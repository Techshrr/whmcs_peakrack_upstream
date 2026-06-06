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

namespace PeakRack\Upstream;

use Closure;
use Throwable;

final class Logger
{
    private readonly ?Closure $writer;

    public function __construct(?callable $writer = null)
    {
        $this->writer = $writer === null ? null : Closure::fromCallable($writer);
    }

    public function log(string $action, mixed $request, mixed $response): void
    {
        $safeRequest = Redactor::redact($request);
        $safeResponse = Redactor::redact($response);

        try {
            if ($this->writer !== null) {
                ($this->writer)('peakrackupstream', $action, $safeRequest, $safeResponse, '', []);
                return;
            }

            if (function_exists('logModuleCall')) {
                \logModuleCall('peakrackupstream', $action, $safeRequest, $safeResponse, '', []);
            }
        } catch (Throwable) {
            // Module logging must never break provisioning.
        }
    }
}
