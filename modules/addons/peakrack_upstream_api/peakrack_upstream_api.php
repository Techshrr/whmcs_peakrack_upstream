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

use PeakRack\UpstreamApi\Admin\ActivationService;
use PeakRack\UpstreamApi\Admin\AdminController;
use PeakRack\UpstreamApi\Admin\Csrf;
use PeakRack\UpstreamApi\Admin\OnboardingAdminController;
use PeakRack\UpstreamApi\Admin\TemplateRenderer;
use PeakRack\UpstreamApi\Bootstrap;
use PeakRack\UpstreamApi\Config;
use PeakRack\UpstreamApi\Database\CapsuleApplicationRepository;
use PeakRack\UpstreamApi\Database\CapsuleAuditRepository;
use PeakRack\UpstreamApi\Database\CapsuleApiKeyRepository;
use PeakRack\UpstreamApi\Database\CapsuleOperationRepository;
use PeakRack\UpstreamApi\Database\CapsulePolicyTemplateRepository;
use PeakRack\UpstreamApi\Database\CapsulePolicyRepository;
use PeakRack\UpstreamApi\Database\Schema;
use PeakRack\UpstreamApi\Support\UuidGenerator;
use WHMCS\Database\Capsule;

require_once __DIR__ . '/lib/Bootstrap.php';
Bootstrap::register();

function peakrack_upstream_api_config(): array
{
    return [
        'name' => 'PeakRack Upstream API',
        'description' => 'Exposes an authenticated reseller API for approved upstream WHMCS products.',
        'version' => Config::VERSION,
        'author' => 'PeakRack',
        'language' => 'english',
        'fields' => [
            'order_payment_method' => [
                'FriendlyName' => 'Order Payment Method',
                'Type' => 'text',
                'Size' => '25',
                'Default' => 'mailin',
                'Description' => 'WHMCS payment method used for API-created orders.',
            ],
            'worker_batch_size' => [
                'FriendlyName' => 'Worker Batch Size',
                'Type' => 'text',
                'Size' => '8',
                'Default' => '25',
                'Description' => 'Operations claimed by each one-minute worker run.',
            ],
        ],
    ];
}

function peakrack_upstream_api_activate(): array
{
    return (new ActivationService(
        [Schema::class, 'install'],
        static function (): array {
            $configuration = Capsule::table('tblconfiguration')
                ->whereIn('setting', ['AutoCreditApply', 'CreditOnDowngrade'])
                ->pluck('value', 'setting')
                ->all();
            $errors = [];
            if (($configuration['AutoCreditApply'] ?? '') === 'on') {
                $errors[] = 'Automatic Credit Use must be disabled.';
            }
            if (($configuration['CreditOnDowngrade'] ?? '') === 'on') {
                $errors[] = 'Credit on Downgrade must be disabled.';
            }
            return $errors;
        }
    ))
        ->activate(PHP_VERSION, peakrack_upstream_api_whmcs_version());
}

function peakrack_upstream_api_deactivate(): array
{
    return (new ActivationService([Schema::class, 'install']))->deactivate();
}

