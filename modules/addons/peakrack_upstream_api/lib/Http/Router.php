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

namespace PeakRack\UpstreamApi\Http;

final class Router
{
    private const SERVICE_ID = '([1-9][0-9]*)';
    private const OPERATION_ID = '([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89aAbB][0-9a-fA-F]{3}-[0-9a-fA-F]{12})';

    private const ROUTES = [
        ['GET', '#^/health$#', 'health', []],
        ['GET', '#^/catalog$#', 'catalog', []],
        ['POST', '#^/services$#', 'service.create', []],
        ['GET', '#^/services/' . self::SERVICE_ID . '$#', 'service.show', ['local_service_id']],
        ['POST', '#^/services/' . self::SERVICE_ID . '/suspend$#', 'service.suspend', ['local_service_id']],
        ['POST', '#^/services/' . self::SERVICE_ID . '/unsuspend$#', 'service.unsuspend', ['local_service_id']],
        ['POST', '#^/services/' . self::SERVICE_ID . '/terminate$#', 'service.terminate', ['local_service_id']],
        ['POST', '#^/services/' . self::SERVICE_ID . '/renew$#', 'service.renew', ['local_service_id']],
        ['POST', '#^/services/' . self::SERVICE_ID . '/change-package$#', 'service.change_package', ['local_service_id']],
        ['POST', '#^/services/' . self::SERVICE_ID . '/sso$#', 'service.sso', ['local_service_id']],
        ['GET', '#^/operations/' . self::OPERATION_ID . '$#', 'operation.show', ['operation_id']],
    ];

    public function match(Request $request): ?array
    {
        foreach (self::ROUTES as [$method, $pattern, $name, $parameterNames]) {
            if ($request->method() !== $method) {
                continue;
            }

            if (preg_match($pattern, $request->path(), $matches) !== 1) {
                continue;
            }

            array_shift($matches);
            $parameters = [];
            foreach ($parameterNames as $index => $parameterName) {
                $value = $matches[$index] ?? '';
                $parameters[$parameterName] = $parameterName === 'local_service_id'
                    ? (int) $value
                    : strtolower($value);
            }

            return [
                'name' => $name,
                'parameters' => $parameters,
            ];
        }

        return null;
    }
}
