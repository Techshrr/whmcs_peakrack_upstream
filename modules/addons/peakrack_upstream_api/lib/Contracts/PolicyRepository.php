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

use PeakRack\UpstreamApi\Domain\ProductPolicy;

interface PolicyRepository
{
    public function findForProduct(int $apiKeyId, int $productId): ?ProductPolicy;

    public function catalogForApiKey(int $apiKeyId): array;

    public function save(array $attributes): int;

    public function delete(int $id): void;
}
