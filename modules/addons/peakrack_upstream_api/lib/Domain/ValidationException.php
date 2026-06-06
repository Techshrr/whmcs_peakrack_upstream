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

namespace PeakRack\UpstreamApi\Domain;

use RuntimeException;

final class ValidationException extends RuntimeException
{
    public function __construct(
        private readonly string $apiErrorCode,
        string $message,
        private readonly int $httpStatus = 422
    ) {
        parent::__construct($message);
    }

    public function apiErrorCode(): string
    {
        return $this->apiErrorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}
