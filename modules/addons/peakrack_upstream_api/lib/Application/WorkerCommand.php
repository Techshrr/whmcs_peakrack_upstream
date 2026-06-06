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

use Throwable;

final class WorkerCommand
{
    public function run(
        string $sapi,
        callable $execute,
        callable $stdout,
        callable $stderr
    ): int {
        if ($sapi !== 'cli') {
            $stderr("PeakRack upstream worker is CLI-only.\n");
            return 1;
        }

        try {
            $summary = $execute();
            $stdout((string) json_encode(
                is_array($summary) ? $summary : [],
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ) . "\n");
            return 0;
        } catch (Throwable) {
            $stderr("PeakRack upstream worker failed during bootstrap or execution.\n");
            return 1;
        }
    }
}
