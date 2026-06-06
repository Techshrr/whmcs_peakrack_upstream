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

namespace PeakRack\UpstreamApi\Security;

final class Redactor
{
    private const MASK = '[REDACTED]';

    private const SENSITIVE_KEYS = [
        'api_key',
        'api_secret',
        'authorization',
        'password',
        'rootpw',
        'secret',
        'token',
        'access_token',
        'refresh_token',
        'sso_url',
        'signature',
    ];

    public static function redact(mixed $value): mixed
    {
        if (is_array($value)) {
            $redacted = [];
            foreach ($value as $key => $item) {
                $redacted[$key] = self::isSensitiveKey((string) $key)
                    ? self::MASK
                    : self::redact($item);
            }

            return $redacted;
        }

        if (is_object($value)) {
            return self::redact(get_object_vars($value));
        }

        if (is_string($value) && self::isJsonObjectOrArray($value)) {
            $decoded = json_decode($value, true);
            return json_encode(
                self::redact($decoded),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        }

        return $value;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);
        foreach (self::SENSITIVE_KEYS as $sensitiveKey) {
            if (str_contains($normalized, $sensitiveKey)) {
                return true;
            }
        }

        return false;
    }

    private static function isJsonObjectOrArray(string $value): bool
    {
        $trimmed = ltrim($value);
        if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) {
            return false;
        }

        json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE;
    }
}
