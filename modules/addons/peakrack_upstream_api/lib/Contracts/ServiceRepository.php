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

use PeakRack\UpstreamApi\Domain\ServiceRecord;

interface ServiceRepository
{
    public function findByLocalId(int $apiKeyId, int $localServiceId): ?ServiceRecord;

    public function findByUpstreamServiceId(int $upstreamServiceId): ?ServiceRecord;

    public function save(ServiceRecord $service): void;

    public function isManagedUpstreamService(int $upstreamServiceId): bool;
}
