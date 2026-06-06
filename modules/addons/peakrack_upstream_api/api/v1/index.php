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

use PeakRack\UpstreamApi\Application\ApiKernel;
use PeakRack\UpstreamApi\Application\CatalogService;
use PeakRack\UpstreamApi\Application\ChangePackageExecutor;
use PeakRack\UpstreamApi\Application\CreateExecutor;
use PeakRack\UpstreamApi\Application\HealthService;
use PeakRack\UpstreamApi\Application\LifecycleExecutor;
use PeakRack\UpstreamApi\Application\OperationExecutor;
use PeakRack\UpstreamApi\Application\OperationService;
use PeakRack\UpstreamApi\Application\RenewExecutor;
use PeakRack\UpstreamApi\Bootstrap;
use PeakRack\UpstreamApi\Database\CapsuleApiKeyRepository;
use PeakRack\UpstreamApi\Database\CapsuleLockRepository;
use PeakRack\UpstreamApi\Database\CapsuleNonceRepository;
use PeakRack\UpstreamApi\Database\CapsuleOperationRepository;
use PeakRack\UpstreamApi\Database\CapsulePolicyRepository;
use PeakRack\UpstreamApi\Database\CapsuleServiceRepository;
use PeakRack\UpstreamApi\Domain\ApiError;
use PeakRack\UpstreamApi\Http\Request;
use PeakRack\UpstreamApi\Http\RequestPath;
use PeakRack\UpstreamApi\Http\Response;
use PeakRack\UpstreamApi\Http\Router;
use PeakRack\UpstreamApi\Security\Authenticator;
use PeakRack\UpstreamApi\Security\RateLimiter;
use PeakRack\UpstreamApi\Support\SystemClock;
use PeakRack\UpstreamApi\Support\UuidGenerator;
use PeakRack\UpstreamApi\Whmcs\BillingGateway;
use PeakRack\UpstreamApi\Whmcs\LocalApiClient;
use PeakRack\UpstreamApi\Whmcs\OperationContext;
use PeakRack\UpstreamApi\Whmcs\ProvisioningGateway;
use WHMCS\Database\Capsule;

$root = dirname(__DIR__, 5);

