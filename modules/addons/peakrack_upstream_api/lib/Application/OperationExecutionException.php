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

use RuntimeException;

final class OperationExecutionException extends RuntimeException
{
    private function __construct(
        private readonly string $apiErrorCode,
        private readonly bool $unknownOutcome
    ) {
        parent::__construct('The operation could not be completed safely.');
    }

    public static function retryable(string $apiErrorCode): self
    {
        return new self($apiErrorCode, false);
    }

    public static function unknownOutcome(string $apiErrorCode): self
    {
        return new self($apiErrorCode, true);
    }

    public function apiErrorCode(): string
    {
        return $this->apiErrorCode;
    }

    public function isUnknownOutcome(): bool
    {
        return $this->unknownOutcome;
    }
}
