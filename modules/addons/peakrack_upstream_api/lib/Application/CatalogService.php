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

namespace PeakRack\UpstreamApi\Application;

use PeakRack\UpstreamApi\Contracts\PolicyRepository;

final class CatalogService
{
    public function __construct(private readonly PolicyRepository $policies)
    {
    }

    public function forApiKey(int $apiKeyId): array
    {
        return [
            'api_key_id' => $apiKeyId,
            'products' => $this->policies->catalogForApiKey($apiKeyId),
        ];
    }
}
