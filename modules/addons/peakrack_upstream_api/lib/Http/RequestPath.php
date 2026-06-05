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

final class RequestPath
{
    private const ENTRY_PATH = '/modules/addons/peakrack_upstream_api/api/v1';

    public static function route(string $publicPath, string $pathInfo): string
    {
        if ($pathInfo !== '') {
            return self::normalize($pathInfo);
        }

        $position = strrpos($publicPath, self::ENTRY_PATH);
        if ($position === false) {
            return '/';
        }

        $route = substr($publicPath, $position + strlen(self::ENTRY_PATH));
        if (str_starts_with($route, '/index.php')) {
            $route = substr($route, strlen('/index.php'));
        }

        return self::normalize($route);
    }

    private static function normalize(string $route): string
    {
        if ($route === '') {
            return '/';
        }

        return str_starts_with($route, '/') ? $route : '/' . $route;
    }
}
