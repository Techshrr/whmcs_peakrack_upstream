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

namespace PeakRack\Upstream\Api;

interface ApiClientInterface
{
    public function health(): array;

    public function catalog(): array;

    public function createService(array $payload, string $idempotencyKey): array;

    public function getService(int $localServiceId): array;

    public function suspendService(int $localServiceId, string $idempotencyKey): array;

    public function unsuspendService(int $localServiceId, string $idempotencyKey): array;

    public function terminateService(int $localServiceId, string $mode, string $idempotencyKey): array;

    public function renewService(int $localServiceId, string $renewalBoundary, string $idempotencyKey): array;

    public function changePackage(int $localServiceId, array $payload, string $idempotencyKey): array;

    public function getSsoUrl(int $localServiceId, string $idempotencyKey): array;
}
