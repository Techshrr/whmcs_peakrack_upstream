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

use PeakRack\Upstream\Api\ApiClient;
use PeakRack\Upstream\Api\ApiException;
use PeakRack\Upstream\Bootstrap;
use PeakRack\Upstream\Idempotency;
use PeakRack\Upstream\Mapper;
use PeakRack\Upstream\ModuleService;
use PeakRack\Upstream\ServiceProperties;
use PeakRack\Upstream\SsoService;
use PeakRack\Upstream\Validator;

require_once __DIR__ . '/lib/Bootstrap.php';
Bootstrap::register();

function peakrackupstream_MetaData(): array
{
    return [
        'DisplayName' => 'PeakRack Upstream',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
        'DefaultSSLPort' => 443,
        'ServiceSingleSignOnLabel' => 'Open Control Panel',
    ];
}

function peakrackupstream_ConfigOptions(): array
{
    return [
        'Upstream Product ID' => [
            'Type' => 'text',
            'Size' => '20',
            'Description' => 'Allowed upstream product ID.',
        ],
        'Upstream Billing Cycle' => [
            'Type' => 'dropdown',
            'Options' => [
                'auto' => 'Use local service billing cycle',
                'monthly' => 'Monthly',
                'quarterly' => 'Quarterly',
                'semiannually' => 'Semi-Annually',
                'annually' => 'Annually',
                'biennially' => 'Biennially',
                'triennially' => 'Triennially',
            ],
            'Default' => 'auto',
        ],
        'Upstream Location' => [
            'Type' => 'text',
            'Size' => '32',
            'Description' => 'Optional upstream location identifier.',
        ],
        'Default OS Template' => [
            'Type' => 'text',
            'Size' => '32',
            'Description' => 'Optional upstream OS template identifier.',
        ],
        'Terminate Mode' => [
            'Type' => 'dropdown',
            'Options' => [
                'cancel_only' => 'Cancel at end of billing period',
                'destroy' => 'Destroy immediately',
            ],
            'Default' => 'cancel_only',
        ],
        'Request Timeout' => [
            'Type' => 'text',
            'Size' => '5',
            'Default' => '30',
            'Description' => 'API timeout in seconds. Allowed range: 5 to 120.',
        ],
    ];
}

function peakrackupstream_TestConnection(array $params): array
{
    try {
        $response = peakrackupstream_api_client($params)->health();
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $instanceId = (string) ($data['instance_id'] ?? '');
        $blocking = $data['blocking_errors'] ?? null;

        if (
            ($response['success'] ?? false) !== true
            || ($response['status'] ?? '') !== 'completed'
            || ($data['protocol'] ?? '') !== 'v1'
            || ($data['key_enabled'] ?? false) !== true
            || !is_array($blocking)
            || $blocking !== []
            || !peakrackupstream_is_uuid($instanceId)
        ) {
            return [
                'success' => false,
                'error' => $blocking !== null && is_array($blocking) && $blocking !== []
                    ? 'The upstream installation has blocking policy settings: ' . implode(', ', $blocking)
                    : 'The upstream API health response is not ready for provisioning.',
            ];
        }

        return ['success' => true, 'error' => ''];
    } catch (ApiException $exception) {
        return [
            'success' => false,
            'error' => 'Upstream API error ' . $exception->errorCode() . ': ' . $exception->getMessage(),
        ];
    } catch (InvalidArgumentException $exception) {
        return [
            'success' => false,
            'error' => 'Upstream API configuration error: ' . $exception->getMessage(),
        ];
    } catch (Throwable) {
        return [
            'success' => false,
            'error' => 'The upstream API connection test could not be completed.',
        ];
    }
}

function peakrackupstream_CreateAccount(array $params): string
{
    return peakrackupstream_module_action($params, 'create');
}

function peakrackupstream_SuspendAccount(array $params): string
{
    return peakrackupstream_module_action($params, 'suspend');
}

function peakrackupstream_UnsuspendAccount(array $params): string
{
    return peakrackupstream_module_action($params, 'unsuspend');
}

function peakrackupstream_TerminateAccount(array $params): string
{
    return peakrackupstream_module_action($params, 'terminate');
}

function peakrackupstream_Renew(array $params): string
{
    return peakrackupstream_module_action(peakrackupstream_renewal_params($params), 'renew');
}

