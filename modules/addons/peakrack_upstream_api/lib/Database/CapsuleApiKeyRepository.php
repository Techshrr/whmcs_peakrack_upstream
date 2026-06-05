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

use PeakRack\UpstreamApi\Contracts\ApiKeyRepository;
use WHMCS\Database\Capsule;

final class CapsuleApiKeyRepository implements ApiKeyRepository
{
    public function find(int $id): ?array
    {
        return $this->rowToArray(Capsule::table(Schema::API_KEYS)->where('id', $id)->first());
    }

    public function findEnabledByPublicKey(string $publicKey): ?array
    {
        return $this->rowToArray(
            Capsule::table(Schema::API_KEYS)
                ->where('public_key', $publicKey)
                ->where('enabled', 1)
                ->first()
        );
    }

    public function all(): array
    {
        return array_map(
            static fn (object $row): array => (array) $row,
            Capsule::table(Schema::API_KEYS)->orderBy('id')->get()->all()
        );
    }

    public function create(array $attributes): int
    {
        $now = time();
        $attributes['created_at'] = $attributes['created_at'] ?? $now;
        $attributes['updated_at'] = $attributes['updated_at'] ?? $now;

        return (int) Capsule::table(Schema::API_KEYS)->insertGetId($attributes);
    }

    public function updateEncryptedSecret(int $id, string $encryptedSecret): void
    {
        Capsule::table(Schema::API_KEYS)->where('id', $id)->update([
            'encrypted_secret' => $encryptedSecret,
            'updated_at' => time(),
        ]);
    }

    public function updateUsage(int $id, string $sourceIp, int $usedAt): void
    {
        Capsule::table(Schema::API_KEYS)->where('id', $id)->update([
            'last_used_at' => $usedAt,
            'last_used_ip' => $sourceIp,
            'updated_at' => $usedAt,
        ]);
    }

    public function hasServices(int $id): bool
    {
        return Capsule::table(Schema::SERVICES)->where('api_key_id', $id)->exists();
    }

    public function delete(int $id): void
    {
        Capsule::table(Schema::API_KEYS)->where('id', $id)->delete();
    }

    private function rowToArray(?object $row): ?array
    {
        return $row === null ? null : (array) $row;
    }
}