function peakrack_upstream_api_output(array $vars): void
{
    $adminId = isset($_SESSION['adminid']) ? (int) $_SESSION['adminid'] : null;
    $request = array_merge($_GET, $_POST);

    try {
        $moduleLink = (string) ($vars['modulelink'] ?? 'addonmodules.php?module=peakrack_upstream_api');
        $page = (string) ($request['page'] ?? 'dashboard');
        $view = in_array($page, ['policy_templates', 'onboarding_applications'], true)
            ? peakrack_upstream_api_onboarding_admin_controller()->dispatch($request, $adminId, $_SESSION, $moduleLink)
            : peakrack_upstream_api_admin_controller()->dispatch($request, $adminId, $_SESSION, $moduleLink);
        echo (new TemplateRenderer(__DIR__ . '/templates'))->render($view);
    } catch (InvalidArgumentException $exception) {
        echo '<div class="alert alert-danger">'
            . htmlspecialchars($exception->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</div>';
    } catch (Throwable) {
        echo '<div class="alert alert-danger">The administrator request could not be completed.</div>';
    }
}

function peakrack_upstream_api_onboarding_admin_controller(): OnboardingAdminController
{
    return new OnboardingAdminController(
        applications: new CapsuleApplicationRepository(),
        templates: new CapsulePolicyTemplateRepository(),
        audits: new CapsuleAuditRepository(),
        csrf: new Csrf(),
        policyValidator: static function (array $policy): void {
            $product = Capsule::table('tblproducts')->where('id', (int) $policy['product_id'])->first();
            if ($product === null || trim((string) $product->servertype) === '') {
                throw new InvalidArgumentException('The product must exist and have a Provisioning Module assigned.');
            }
        },
        clock: static fn (): int => time()
    );
}

function peakrack_upstream_api_admin_controller(): AdminController
{
    $keys = new CapsuleApiKeyRepository();
    $policies = new CapsulePolicyRepository();
    $operations = new CapsuleOperationRepository();

    return new AdminController(
        keys: $keys,
        policies: $policies,
        operations: $operations,
        csrf: new Csrf(),
        encryptSecret: static function (string $secret): string {
            if (!function_exists('encrypt')) {
                throw new RuntimeException('WHMCS encryption helper is unavailable.');
            }
            return (string) encrypt($secret);
        },
        policyValidator: static function (array $policy) use ($keys): void {
            $key = $keys->find((int) $policy['api_key_id']);
            if ($key === null) {
                throw new InvalidArgumentException('The API key does not exist.');
            }

            $product = Capsule::table('tblproducts')->where('id', (int) $policy['product_id'])->first();
            if ($product === null || trim((string) $product->servertype) === '') {
                throw new InvalidArgumentException('The product must exist and have a Provisioning Module assigned.');
            }

            $currencyId = (int) (Capsule::table('tblclients')
                ->where('id', (int) $key['client_id'])
                ->value('currency') ?? 0);
            if ($currencyId < 1) {
                $currencyId = (int) (Capsule::table('tblcurrencies')->where('default', 1)->value('id') ?? 0);
            }
            $pricing = Capsule::table('tblpricing')
                ->where('type', 'product')
                ->where('relid', (int) $policy['product_id'])
                ->where('currency', $currencyId)
                ->first();
            if ($pricing === null) {
                throw new InvalidArgumentException('The product does not have pricing in the reseller currency.');
            }

            $allowedCycles = ['monthly', 'quarterly', 'semiannually', 'annually', 'biennially', 'triennially'];
            foreach ((array) $policy['billing_cycles'] as $cycle) {
                if (!in_array($cycle, $allowedCycles, true) || (string) ($pricing->{$cycle} ?? '-1.00') === '-1.00') {
                    throw new InvalidArgumentException('The policy contains an unavailable billing cycle.');
                }
            }
        },
        keyUpdater: static function (int $id, array $attributes): void {
            $allowed = array_intersect_key($attributes, array_flip([
                'enabled',
                'ip_allowlist_json',
                'rate_limit_per_minute',
            ]));
            if ($allowed === []) {
                return;
            }
            $allowed['updated_at'] = time();
            Capsule::table(Schema::API_KEYS)->where('id', $id)->update($allowed);
        },
        publicKeyGenerator: static fn (): string => 'prk_' . bin2hex(random_bytes(20)),
        secretGenerator: static fn (): string => 'prs_' . bin2hex(random_bytes(32)),
        instanceIdGenerator: [new UuidGenerator(), 'uuid'],
        pageReader: static function (string $page): array {
            return match ($page) {
                'dashboard' => [[
                    'queued_operations' => Capsule::table(Schema::OPERATIONS)->where('status', 'queued')->count(),
                    'processing_operations' => Capsule::table(Schema::OPERATIONS)->where('status', 'processing')->count(),
                    'manual_review_operations' => Capsule::table(Schema::OPERATIONS)->where('status', 'manual_review')->count(),
                    'managed_services' => Capsule::table(Schema::SERVICES)->count(),
                ]],
                'product_policies' => array_map(
                    static fn (object $row): array => (array) $row,
                    Capsule::table(Schema::POLICIES)->orderByDesc('id')->limit(100)->get()->all()
                ),
                'operations' => array_map(
                    static fn (object $row): array => (array) $row,
                    Capsule::table(Schema::OPERATIONS)
                        ->select([
                            'operation_id', 'api_key_id', 'local_service_id', 'action', 'status',
                            'stage', 'attempt_count', 'last_error_code', 'updated_at',
                        ])
                        ->orderByDesc('id')
                        ->limit(100)
                        ->get()
                        ->all()
                ),
                'services' => array_map(
                    static fn (object $row): array => (array) $row,
                    Capsule::table(Schema::SERVICES)->orderByDesc('id')->limit(100)->get()->all()
                ),
                'system_health' => peakrack_upstream_api_system_health_rows(),
                default => [],
            };
        },
        clock: static fn (): int => time(),
        clientValidator: static function (int $clientId): void {
            if (!Capsule::table('tblclients')->where('id', $clientId)->exists()) {
                throw new InvalidArgumentException('The reseller client does not exist.');
            }
        }
    );
}

function peakrack_upstream_api_system_health_rows(): array
{
    $configuration = Capsule::table('tblconfiguration')
        ->whereIn('setting', ['AutoCreditApply', 'CreditOnDowngrade'])
        ->pluck('value', 'setting')
        ->all();
    $workerLastRun = Capsule::table('tbladdonmodules')
        ->where('module', 'peakrack_upstream_api')
        ->where('setting', 'worker_last_run')
        ->value('value');

    return [[
        'php_version' => PHP_VERSION,
        'whmcs_version' => peakrack_upstream_api_whmcs_version(),
        'https' => strtolower((string) ($_SERVER['HTTPS'] ?? '')) === 'on'
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443,
        'automatic_credit_use' => ($configuration['AutoCreditApply'] ?? '') === 'on',
        'credit_on_downgrade' => ($configuration['CreditOnDowngrade'] ?? '') === 'on',
        'worker_last_run' => $workerLastRun === null ? null : (int) $workerLastRun,
        'empty_ip_allowlists' => Capsule::table(Schema::API_KEYS)
            ->whereNull('ip_allowlist_json')
            ->orWhere('ip_allowlist_json', '[]')
            ->count(),
    ]];
}

function peakrack_upstream_api_whmcs_version(): string
{
    if (defined('WHMCS_VERSION')) {
        return (string) constant('WHMCS_VERSION');
    }
    if (isset($GLOBALS['CONFIG']['Version'])) {
        return (string) $GLOBALS['CONFIG']['Version'];
    }

    try {
        if (class_exists(\WHMCS\Application::class)) {
            $version = \WHMCS\Application::getInstance()->getVersion();
            return (string) $version;
        }
    } catch (Throwable) {
    }

    return '';
}
