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

namespace PeakRack\UpstreamApi\Contracts;

interface ApiKeyRepository
{
    public function find(int $id): ?array;

    public function findEnabledByPublicKey(string $publicKey): ?array;

    public function all(): array;

    public function create(array $attributes): int;

    public function updateEncryptedSecret(int $id, string $encryptedSecret): void;

    public function updateUsage(int $id, string $sourceIp, int $usedAt): void;

    public function consumeRateLimit(int $id, int $now, int $windowSeconds): bool;

    public function hasServices(int $id): bool;

    public function delete(int $id): void;
}
