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

namespace PeakRack\UpstreamApi\Support;

use PeakRack\UpstreamApi\Config;

final class Compatibility
{
    public static function errors(string $phpVersion, string $whmcsVersion): array
    {
        $errors = [];
        if (version_compare($phpVersion, Config::MINIMUM_PHP_VERSION, '<')) {
            $errors[] = 'PHP 8.2 or later is required.';
        }

        if (preg_match('/^9\.0(?:\.|$)/', $whmcsVersion) !== 1) {
            $errors[] = 'WHMCS 9.0.x is required.';
        }

        return $errors;
    }
}
