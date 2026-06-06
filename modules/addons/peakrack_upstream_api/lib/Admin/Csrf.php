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
use RuntimeException;

final class Csrf
{
    private const SESSION_KEY = 'peakrack_upstream_api_csrf';
    private readonly Closure $tokenGenerator;

    public function __construct(?callable $tokenGenerator = null)
    {
        $this->tokenGenerator = $tokenGenerator === null
            ? static fn (): string => bin2hex(random_bytes(32))
            : Closure::fromCallable($tokenGenerator);
    }

    public function token(array &$session): string
    {
        $token = $session[self::SESSION_KEY] ?? null;
        if (!is_string($token) || preg_match('/^[A-Za-z0-9._-]{8,128}$/', $token) !== 1) {
            $token = (string) ($this->tokenGenerator)();
            if (preg_match('/^[A-Za-z0-9._-]{8,128}$/', $token) !== 1) {
                throw new RuntimeException('Unable to create a valid CSRF token.');
            }
            $session[self::SESSION_KEY] = $token;
        }

        return $token;
    }

    public function assertValid(array $session, mixed $submitted): void
    {
        $expected = $session[self::SESSION_KEY] ?? null;
        if (
            !is_string($expected)
            || !is_string($submitted)
            || !hash_equals($expected, $submitted)
        ) {
            throw new RuntimeException('The administrator CSRF token is invalid.');
        }
    }
}
