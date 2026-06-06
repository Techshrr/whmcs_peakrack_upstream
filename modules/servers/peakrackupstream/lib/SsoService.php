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

use Closure;
use PeakRack\Upstream\Api\ApiClientInterface;
use RuntimeException;
use Throwable;

final class SsoService
{
    private readonly Closure $randomGenerator;

    public function __construct(
        private readonly ApiClientInterface $api,
        ?callable $randomGenerator = null
    ) {
        $this->randomGenerator = $randomGenerator === null
            ? static fn (): string => bin2hex(random_bytes(16))
            : Closure::fromCallable($randomGenerator);
    }

    public function request(int $localServiceId): array
    {
        try {
            $serviceId = Validator::positiveInt($localServiceId, 'local service ID');
            $random = (string) ($this->randomGenerator)();
            if (preg_match('/^[A-Za-z0-9._-]{1,64}$/', $random) !== 1) {
                throw new RuntimeException('Unable to generate an SSO request key.');
            }

            $response = $this->api->getSsoUrl(
                $serviceId,
                'service:sso:' . $serviceId . ':' . $random
            );
            $url = is_array($response['data'] ?? null)
                ? (string) ($response['data']['sso_url'] ?? '')
                : '';

            if (
                ($response['success'] ?? false) !== true
                || ($response['status'] ?? '') !== 'completed'
                || !$this->isHttpsUrl($url)
            ) {
                throw new RuntimeException('The upstream SSO response is invalid.');
            }

            return [
                'success' => true,
                'redirectTo' => $url,
            ];
        } catch (Throwable) {
            return [
                'success' => false,
                'errorMsg' => 'The upstream control panel is temporarily unavailable.',
            ];
        }
    }

    private function isHttpsUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https'
            && (string) parse_url($url, PHP_URL_HOST) !== '';
    }
}
