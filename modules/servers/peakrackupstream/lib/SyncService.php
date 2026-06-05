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

namespace PeakRack\Upstream;

use Closure;
use RuntimeException;
use Throwable;

final class SyncService
{
    private const LOCAL_STATUS_MAP = [
        'active' => 'Active',
        'suspended' => 'Suspended',
        'terminated' => 'Terminated',
    ];

    private const REMOTE_STATUSES = [
        'pending',
        'provisioning',
        'active',
        'suspended',
        'cancellation_pending',
        'terminated',
        'failed',
        'manual_review',
        'unknown',
    ];

    private readonly Closure $serviceReader;
    private readonly Closure $remoteReader;
    private readonly Closure $resultApplier;
    private readonly Closure $statusUpdater;

    public function __construct(
        callable $serviceReader,
        callable $remoteReader,
        callable $resultApplier,
        callable $statusUpdater
    ) {
        $this->serviceReader = Closure::fromCallable($serviceReader);
        $this->remoteReader = Closure::fromCallable($remoteReader);
        $this->resultApplier = Closure::fromCallable($resultApplier);
        $this->statusUpdater = Closure::fromCallable($statusUpdater);
    }

    public function run(int $limit): array
    {
        $services = ($this->serviceReader)(max(1, min(500, $limit)));
        if (!is_array($services)) {
            throw new RuntimeException('The downstream service reader returned invalid data.');
        }

        usort($services, static function (mixed $left, mixed $right): int {
            $left = is_array($left) ? $left : [];
            $right = is_array($right) ? $right : [];
            $leftPending = strcasecmp((string) ($left['status'] ?? ''), 'Pending') === 0 ? 0 : 1;
            $rightPending = strcasecmp((string) ($right['status'] ?? ''), 'Pending') === 0 ? 0 : 1;

            return [$leftPending, (int) ($left['id'] ?? 0)] <=> [$rightPending, (int) ($right['id'] ?? 0)];
        });

        $summary = [
            'scanned' => count($services),
            'synced' => 0,
            'failed' => 0,
            'status_updated' => 0,
        ];

        foreach ($services as $service) {
            try {
                if (!is_array($service)) {
                    throw new RuntimeException('The downstream service record is invalid.');
                }
                Validator::positiveInt($service['id'] ?? null, 'local service ID');
                $localStatus = (string) ($service['status'] ?? '');
                $response = ($this->remoteReader)($service);
                $remoteStatus = $this->remoteStatus($response);

                ($this->resultApplier)($service, $response);
                $targetStatus = self::LOCAL_STATUS_MAP[$remoteStatus] ?? null;
                if ($targetStatus !== null && strcasecmp($localStatus, $targetStatus) !== 0) {
                    ($this->statusUpdater)($service, $targetStatus);
                    $summary['status_updated']++;
                }
                $summary['synced']++;
            } catch (Throwable) {
                $summary['failed']++;
            }
        }

        return $summary;
    }

    private function remoteStatus(mixed $response): string
    {
        if (
            !is_array($response)
            || ($response['success'] ?? false) !== true
            || ($response['status'] ?? '') !== 'completed'
            || !is_array($response['data'] ?? null)
        ) {
            throw new RuntimeException('The upstream synchronization response is invalid.');
        }

        $status = strtolower(trim((string) ($response['data']['service_status'] ?? '')));
        if (!in_array($status, self::REMOTE_STATUSES, true)) {
            throw new RuntimeException('The upstream service status is invalid.');
        }

        return $status;
    }
}
