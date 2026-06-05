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

namespace PeakRack\UpstreamApi\Whmcs;

use InvalidArgumentException;

final class OperationContext
{
    private static ?string $operationId = null;

    public static function run(string $operationId, callable $callback): mixed
    {
        if ($operationId === '') {
            throw new InvalidArgumentException('The operation ID cannot be empty.');
        }

        $previous = self::$operationId;
        self::$operationId = $operationId;

        try {
            return $callback();
        } finally {
            self::$operationId = $previous;
        }
    }

    public static function isActive(): bool
    {
        return self::$operationId !== null;
    }

    public static function operationId(): ?string
    {
        return self::$operationId;
    }

    public static function guardManagedService(bool $managed): array
    {
        return $managed && !self::isActive() ? ['abortcmd' => true] : [];
    }
}
