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

namespace PeakRack\UpstreamApi\Admin;

use Closure;
use PeakRack\UpstreamApi\Support\Compatibility;
use Throwable;

final class ActivationService
{
    private readonly Closure $installSchema;
    private readonly ?Closure $preconditions;

    public function __construct(callable $installSchema, ?callable $preconditions = null)
    {
        $this->installSchema = Closure::fromCallable($installSchema);
        $this->preconditions = $preconditions === null ? null : Closure::fromCallable($preconditions);
    }

    public function activate(string $phpVersion, string $whmcsVersion): array
    {
        $errors = Compatibility::errors($phpVersion, $whmcsVersion);
        if ($errors !== []) {
            return [
                'status' => 'error',
                'description' => implode(' ', $errors),
            ];
        }

        $preconditionErrors = [];

        try {
            ($this->installSchema)();

            if ($this->preconditions !== null) {
                $reported = ($this->preconditions)();
                if (is_array($reported)) {
                    $preconditionErrors = array_map('strval', $reported);
                }
            }
        } catch (Throwable) {
            return [
                'status' => 'error',
                'description' => 'The PeakRack upstream database schema could not be installed.',
            ];
        }

        if ($preconditionErrors !== []) {
            return [
                'status' => 'success',
                'description' => 'PeakRack Upstream API activated. System Health requires attention: '
                    . implode(' ', $preconditionErrors),
            ];
        }

        return [
            'status' => 'success',
            'description' => 'PeakRack Upstream API activated.',
        ];
    }

    public function deactivate(): array
    {
        return [
            'status' => 'success',
            'description' => 'PeakRack Upstream API deactivated. Addon data was preserved.',
        ];
    }
}
