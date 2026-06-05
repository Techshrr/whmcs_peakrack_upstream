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

use PeakRack\UpstreamApi\Application\WorkerCommand;
use PeakRack\UpstreamApi\Bootstrap;
use PeakRack\UpstreamApi\Database\CapsuleLockRepository;
use PeakRack\UpstreamApi\Whmcs\WorkerFactory;
use WHMCS\Database\Capsule;

require_once dirname(__DIR__) . '/lib/Bootstrap.php';
Bootstrap::register();

$command = new WorkerCommand();
$exitCode = $command->run(
    PHP_SAPI,
    static function (): array {
        $root = dirname(__DIR__, 4);
        require_once $root . '/init.php';

        $now = time();
        $owner = (gethostname() ?: 'worker') . ':' . getmypid();
        $locks = new CapsuleLockRepository();
        if (!$locks->acquire('worker:global', $owner, $now + 55)) {
            return ['status' => 'skipped', 'reason' => 'worker_lock_busy'];
        }

        try {
            $batchSize = (int) (Capsule::table('tbladdonmodules')
                ->where('module', 'peakrack_upstream_api')
                ->where('setting', 'worker_batch_size')
                ->value('value') ?? 25);
            $batchSize = max(1, min(100, $batchSize));
            $summary = WorkerFactory::create()->run($owner, $batchSize);

            $setting = Capsule::table('tbladdonmodules')
                ->where('module', 'peakrack_upstream_api')
                ->where('setting', 'worker_last_run');
            if ($setting->exists()) {
                $setting->update(['value' => (string) time()]);
            } else {
                Capsule::table('tbladdonmodules')->insert([
                    'module' => 'peakrack_upstream_api',
                    'setting' => 'worker_last_run',
                    'value' => (string) time(),
                ]);
            }

            return ['status' => 'completed'] + $summary;
        } finally {
            $locks->release('worker:global', $owner);
        }
    },
    static fn (string $message): int => fwrite(STDOUT, $message),
    static fn (string $message): int => fwrite(STDERR, $message)
);

exit($exitCode);
