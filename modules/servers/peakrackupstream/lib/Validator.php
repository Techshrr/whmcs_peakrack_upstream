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

use DateTimeImmutable;
use InvalidArgumentException;

final class Validator
{
    private const BILLING_CYCLES = [
        'monthly',
        'quarterly',
        'semiannually',
        'annually',
        'biennially',
        'triennially',
    ];

    public static function hostname(string $hostname): string
    {
        if (
            $hostname === ''
            || $hostname !== trim($hostname)
            || strlen($hostname) > 253
            || preg_match('/[\x00-\x20\x7f]/', $hostname) === 1
            || filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
        ) {
            throw new InvalidArgumentException('The service hostname is invalid.');
        }

        return strtolower($hostname);
    }

    public static function serverHost(string $hostname): string
    {
        if ($hostname !== trim($hostname) || preg_match('/[\x00-\x20\x7f]/', $hostname) === 1) {
            throw new InvalidArgumentException('The upstream API hostname is invalid.');
        }

        $candidate = $hostname;
        if (str_starts_with($candidate, '[') && str_ends_with($candidate, ']')) {
            $candidate = substr($candidate, 1, -1);
        }
        if (
            $candidate === ''
            || (
                filter_var($candidate, FILTER_VALIDATE_IP) === false
                && filter_var($candidate, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            )
        ) {
            throw new InvalidArgumentException('The upstream API hostname is invalid.');
        }

        return strtolower($candidate);
    }

    public static function positiveInt(mixed $value, string $name): int
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new InvalidArgumentException("The {$name} must be a positive integer.");
        }

        $integer = (int) $value;
        if ($integer < 1) {
            throw new InvalidArgumentException("The {$name} must be a positive integer.");
        }

        return $integer;
    }

    public static function operationId(string $value): string
    {
        if (
            preg_match(
                '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89aAbB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/',
                $value
            ) !== 1
        ) {
            throw new InvalidArgumentException('The upstream operation ID is invalid.');
        }

        return strtolower($value);
    }

    public static function billingCycle(string $cycle): string
    {
        $normalized = strtolower(str_replace(['-', '_', ' '], '', trim($cycle)));
        if (!in_array($normalized, self::BILLING_CYCLES, true)) {
            throw new InvalidArgumentException('The billing cycle is invalid.');
        }

        return $normalized;
    }

    public static function billingCycleOption(string $configured, string $serviceCycle): string
    {
        return strtolower(trim($configured)) === 'auto' || trim($configured) === ''
            ? self::billingCycle($serviceCycle)
            : self::billingCycle($configured);
    }

    public static function identifier(string $value, string $name): string
    {
        if (
            $value !== trim($value)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $value) !== 1
        ) {
            throw new InvalidArgumentException("The {$name} identifier is invalid.");
        }

        return $value;
    }

    public static function terminateMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        if ($mode === '') {
            return 'cancel_only';
        }
        if (!in_array($mode, ['cancel_only', 'destroy'], true)) {
            throw new InvalidArgumentException('The termination mode is invalid.');
        }

        return $mode;
    }

    public static function date(string $value, string $name): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (
            $date === false
            || $date->format('Y-m-d') !== $value
            || (
                is_array($errors)
                && ((int) $errors['warning_count'] > 0 || (int) $errors['error_count'] > 0)
            )
        ) {
            throw new InvalidArgumentException("The {$name} is invalid.");
        }

        return $value;
    }

    public static function basePath(string $path): string
    {
        if (
            $path !== trim($path)
            || preg_match('/[\x00-\x20\x7f?#\\\\%]/', $path) === 1
            || str_contains($path, '://')
            || str_starts_with($path, '//')
        ) {
            throw new InvalidArgumentException('The upstream WHMCS base path is invalid.');
        }

        if ($path === '' || $path === '/') {
            return '';
        }

        $normalized = '/' . ltrim($path, '/');
        $normalized = (string) preg_replace('#/+#', '/', $normalized);
        $normalized = rtrim($normalized, '/');
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('The upstream WHMCS base path contains traversal.');
            }
        }
        if (preg_match("#^/[A-Za-z0-9._~!$&'()*+,;=:@/-]+$#", $normalized) !== 1) {
            throw new InvalidArgumentException('The upstream WHMCS base path is invalid.');
        }

        return $normalized;
    }

    public static function timeout(mixed $value): int
    {
        if ($value === null || $value === '') {
            return Config::DEFAULT_TIMEOUT;
        }
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new InvalidArgumentException('The request timeout is invalid.');
        }

        return max(Config::MINIMUM_TIMEOUT, min(Config::MAXIMUM_TIMEOUT, (int) $value));
    }

    public static function port(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 443;
        }

        $port = self::positiveInt($value, 'upstream API port');
        if ($port > 65535) {
            throw new InvalidArgumentException('The upstream API port is invalid.');
        }

        return $port;
    }

    public static function nonEmptyString(mixed $value, string $name, int $maximumLength = 1024): string
    {
        if (
            !is_string($value)
            || $value === ''
            || strlen($value) > $maximumLength
            || str_contains($value, "\0")
        ) {
            throw new InvalidArgumentException("The {$name} is invalid.");
        }

        return $value;
    }
}
