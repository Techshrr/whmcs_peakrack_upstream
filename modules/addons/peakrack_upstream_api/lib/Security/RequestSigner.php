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

final class RequestSigner
{
    public static function canonicalize(
        string $method,
        string $path,
        array $query,
        string $rawBody,
        int $timestamp,
        string $nonce
    ): string {
        return implode("\n", [
            strtoupper($method),
            $path,
            self::canonicalQuery($query),
            hash('sha256', $rawBody),
            (string) $timestamp,
            $nonce,
        ]);
    }

    public static function sign(
        string $secret,
        string $method,
        string $path,
        array $query,
        string $rawBody,
        int $timestamp,
        string $nonce
    ): string {
        return hash_hmac(
            'sha256',
            self::canonicalize($method, $path, $query, $rawBody, $timestamp, $nonce),
            $secret
        );
    }

    private static function canonicalQuery(array $query): string
    {
        $pairs = [];

        foreach ($query as $key => $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                $pairs[] = [
                    rawurlencode((string) $key),
                    rawurlencode((string) $item),
                ];
            }
        }

        usort($pairs, static function (array $left, array $right): int {
            return [$left[0], $left[1]] <=> [$right[0], $right[1]];
        });

        return implode('&', array_map(
            static fn (array $pair): string => $pair[0] . '=' . $pair[1],
            $pairs
        ));
    }
}
