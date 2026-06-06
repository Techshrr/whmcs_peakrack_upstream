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

use InvalidArgumentException;

final class Mapper
{
    private const API_PATH = '/modules/addons/peakrack_upstream_api/api/v1';

    public static function createPayload(array $params): array
    {
        return [
            'local_service_id' => Validator::positiveInt($params['serviceid'] ?? null, 'local service ID'),
            'product_id' => Validator::positiveInt(self::option($params, 1), 'upstream product ID'),
            'billing_cycle' => Validator::billingCycleOption(
                self::option($params, 2),
                (string) ($params['billingcycle'] ?? '')
            ),
            'hostname' => Validator::hostname((string) ($params['domain'] ?? '')),
            'password' => Validator::nonEmptyString($params['password'] ?? null, 'service password'),
        ] + self::optionalProductMappings($params);
    }

    public static function changePackagePayload(array $params): array
    {
        return [
            'product_id' => Validator::positiveInt(self::option($params, 1), 'upstream product ID'),
            'billing_cycle' => Validator::billingCycleOption(
                self::option($params, 2),
                (string) ($params['billingcycle'] ?? '')
            ),
        ] + self::optionalProductMappings($params);
    }

    public static function terminateMode(array $params): string
    {
        return Validator::terminateMode(self::option($params, 5));
    }

    public static function timeout(array $params): int
    {
        return Validator::timeout(self::option($params, 6));
    }

    public static function apiBaseUrl(array $params): string
    {
        if (!in_array($params['serversecure'] ?? null, [true, 1, '1', 'on', 'yes', 'true'], true)) {
            throw new InvalidArgumentException('The upstream API server must use SSL.');
        }

        $host = Validator::serverHost((string) ($params['serverhostname'] ?? ''));
        $port = Validator::port($params['serverport'] ?? null);
        $basePath = Validator::basePath((string) ($params['serveraccesshash'] ?? ''));
        $urlHost = str_contains($host, ':') ? '[' . $host . ']' : $host;

        return 'https://' . $urlHost . ($port === 443 ? '' : ':' . $port) . $basePath . self::API_PATH;
    }

    private static function optionalProductMappings(array $params): array
    {
        $payload = [];
        $location = self::option($params, 3);
        if ($location !== '') {
            $payload['location'] = Validator::identifier($location, 'location');
        }
        $osTemplate = self::option($params, 4);
        if ($osTemplate !== '') {
            $payload['os_template'] = Validator::identifier($osTemplate, 'OS template');
        }

        return $payload;
    }

    private static function option(array $params, int $position): string
    {
        $value = $params['configoption' . $position] ?? '';
        if (!is_scalar($value) && $value !== null) {
            throw new InvalidArgumentException("Product configuration option {$position} is invalid.");
        }

        return (string) $value;
    }
}