function peakrackupstream_ChangePackage(array $params): string
{
    return peakrackupstream_module_action($params, 'changePackage');
}

function peakrackupstream_ClientArea(array $params): array
{
    $data = [
        'status' => (string) ($params['status'] ?? ''),
        'primary_ip' => null,
        'panel_url' => null,
        'last_sync_time' => null,
        'sso_available' => false,
    ];

    try {
        $properties = peakrackupstream_service_properties($params);
        $ssoAvailable = $properties->hasUpstreamBinding()
            && strcasecmp((string) ($params['status'] ?? ''), 'Active') === 0;
        $data = $properties->customerData((string) ($params['status'] ?? ''), $ssoAvailable);
    } catch (Throwable) {
        // Render only empty safe values when WHMCS service context is incomplete.
    }

    return [
        'templatefile' => 'clientarea',
        'vars' => [
            'service_status' => $data['status'],
            'primary_ip' => $data['primary_ip'],
            'panel_url' => $data['panel_url'],
            'last_sync_time' => $data['last_sync_time'],
            'sso_available' => $data['sso_available'],
        ],
    ];
}

function peakrackupstream_ServiceSingleSignOn(array $params): array
{
    try {
        $properties = peakrackupstream_service_properties($params);
        if (
            !$properties->hasUpstreamBinding()
            || strcasecmp((string) ($params['status'] ?? ''), 'Active') !== 0
        ) {
            return [
                'success' => false,
                'errorMsg' => 'The upstream control panel is not available for this service.',
            ];
        }

        return (new SsoService(peakrackupstream_api_client($params)))->request(
            Validator::positiveInt($params['serviceid'] ?? null, 'local service ID')
        );
    } catch (Throwable) {
        return [
            'success' => false,
            'errorMsg' => 'The upstream control panel is temporarily unavailable.',
        ];
    }
}

function peakrackupstream_api_client(array $params): ApiClient
{
    $apiKey = Validator::nonEmptyString($params['serverusername'] ?? null, 'API key', 128);
    $apiSecret = Validator::nonEmptyString($params['serverpassword'] ?? null, 'API secret', 4096);

    return new ApiClient(
        Mapper::apiBaseUrl($params),
        $apiKey,
        $apiSecret,
        Mapper::timeout($params)
    );
}

function peakrackupstream_service_properties(array $params): ServiceProperties
{
    $model = $params['model'] ?? null;
    if (!is_object($model) || !isset($model->serviceProperties) || !is_object($model->serviceProperties)) {
        throw new RuntimeException('The WHMCS service property store is unavailable.');
    }

    return new ServiceProperties($model->serviceProperties);
}

function peakrackupstream_module_service(array $params): ModuleService
{
    $properties = peakrackupstream_service_properties($params);

    return new ModuleService(
        peakrackupstream_api_client($params),
        $properties,
        new Idempotency($properties)
    );
}

function peakrackupstream_module_action(array $params, string $action): string
{
    try {
        $service = peakrackupstream_module_service($params);
        return match ($action) {
            'create' => $service->create($params),
            'suspend' => $service->suspend($params),
            'unsuspend' => $service->unsuspend($params),
            'terminate' => $service->terminate($params),
            'renew' => $service->renew($params),
            'changePackage' => $service->changePackage($params),
            default => 'The requested upstream module action is not supported.',
        };
    } catch (InvalidArgumentException $exception) {
        return 'Upstream module configuration error: ' . $exception->getMessage();
    } catch (Throwable) {
        return 'The upstream module action could not be completed.';
    }
}

function peakrackupstream_renewal_params(array $params): array
{
    if ((string) ($params['nextduedate'] ?? '') !== '') {
        return $params;
    }

    $model = $params['model'] ?? null;
    if (!is_object($model)) {
        return $params;
    }
    foreach (['nextDueDate', 'nextduedate'] as $property) {
        if (!isset($model->{$property})) {
            continue;
        }
        $value = $model->{$property};
        if ($value instanceof DateTimeInterface) {
            $params['nextduedate'] = $value->format('Y-m-d');
            return $params;
        }
        if (is_scalar($value)) {
            $params['nextduedate'] = substr((string) $value, 0, 10);
            return $params;
        }
    }

    return $params;
}

function peakrackupstream_is_uuid(string $value): bool
{
    return preg_match(
        '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89aAbB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/',
        $value
    ) === 1;
}
