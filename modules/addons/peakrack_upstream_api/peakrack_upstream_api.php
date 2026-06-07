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
use PeakRack\UpstreamApi\Application\CredentialService;
use PeakRack\UpstreamApi\Application\EligibilityService;
use PeakRack\UpstreamApi\Application\OnboardingService;
use PeakRack\UpstreamApi\Application\OnboardingValidator;
use PeakRack\UpstreamApi\Application\SetupGuideService;
use PeakRack\UpstreamApi\Bootstrap;
use PeakRack\UpstreamApi\ClientArea\OnboardingClientAreaController;
use PeakRack\UpstreamApi\Config;
use PeakRack\UpstreamApi\Database\CapsuleApplicationRepository;
use PeakRack\UpstreamApi\Database\CapsuleAuditRepository;
use PeakRack\UpstreamApi\Database\CapsuleApiKeyRepository;
use PeakRack\UpstreamApi\Database\CapsuleOperationRepository;
use PeakRack\UpstreamApi\Database\CapsulePolicyTemplateRepository;
use PeakRack\UpstreamApi\Database\CapsulePolicyRepository;
use PeakRack\UpstreamApi\Database\CapsuleSecretResetRepository;
use PeakRack\UpstreamApi\Database\Schema;
use PeakRack\UpstreamApi\Support\UuidGenerator;
use PeakRack\UpstreamApi\Whmcs\CapsuleClientAccountGateway;
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
            'allowed_client_group_ids' => [
                'FriendlyName' => 'Allowed Client Group IDs',
                'Type' => 'text',
                'Size' => '40',
                'Default' => '',
                'Description' => 'Comma-separated WHMCS client group IDs allowed to apply for upstream API access.',
            ],
            'downstream_module_download_url' => [
                'FriendlyName' => 'Downstream Module Download URL',
                'Type' => 'text',
                'Size' => '80',
                'Default' => '',
                'Description' => 'Download URL shown to approved reseller clients.',
            ],
            'integration_terms_url' => [
                'FriendlyName' => 'Integration Terms URL',
                'Type' => 'text',
                'Size' => '80',
                'Default' => '',
                'Description' => 'Terms URL shown next to the Client Area agreement checkbox.',
            ],
            'default_api_rate_limit' => [
                'FriendlyName' => 'Default API Rate Limit',
                'Type' => 'text',
                'Size' => '8',
                'Default' => '120',
                'Description' => 'Per-minute rate limit for API keys created through onboarding.',
            ],
            'require_outbound_ip_allowlist' => [
                'FriendlyName' => 'Require Outbound IP Allowlist',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Require reseller applications to submit at least one outbound IP or CIDR entry.',
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

function peakrack_upstream_api_clientarea(array $vars): array
{
    $session = &$_SESSION;
    $request = array_merge($_GET, $_POST);
    $sourceIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    $view = peakrack_upstream_api_clientarea_controller($vars)->dispatch($request, $session, $sourceIp);

    return [
        'pagetitle' => 'PeakRack Upstream Integration',
        'breadcrumb' => ['index.php?m=peakrack_upstream_api' => 'PeakRack Upstream Integration'],
        'templatefile' => 'clientarea-onboarding',
        'requirelogin' => true,
        'forcessl' => true,
        'vars' => $view,
    ];
}

function peakrack_upstream_api_onboarding_admin_controller(): OnboardingAdminController
{
    $applications = new CapsuleApplicationRepository();
    $keys = new CapsuleApiKeyRepository();
    $policies = new CapsulePolicyRepository();
    $templates = new CapsulePolicyTemplateRepository();
    $audits = new CapsuleAuditRepository();
    $resets = new CapsuleSecretResetRepository();
    $clock = static fn (): int => time();

    return new OnboardingAdminController(
        applications: $applications,
        templates: $templates,
        audits: $audits,
        csrf: new Csrf(),
        policyValidator: static function (array $policy): void {
            $product = Capsule::table('tblproducts')->where('id', (int) $policy['product_id'])->first();
            if ($product === null || trim((string) $product->servertype) === '') {
                throw new InvalidArgumentException('The product must exist and have a Provisioning Module assigned.');
            }
        },
        clock: $clock,
        onboarding: new OnboardingService(
            applications: $applications,
            keys: $keys,
            policies: $policies,
            templates: $templates,
            audits: $audits,
            encryptSecret: static function (string $secret): string {
                if (!function_exists('encrypt')) {
                    throw new RuntimeException('WHMCS encryption helper is unavailable.');
                }
                return (string) encrypt($secret);
            },
            publicKeyGenerator: static fn (): string => 'prk_' . bin2hex(random_bytes(20)),
            secretGenerator: static fn (): string => 'prs_' . bin2hex(random_bytes(32)),
            instanceIdGenerator: [new UuidGenerator(), 'uuid'],
            defaultRateLimit: 120,
            clock: $clock,
            transaction: static fn (callable $callback): mixed => Capsule::connection()->transaction($callback)
        ),
        credentials: new CredentialService(
            applications: $applications,
            keys: $keys,
            resets: $resets,
            audits: $audits,
            encryptSecret: static function (string $secret): string {
                if (!function_exists('encrypt')) {
                    throw new RuntimeException('WHMCS encryption helper is unavailable.');
                }
                return (string) encrypt($secret);
            },
            decryptSecret: static function (string $secret): string {
                if (!function_exists('decrypt')) {
                    throw new RuntimeException('WHMCS decryption helper is unavailable.');
                }
                return (string) decrypt($secret);
            },
            secretGenerator: static fn (): string => 'prs_' . bin2hex(random_bytes(32)),
            clock: $clock
        )
    );
}

function peakrack_upstream_api_clientarea_controller(array $vars): OnboardingClientAreaController
{
    $applications = new CapsuleApplicationRepository();
    $keys = new CapsuleApiKeyRepository();
    $audits = new CapsuleAuditRepository();
    $clock = static fn (): int => time();

    return new OnboardingClientAreaController(
        applications: $applications,
        eligibility: new EligibilityService(
            new CapsuleClientAccountGateway(),
            peakrack_upstream_api_allowed_client_group_ids($vars)
        ),
        validator: new OnboardingValidator(peakrack_upstream_api_yesno_enabled($vars, 'require_outbound_ip_allowlist', true)),
        onboarding: new OnboardingService(
            applications: $applications,
            keys: $keys,
            policies: new CapsulePolicyRepository(),
            templates: new CapsulePolicyTemplateRepository(),
            audits: $audits,
            encryptSecret: static function (string $secret): string {
                if (!function_exists('encrypt')) {
                    throw new RuntimeException('WHMCS encryption helper is unavailable.');
                }
                return (string) encrypt($secret);
            },
            publicKeyGenerator: static fn (): string => 'prk_' . bin2hex(random_bytes(20)),
            secretGenerator: static fn (): string => 'prs_' . bin2hex(random_bytes(32)),
            instanceIdGenerator: [new UuidGenerator(), 'uuid'],
            defaultRateLimit: peakrack_upstream_api_positive_int_config($vars, 'default_api_rate_limit', 120),
            clock: $clock,
            transaction: static fn (callable $callback): mixed => Capsule::connection()->transaction($callback)
        ),
        credentials: new CredentialService(
            applications: $applications,
            keys: $keys,
            resets: new CapsuleSecretResetRepository(),
            audits: $audits,
            encryptSecret: static function (string $secret): string {
                if (!function_exists('encrypt')) {
                    throw new RuntimeException('WHMCS encryption helper is unavailable.');
                }
                return (string) encrypt($secret);
            },
            decryptSecret: static function (string $secret): string {
                if (!function_exists('decrypt')) {
                    throw new RuntimeException('WHMCS decryption helper is unavailable.');
                }
                return (string) decrypt($secret);
            },
            secretGenerator: static fn (): string => 'prs_' . bin2hex(random_bytes(32)),
            clock: $clock
        ),
        guides: new SetupGuideService(),
        config: [
            'download_url' => (string) ($vars['downstream_module_download_url'] ?? ''),
            'api_base_url' => peakrack_upstream_api_public_api_base_url(),
            'terms_url' => (string) ($vars['integration_terms_url'] ?? ''),
        ]
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

function peakrack_upstream_api_allowed_client_group_ids(array $vars): array
{
    $raw = (string) ($vars['allowed_client_group_ids'] ?? '');
    if (trim($raw) === '') {
        return [];
    }

    $ids = [];
    foreach (preg_split('/[\s,]+/', $raw) ?: [] as $value) {
        if (ctype_digit($value) && (int) $value > 0) {
            $ids[] = (int) $value;
        }
    }

    return array_values(array_unique($ids));
}

function peakrack_upstream_api_public_api_base_url(): string
{
    $systemUrl = rtrim((string) ($GLOBALS['CONFIG']['SystemURL'] ?? ''), '/');
    if ($systemUrl === '') {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? '')) === 'on'
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
        $systemUrl = ($https ? 'https://' : 'http://') . $host;
    }

    return rtrim($systemUrl, '/') . '/modules/addons/peakrack_upstream_api/api/v1';
}

function peakrack_upstream_api_yesno_enabled(array $vars, string $key, bool $default): bool
{
    if (!array_key_exists($key, $vars)) {
        return $default;
    }

    return in_array($vars[$key], ['on', '1', 1, true, 'yes'], true);
}

function peakrack_upstream_api_positive_int_config(array $vars, string $key, int $default): int
{
    $value = $vars[$key] ?? null;
    if (is_int($value) && $value > 0) {
        return $value;
    }
    if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
        return (int) $value;
    }

    return $default;
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
