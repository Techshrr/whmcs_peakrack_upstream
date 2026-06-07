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

namespace PeakRack\UpstreamApi\Database;

use PeakRack\UpstreamApi\Contracts\SecretResetRepository;
use WHMCS\Database\Capsule;

final class CapsuleSecretResetRepository implements SecretResetRepository
{
    public function record(array $event): void
    {
        Capsule::table(Schema::SECRET_RESET_EVENTS)->insert($event);
    }

    public function countClientResetsInMonth(int $apiKeyId, int $clientId, int $monthStart, int $monthEnd): int
    {
        return (int) Capsule::table(Schema::SECRET_RESET_EVENTS)
            ->where('api_key_id', $apiKeyId)
            ->where('client_id', $clientId)
            ->where('actor_type', 'client')
            ->where('reset_at', '>=', $monthStart)
            ->where('reset_at', '<', $monthEnd)
            ->count();
    }

    public function latestClientResetAt(int $apiKeyId, int $clientId): ?int
    {
        $value = Capsule::table(Schema::SECRET_RESET_EVENTS)
            ->where('api_key_id', $apiKeyId)
            ->where('client_id', $clientId)
            ->where('actor_type', 'client')
            ->orderByDesc('reset_at')
            ->value('reset_at');

        return $value === null ? null : (int) $value;
    }
}
