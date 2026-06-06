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

use PeakRack\UpstreamApi\Contracts\NonceRepository;
use Throwable;
use WHMCS\Database\Capsule;

final class CapsuleNonceRepository implements NonceRepository
{
    public function claim(int $apiKeyId, string $nonce, int $expiresAt): bool
    {
        try {
            Capsule::table(Schema::NONCES)->insert([
                'api_key_id' => $apiKeyId,
                'nonce' => $nonce,
                'expires_at' => $expiresAt,
                'created_at' => time(),
            ]);

            return true;
        } catch (Throwable $exception) {
            $claimed = Capsule::table(Schema::NONCES)
                ->where('api_key_id', $apiKeyId)
                ->where('nonce', $nonce)
                ->exists();

            if (!$claimed) {
                throw $exception;
            }

            return false;
        }
    }

    public function purgeExpired(int $now): int
    {
        return Capsule::table(Schema::NONCES)
            ->where('expires_at', '<=', $now)
            ->delete();
    }
}
