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

use Closure;
use PeakRack\UpstreamApi\Contracts\ProvisioningGateway as ProvisioningGatewayContract;
use PeakRack\UpstreamApi\Domain\ApiError;
use PeakRack\UpstreamApi\Domain\ServiceStatus;
use PeakRack\UpstreamApi\Domain\ValidationException;
use Throwable;

final class ProvisioningGateway implements ProvisioningGatewayContract
{
    private const SENSITIVE_FIELD_NAMES = [
        'api key',
        'api secret',
        'authorization',
        'password',
        'rootpw',
        'secret',
        'signature',
        'sso',
        'token',
    ];

    private readonly Closure $serviceReader;
    private readonly Closure $ssoResolver;

    public function __construct(
        private readonly LocalApiClient $api,
        callable $serviceReader,
        callable $ssoResolver
    ) {
        $this->serviceReader = Closure::fromCallable($serviceReader);
        $this->ssoResolver = Closure::fromCallable($ssoResolver);
    }

    public function create(int $serviceId, string $operationId): array
    {
        return $this->moduleCall('ModuleCreate', $serviceId, [], $operationId);
    }

    public function suspend(int $serviceId, string $reason, string $operationId): array
    {
        return $this->moduleCall('ModuleSuspend', $serviceId, ['suspendreason' => $reason], $operationId);
    }

    public function unsuspend(int $serviceId, string $operationId): array
    {
        return $this->moduleCall('ModuleUnsuspend', $serviceId, [], $operationId);
    }

    public function terminate(int $serviceId, string $operationId): array
    {
        return $this->moduleCall('ModuleTerminate', $serviceId, [], $operationId);
    }

    public function readDelivery(int $serviceId, array $mappings): array
    {
        try {
            $service = ($this->serviceReader)($serviceId);
        } catch (Throwable) {
            throw $this->provisioningFailure();
        }

        if (!is_array($service)) {
            throw $this->provisioningFailure();
        }

        $delivery = [
            'service_status' => ServiceStatus::normalize((string) ($service['status'] ?? '')),
        ];

        foreach (['primary_ip', 'panel_url'] as $output) {
            $mapping = $mappings[$output] ?? null;
            if (!is_array($mapping)) {
                continue;
            }

            $value = $this->mappedValue($service, $mapping);
            if ($output === 'primary_ip' && filter_var($value, FILTER_VALIDATE_IP) !== false) {
                $delivery[$output] = $value;
            }
            if ($output === 'panel_url' && $this->isHttpsUrl($value)) {
                $delivery[$output] = $value;
            }
        }

        return $delivery;
    }

    public function sso(int $serviceId, array $allowedHosts, string $operationId): string
    {
        try {
            $response = OperationContext::run(
                $operationId,
                fn () => ($this->ssoResolver)($serviceId)
            );
        } catch (Throwable) {
            throw $this->provisioningFailure();
        }

        $url = is_array($response) && ($response['success'] ?? false) === true
            ? (string) ($response['redirectTo'] ?? '')
            : '';
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $normalizedHosts = array_map(
            static fn (mixed $allowed): string => strtolower(trim((string) $allowed)),
            $allowedHosts
        );

        if (!$this->isHttpsUrl($url) || $host === '' || !in_array($host, $normalizedHosts, true)) {
            throw $this->provisioningFailure();
        }

        return $url;
    }

    private function moduleCall(
        string $command,
        int $serviceId,
        array $parameters,
        string $operationId
    ): array {
        return OperationContext::run(
            $operationId,
            fn (): array => $this->api->call($command, ['serviceid' => $serviceId] + $parameters)
        );
    }

    private function mappedValue(array $service, array $mapping): string
    {
        $source = (string) ($mapping['source'] ?? '');
        if ($source === 'dedicated_ip') {
            return (string) ($service['dedicated_ip'] ?? '');
        }

        if ($source !== 'custom_field') {
            return '';
        }

        $id = (int) ($mapping['id'] ?? 0);
        $field = $service['custom_fields'][$id] ?? null;
        if (!is_array($field) || $this->isSensitiveFieldName((string) ($field['name'] ?? ''))) {
            return '';
        }

        return (string) ($field['value'] ?? '');
    }

    private function isSensitiveFieldName(string $name): bool
    {
        $normalized = strtolower($name);
        foreach (self::SENSITIVE_FIELD_NAMES as $sensitive) {
            if (str_contains($normalized, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    private function isHttpsUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https'
            && (string) parse_url($url, PHP_URL_HOST) !== '';
    }

    private function provisioningFailure(): ValidationException
    {
        return new ValidationException(
            ApiError::PROVISIONING_FAILED,
            'The upstream Provisioning Module operation failed.',
            422
        );
    }
}
