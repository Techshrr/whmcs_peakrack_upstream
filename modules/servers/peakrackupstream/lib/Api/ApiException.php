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

use RuntimeException;

final class ApiException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $errorCode = 'UPSTREAM_API_ERROR',
        private readonly int $httpStatus = 0,
        private readonly ?string $operationId = null,
        private readonly ?string $operationStatus = null
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function operationId(): ?string
    {
        return $this->operationId;
    }

    public function operationStatus(): ?string
    {
        return $this->operationStatus;
    }
}
