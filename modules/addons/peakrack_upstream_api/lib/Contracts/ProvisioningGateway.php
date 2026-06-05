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

interface ProvisioningGateway
{
    public function create(int $serviceId, string $operationId): array;

    public function suspend(int $serviceId, string $reason, string $operationId): array;

    public function unsuspend(int $serviceId, string $operationId): array;

    public function terminate(int $serviceId, string $operationId): array;

    public function readDelivery(int $serviceId, array $mappings): array;

    public function sso(int $serviceId, array $allowedHosts, string $operationId): string;
}