try {
    require_once dirname(__DIR__, 2) . '/lib/Bootstrap.php';
    Bootstrap::register();
    require_once $root . '/init.php';

    $clock = new SystemClock();
    $ids = new UuidGenerator();
    $apiKeys = new CapsuleApiKeyRepository();
    $policies = new CapsulePolicyRepository();
    $services = new CapsuleServiceRepository();
    $operations = new CapsuleOperationRepository();
    $locks = new CapsuleLockRepository();
    $nonces = new CapsuleNonceRepository();
    $localApi = new LocalApiClient();
    $billing = new BillingGateway($localApi);

    $serviceReader = static function (int $serviceId): array {
        $service = Capsule::table('tblhosting')->where('id', $serviceId)->first();
        if ($service === null) {
            throw new RuntimeException('Service not found.');
        }

        $customFields = [];
        $rows = Capsule::table('tblcustomfieldsvalues')
            ->join('tblcustomfields', 'tblcustomfields.id', '=', 'tblcustomfieldsvalues.fieldid')
            ->where('tblcustomfieldsvalues.relid', $serviceId)
            ->where('tblcustomfields.type', 'product')
            ->get(['tblcustomfields.id', 'tblcustomfields.fieldname', 'tblcustomfieldsvalues.value'])
            ->all();
        foreach ($rows as $row) {
            $customFields[(int) $row->id] = [
                'name' => (string) $row->fieldname,
                'value' => (string) $row->value,
            ];
        }

        return [
            'id' => (int) $service->id,
            'status' => (string) $service->domainstatus,
            'dedicated_ip' => (string) $service->dedicatedip,
            'product_id' => (int) $service->packageid,
            'billing_cycle' => (string) $service->billingcycle,
            'next_due_date' => (string) $service->nextduedate,
            'custom_fields' => $customFields,
        ];
    };
    $ssoResolver = static function (int $serviceId) use ($root): array {
        require_once $root . '/includes/modulefunctions.php';
        if (!function_exists('ModuleBuildParams')) {
            return ['success' => false];
        }

        $params = ModuleBuildParams($serviceId);
        $module = (string) ($params['moduletype'] ?? '');
        if ($module === '') {
            return ['success' => false];
        }

        if (function_exists('loadServerModule')) {
            loadServerModule($module);
        }
        $function = $module . '_ServiceSingleSignOn';
        if (!function_exists($function)) {
            return ['success' => false];
        }

        $response = $function($params);
        return is_array($response) ? $response : ['success' => false];
    };
    $provisioning = new ProvisioningGateway($localApi, $serviceReader, $ssoResolver);
    $cancelRequest = static function (int $serviceId, string $operationId) use ($localApi, $serviceReader): array {
        OperationContext::run($operationId, static fn (): array => $localApi->call('AddCancelRequest', [
            'serviceid' => $serviceId,
            'type' => 'End of Billing Period',
            'reason' => 'Authorized PeakRack reseller cancellation',
        ]));
        $state = $serviceReader($serviceId);
        $dueAt = strtotime((string) $state['next_due_date'] . ' 00:00:00 UTC');
        return ['due_at' => $dueAt === false ? 0 : $dueAt];
    };

    $createExecutor = new CreateExecutor($billing, $provisioning, $services, $operations);
    $renewalInvoiceFinder = static function (
        int $clientId,
        int $serviceId,
        string $boundary
    ): ?int {
        $invoiceId = Capsule::table('tblinvoiceitems')
            ->join('tblinvoices', 'tblinvoices.id', '=', 'tblinvoiceitems.invoiceid')
            ->where('tblinvoiceitems.userid', $clientId)
            ->where('tblinvoiceitems.type', 'Hosting')
            ->where('tblinvoiceitems.relid', $serviceId)
            ->where('tblinvoices.userid', $clientId)
            ->where('tblinvoices.status', 'Unpaid')
            ->where('tblinvoices.duedate', $boundary)
            ->orderBy('tblinvoices.id')
            ->value('tblinvoices.id');

        return $invoiceId === null ? null : (int) $invoiceId;
    };
    $renewExecutor = new RenewExecutor($billing, $operations, $serviceReader, $renewalInvoiceFinder);
    $changeExecutor = new ChangePackageExecutor($billing, $operations, $services, $serviceReader);
    $lifecycleExecutor = new LifecycleExecutor($provisioning, $services, $operations, $cancelRequest);
    $executor = new OperationExecutor([
        'create' => $createExecutor,
        'renew' => $renewExecutor,
        'change_package' => $changeExecutor,
        'suspend' => $lifecycleExecutor,
        'unsuspend' => $lifecycleExecutor,
        'terminate' => $lifecycleExecutor,
        'terminate_due' => $lifecycleExecutor,
    ]);
    $operationService = new OperationService($operations, $locks, $executor, $ids, $clock);
    $authenticator = new Authenticator(
        $apiKeys,
        $nonces,
        $clock,
        new RateLimiter($apiKeys),
        static function (string $encrypted): string {
            if (!function_exists('decrypt')) {
                throw new RuntimeException('WHMCS decryption helper is unavailable.');
            }
            return (string) decrypt($encrypted);
        }
    );
    $settingsReader = static function (): array {
        $settings = Capsule::table('tblconfiguration')
            ->whereIn('setting', ['AutoCreditApply', 'CreditOnDowngrade'])
            ->pluck('value', 'setting')
            ->all();
        $addon = Capsule::table('tbladdonmodules')
            ->where('module', 'peakrack_upstream_api')
            ->pluck('value', 'setting')
            ->all();

        return [
            'automatic_credit_use' => ($settings['AutoCreditApply'] ?? '') === 'on',
            'credit_on_downgrade' => ($settings['CreditOnDowngrade'] ?? '') === 'on',
            'worker_last_run' => isset($addon['worker_last_run']) ? (int) $addon['worker_last_run'] : null,
        ];
    };
    $paymentMethod = (string) (Capsule::table('tbladdonmodules')
        ->where('module', 'peakrack_upstream_api')
        ->where('setting', 'order_payment_method')
        ->value('value') ?? 'mailin');
    $kernel = new ApiKernel(
        [$authenticator, 'authenticate'],
        new Router(),
        [$operationService, 'admit'],
        $services,
        $operations,
        $policies,
        new CatalogService($policies),
        new HealthService($settingsReader),
        $provisioning,
        $paymentMethod
    );

    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $headers = is_array($headers) ? $headers : [];
    foreach ($_SERVER as $key => $value) {
        if (str_starts_with($key, 'HTTP_')) {
            $headers[str_replace('_', '-', substr($key, 5))] = $value;
        }
    }
    $requestPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if ($requestPath === '') {
        $requestPath = '/';
    }
    $path = RequestPath::route($requestPath, (string) ($_SERVER['PATH_INFO'] ?? ''));

    $request = new Request(
        (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        $path,
        $_GET,
        $headers,
        (string) file_get_contents('php://input'),
        (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        strtolower((string) ($_SERVER['HTTPS'] ?? '')) === 'on'
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443,
        $requestPath
    );
    $response = $kernel->handle($request);
} catch (Throwable) {
    $response = Response::error(ApiError::INTERNAL_ERROR, 'An internal error occurred.', 500);
}

http_response_code($response->statusCode());
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo $response->body();
