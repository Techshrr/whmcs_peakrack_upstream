<?php
// SPDX-License-Identifier: Apache-2.0

/**
 * PeakRack Upstream WHMCS Integration
 *
 * Official repository:
 * https://github.com/Techshrr/whmcs_peakrack_upstream
 *
 * Copyright 2026 PeakRack.
 * Licensed under the Apache License, Version 2.0.
 */

use PeakRack\Upstream\Bootstrap;
use PeakRack\Upstream\Idempotency;
use PeakRack\Upstream\SyncCommand;
use PeakRack\Upstream\SyncReader;
use PeakRack\Upstream\SyncService;
use WHMCS\Database\Capsule;

require_once dirname(__DIR__) . '/lib/Bootstrap.php';
Bootstrap::register();

$command = new SyncCommand();
$exitCode = $command->run(
    PHP_SAPI,
    static function (): array {
        $root = dirname(__DIR__, 4);
        require_once $root . '/init.php';
        require_once $root . '/includes/modulefunctions.php';
        require_once dirname(__DIR__) . '/peakrackupstream.php';

        if (!function_exists('ModuleBuildParams') || !function_exists('localAPI')) {
            throw new RuntimeException('Required WHMCS module functions are unavailable.');
        }

        $lock = fopen(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'peakrackupstream-sync.lock', 'c+');
        if ($lock === false) {
            throw new RuntimeException('Unable to open the downstream synchronization lock.');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return ['status' => 'skipped', 'reason' => 'sync_lock_busy'];
        }

        try {
            $sync = new SyncService(
                static function (int $limit): array {
                    $rows = Capsule::table('tblhosting')
                        ->join('tblproducts', 'tblproducts.id', '=', 'tblhosting.packageid')
                        ->where('tblproducts.servertype', 'peakrackupstream')
                        ->whereIn('tblhosting.domainstatus', ['Pending', 'Active', 'Suspended'])
                        ->orderByRaw("CASE WHEN tblhosting.domainstatus = 'Pending' THEN 0 ELSE 1 END")
                        ->orderBy('tblhosting.id')
                        ->limit($limit)
                        ->get(['tblhosting.id', 'tblhosting.domainstatus'])
                        ->all();

                    return array_map(
                        static fn (object $row): array => [
                            'id' => (int) $row->id,
                            'status' => (string) $row->domainstatus,
                        ],
                        $rows
                    );
                },
                static function (array $service): array {
                    $params = ModuleBuildParams((int) $service['id']);
                    if (!is_array($params)) {
                        throw new RuntimeException('Unable to build the WHMCS module parameters.');
                    }

                    $properties = peakrackupstream_service_properties($params);
                    return (new SyncReader(
                        peakrackupstream_api_client($params),
                        $properties,
                        new Idempotency($properties)
                    ))->read((int) $service['id']);
                },
                static function (array $service, array $response): void {
                    $params = ModuleBuildParams((int) $service['id']);
                    if (!is_array($params)) {
                        throw new RuntimeException('Unable to build the WHMCS module parameters.');
                    }

                    $properties = peakrackupstream_service_properties($params);
                    $properties->applyResponse($response);
                    $properties->setLastSync(time());
                    $properties->clearError();
                },
                static function (array $service, string $status): void {
                    $response = localAPI('UpdateClientProduct', [
                        'serviceid' => (int) $service['id'],
                        'status' => $status,
                    ]);
                    if (!is_array($response) || ($response['result'] ?? '') !== 'success') {
                        throw new RuntimeException('Unable to update the local WHMCS service status.');
                    }
                }
            );

            return ['status' => 'completed'] + $sync->run(50);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    },
    static fn (string $message): int => fwrite(STDOUT, $message),
    static fn (string $message): int => fwrite(STDERR, $message)
);

exit($exitCode);
