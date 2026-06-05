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

namespace PeakRack\UpstreamApi\Security;

use PeakRack\UpstreamApi\Contracts\ApiKeyRepository;

final class RateLimiter
{
    private const WINDOW_SECONDS = 60;

    public function __construct(private readonly ApiKeyRepository $apiKeys)
    {
    }

    public function allow(int $apiKeyId, int $now): bool
    {
        return $this->apiKeys->consumeRateLimit($apiKeyId, $now, self::WINDOW_SECONDS);
    }
}
