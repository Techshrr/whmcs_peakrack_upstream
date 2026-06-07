# PeakRack Upstream v1.1 Onboarding Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Client Area reseller onboarding with mandatory admin approval, policy-template application, one-time API Secret display, reset limits, and setup guidance without changing the v1 public API contract.

**Architecture:** Keep the existing upstream Addon and downstream Server Module layout. New onboarding behavior lives in focused upstream Addon classes under `modules/addons/peakrack_upstream_api/lib/`, with pure services tested by the dependency-free test runner and Capsule repositories used only at WHMCS boundaries. The existing manual API key and Product Policy admin pages remain available; onboarding adds optional Client Area and admin review pages around the same API key and policy tables.

**Tech Stack:** PHP 8.2+, WHMCS 9.0.x Addon Module APIs, `WHMCS\Database\Capsule`, WHMCS Client Area addon templates, WHMCS admin addon output, custom PHP CLI test runner, Apache-2.0 source distribution.

---

## Source Spec

- Design spec: `docs/superpowers/specs/2026-06-07-peakrack-upstream-v1-1-onboarding-design.md`
- Existing v1 plan: `docs/superpowers/plans/2026-06-05-peakrack-upstream-v1-implementation.md`

## File Map

### Existing Files to Modify

- `VERSION`: update to `1.1.0-dev` during development or final `1.1.0` at release.
- `CHANGELOG.md`: document planned v1.1 changes only after implementation exists.
- `README.md`: add onboarding installation/configuration notes after code is implemented.
- `README.zh-CN.md`: add Chinese onboarding setup notes after code is implemented.
- `UPGRADE.md`: add additive schema and configuration notes.
- `UPGRADE.zh-CN.md`: add Chinese additive schema and configuration notes.
- `modules/addons/peakrack_upstream_api/peakrack_upstream_api.php`: add onboarding config fields, Client Area entry function, controller factories, and page readers.
- `modules/addons/peakrack_upstream_api/lib/Config.php`: add v1.1 defaults and table-name references if the project keeps them there.
- `modules/addons/peakrack_upstream_api/lib/Database/Schema.php`: add additive onboarding tables and explicit short index names.
- `modules/addons/peakrack_upstream_api/lib/Admin/AdminController.php`: add routing for onboarding applications, templates, and admin secret reset only if a smaller controller cannot be introduced cleanly.
- `modules/addons/peakrack_upstream_api/lib/Admin/TemplateRenderer.php`: add navigation labels and forms for onboarding admin pages.
- `modules/addons/peakrack_upstream_api/lang/english.php`: add v1.1 language keys.
- `modules/addons/peakrack_upstream_api/lang/chinese.php`: add v1.1 language keys.
- `tests/unit/upstream/AdminControllerTest.php`: extend admin tests for review actions if the existing controller handles the actions.
- `tests/unit/upstream/SchemaTest.php`: extend schema contract tests.
- `tests/unit/DocumentationTest.php`: extend docs expectations only for implemented features.
- `tests/integration/WhmcsSchemaTest.php`: ensure additive schema tables are installed and deactivation preserves them.

### New Files to Create

- `modules/addons/peakrack_upstream_api/lib/Contracts/ApplicationRepository.php`: persistence contract for onboarding applications.
- `modules/addons/peakrack_upstream_api/lib/Contracts/PolicyTemplateRepository.php`: persistence contract for policy templates and template items.
- `modules/addons/peakrack_upstream_api/lib/Contracts/AuditRepository.php`: persistence contract for sanitized audit events.
- `modules/addons/peakrack_upstream_api/lib/Contracts/SecretResetRepository.php`: persistence contract for reset limit evidence.
- `modules/addons/peakrack_upstream_api/lib/Contracts/ClientAccountGateway.php`: WHMCS client eligibility boundary.
- `modules/addons/peakrack_upstream_api/lib/Database/CapsuleApplicationRepository.php`: Capsule application repository.
- `modules/addons/peakrack_upstream_api/lib/Database/CapsulePolicyTemplateRepository.php`: Capsule template repository.
- `modules/addons/peakrack_upstream_api/lib/Database/CapsuleAuditRepository.php`: Capsule audit repository.
- `modules/addons/peakrack_upstream_api/lib/Database/CapsuleSecretResetRepository.php`: Capsule reset-event repository.
- `modules/addons/peakrack_upstream_api/lib/Whmcs/CapsuleClientAccountGateway.php`: WHMCS client status, email verification, group, and invoice checks.
- `modules/addons/peakrack_upstream_api/lib/Domain/OnboardingApplication.php`: immutable application value object.
- `modules/addons/peakrack_upstream_api/lib/Domain/PolicyTemplate.php`: immutable template aggregate.
- `modules/addons/peakrack_upstream_api/lib/Application/OnboardingValidator.php`: application input validation and normalization.
- `modules/addons/peakrack_upstream_api/lib/Application/EligibilityService.php`: eligibility checks for logged-in clients.
- `modules/addons/peakrack_upstream_api/lib/Application/OnboardingService.php`: submit, approve, reject, and application lookup orchestration.
- `modules/addons/peakrack_upstream_api/lib/Application/CredentialService.php`: one-time display and reset-limit logic.
- `modules/addons/peakrack_upstream_api/lib/Application/SetupGuideService.php`: approved-client setup guide data builder.
- `modules/addons/peakrack_upstream_api/lib/Admin/OnboardingAdminController.php`: admin review and policy-template actions if kept separate from `AdminController`.
- `modules/addons/peakrack_upstream_api/lib/ClientArea/OnboardingClientAreaController.php`: client page state and actions.
- `modules/addons/peakrack_upstream_api/templates/onboarding-applications.tpl`: admin applications page.
- `modules/addons/peakrack_upstream_api/templates/policy-templates.tpl`: admin template manager page.
- `modules/addons/peakrack_upstream_api/templates/clientarea-onboarding.tpl`: Client Area application and approved guide template.
- `tests/unit/upstream/OnboardingValidatorTest.php`: form validation tests.
- `tests/unit/upstream/EligibilityServiceTest.php`: eligibility tests.
- `tests/unit/upstream/PolicyTemplateTest.php`: template validation/application tests.
- `tests/unit/upstream/OnboardingServiceTest.php`: submit/approve/reject workflow tests.
- `tests/unit/upstream/CredentialServiceTest.php`: one-time secret and reset-limit tests.
- `tests/unit/upstream/SetupGuideServiceTest.php`: guide output and escaping data tests.
- `tests/unit/upstream/ClientAreaOnboardingTest.php`: ownership, CSRF, and client state tests.

## Task 1: Add Additive Onboarding Schema

**Files:**
- Modify: `modules/addons/peakrack_upstream_api/lib/Database/Schema.php`
- Modify: `tests/unit/upstream/SchemaTest.php`
- Modify: `tests/integration/WhmcsSchemaTest.php`

- [ ] **Step 1: Write failing schema table tests**

Add this method to `tests/unit/upstream/SchemaTest.php`:

```php
public function testDefinesOnboardingTablesWithShortIndexNames(): void
{
    $definitions = Schema::definitions();

    $expectedTables = [
        Schema::APPLICATIONS,
        Schema::POLICY_TEMPLATES,
        Schema::POLICY_TEMPLATE_ITEMS,
        Schema::SECRET_RESET_EVENTS,
        Schema::AUDIT_EVENTS,
    ];

    foreach ($expectedTables as $table) {
        $this->assertArrayHasKey($table, $definitions);
    }

    $this->assertContains('secret_pending_display', $definitions[Schema::APPLICATIONS]['columns']);
    $this->assertContains('terms_accepted_ip', $definitions[Schema::APPLICATIONS]['columns']);
    $this->assertContains('billing_cycles_json', $definitions[Schema::POLICY_TEMPLATE_ITEMS]['columns']);
    $this->assertContains('bypassed_limits', $definitions[Schema::SECRET_RESET_EVENTS]['columns']);
    $this->assertContains('sanitized_context_json', $definitions[Schema::AUDIT_EVENTS]['columns']);

    foreach (Schema::indexDefinitions() as $groups) {
        foreach ($groups as $definitionsForType) {
            foreach ($definitionsForType as $definition) {
                $this->assertTrue(strlen($definition['name']) <= 64, $definition['name']);
            }
        }
    }
}
```

- [ ] **Step 2: Run schema tests and verify RED**

Run:

```powershell
php tests\run.php tests\unit\upstream\SchemaTest.php
```

Expected: FAIL with a missing constant or missing table definition for `APPLICATIONS`.

- [ ] **Step 3: Add schema constants and definitions**

Add constants to `Schema`:

```php
public const APPLICATIONS = 'mod_peakrack_upstream_applications';
public const POLICY_TEMPLATES = 'mod_peakrack_upstream_policy_templates';
public const POLICY_TEMPLATE_ITEMS = 'mod_peakrack_upstream_policy_template_items';
public const SECRET_RESET_EVENTS = 'mod_peakrack_upstream_secret_reset_events';
public const AUDIT_EVENTS = 'mod_peakrack_upstream_audit_events';
```

Extend `definitions()` with exact columns:

```php
self::APPLICATIONS => [
    'columns' => [
        'id', 'client_id', 'status', 'brand_name', 'downstream_domain',
        'outbound_ips_json', 'business_type', 'telegram', 'qq', 'phone',
        'notes', 'terms_accepted_at', 'terms_accepted_ip', 'admin_message',
        'admin_id', 'reviewed_at', 'api_key_id', 'template_id',
        'secret_pending_display', 'created_at', 'updated_at',
    ],
    'unique' => [],
],
self::POLICY_TEMPLATES => [
    'columns' => ['id', 'name', 'description', 'enabled', 'created_at', 'updated_at'],
    'unique' => [['name']],
],
self::POLICY_TEMPLATE_ITEMS => [
    'columns' => [
        'id', 'template_id', 'product_id', 'billing_cycles_json', 'actions_json',
        'locations_json', 'os_templates_json', 'delivery_mappings_json',
        'sso_allowed', 'sso_hosts_json', 'destroy_allowed', 'sort_order',
        'created_at', 'updated_at',
    ],
    'unique' => [],
],
self::SECRET_RESET_EVENTS => [
    'columns' => [
        'id', 'api_key_id', 'client_id', 'actor_type', 'actor_id',
        'reset_at', 'bypassed_limits', 'source_ip', 'created_at',
    ],
    'unique' => [],
],
self::AUDIT_EVENTS => [
    'columns' => [
        'id', 'event_type', 'actor_type', 'actor_id', 'client_id',
        'application_id', 'api_key_id', 'sanitized_context_json', 'created_at',
    ],
    'unique' => [],
],
```

Extend `indexDefinitions()` with short names:

```php
self::APPLICATIONS => [
    'unique' => [],
    'index' => [
        ['columns' => ['client_id'], 'name' => 'pru_app_client_idx'],
        ['columns' => ['status'], 'name' => 'pru_app_status_idx'],
        ['columns' => ['api_key_id'], 'name' => 'pru_app_key_idx'],
        ['columns' => ['template_id'], 'name' => 'pru_app_template_idx'],
    ],
],
self::POLICY_TEMPLATES => [
    'unique' => [
        ['columns' => ['name'], 'name' => 'pru_tpl_name_uq'],
    ],
    'index' => [
        ['columns' => ['enabled'], 'name' => 'pru_tpl_enabled_idx'],
    ],
],
self::POLICY_TEMPLATE_ITEMS => [
    'unique' => [],
    'index' => [
        ['columns' => ['template_id'], 'name' => 'pru_tpl_item_tpl_idx'],
        ['columns' => ['product_id'], 'name' => 'pru_tpl_item_product_idx'],
        ['columns' => ['sort_order'], 'name' => 'pru_tpl_item_sort_idx'],
    ],
],
self::SECRET_RESET_EVENTS => [
    'unique' => [],
    'index' => [
        ['columns' => ['api_key_id'], 'name' => 'pru_reset_key_idx'],
        ['columns' => ['client_id'], 'name' => 'pru_reset_client_idx'],
        ['columns' => ['reset_at'], 'name' => 'pru_reset_at_idx'],
    ],
],
self::AUDIT_EVENTS => [
    'unique' => [],
    'index' => [
        ['columns' => ['event_type'], 'name' => 'pru_audit_type_idx'],
        ['columns' => ['client_id'], 'name' => 'pru_audit_client_idx'],
        ['columns' => ['application_id'], 'name' => 'pru_audit_app_idx'],
        ['columns' => ['api_key_id'], 'name' => 'pru_audit_key_idx'],
        ['columns' => ['created_at'], 'name' => 'pru_audit_created_idx'],
    ],
],
```

- [ ] **Step 4: Add table creation blocks**

Create each table in `Schema::install()` using explicit column lengths:

```php
$table->string('status', 32);
$table->string('brand_name', 120);
$table->string('downstream_domain', 255);
$table->text('outbound_ips_json');
$table->string('telegram', 128);
$table->string('qq', 12)->nullable();
$table->string('phone', 32)->nullable();
$table->boolean('secret_pending_display')->default(false);
```

Use `unsignedBigInteger` for timestamps and `text` for JSON columns. Do not add foreign key constraints because WHMCS installations often use mixed storage engines and existing project tables do not use foreign keys.

- [ ] **Step 5: Run schema tests and verify GREEN**

Run:

```powershell
php tests\run.php tests\unit\upstream\SchemaTest.php
```

Expected: all schema tests PASS.

- [ ] **Step 6: Commit**

```powershell
git add modules\addons\peakrack_upstream_api\lib\Database\Schema.php tests\unit\upstream\SchemaTest.php tests\integration\WhmcsSchemaTest.php
git commit -m "Add onboarding schema contract"
```

## Task 2: Add Domain Models and Repository Contracts

**Files:**
- Create: `modules/addons/peakrack_upstream_api/lib/Contracts/ApplicationRepository.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Contracts/PolicyTemplateRepository.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Contracts/AuditRepository.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Contracts/SecretResetRepository.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Domain/OnboardingApplication.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Domain/PolicyTemplate.php`
- Create: `tests/unit/upstream/PolicyTemplateTest.php`
- Create: `tests/unit/upstream/OnboardingApplicationTest.php`

- [ ] **Step 1: Write failing domain tests**

Create `tests/unit/upstream/OnboardingApplicationTest.php`:

```php
<?php

namespace PeakRack\Tests\Unit\Upstream;

use PeakRack\Tests\TestCase;
use PeakRack\UpstreamApi\Domain\OnboardingApplication;

final class OnboardingApplicationTest extends TestCase
{
    public function testOnlyPendingApplicationsCanBeApprovedOrRejected(): void
    {
        $pending = OnboardingApplication::restore([
            'id' => 10,
            'client_id' => 44,
            'status' => OnboardingApplication::PENDING,
            'brand_name' => 'Example Reseller',
            'downstream_domain' => 'billing.example.test',
            'outbound_ips_json' => '["192.0.2.10"]',
            'business_type' => 'VPS',
            'telegram' => '@example',
            'secret_pending_display' => 0,
        ]);

        $approved = $pending->approve(7, 3, 9, 1780617600);
        $this->assertSame(OnboardingApplication::APPROVED, $approved->status());
        $this->assertSame(7, $approved->apiKeyId());
        $this->assertSame(3, $approved->templateId());
        $this->assertTrue($approved->secretPendingDisplay());

        $this->assertThrows(
            fn () => $approved->reject('missing domain', 9, 1780617600),
            \InvalidArgumentException::class,
            'Only pending applications can be rejected.'
        );
    }
}
```

Create `tests/unit/upstream/PolicyTemplateTest.php`:

```php
<?php

namespace PeakRack\Tests\Unit\Upstream;

use PeakRack\Tests\TestCase;
use PeakRack\UpstreamApi\Domain\PolicyTemplate;

final class PolicyTemplateTest extends TestCase
{
    public function testTemplateAppliesMultiplePolicyRowsToOneApiKey(): void
    {
        $template = PolicyTemplate::restore([
            'id' => 3,
            'name' => 'Starter Reseller',
            'enabled' => 1,
            'items' => [
                [
                    'product_id' => 19,
                    'billing_cycles' => ['monthly'],
                    'actions' => ['create', 'renew'],
                    'locations' => [],
                    'os_templates' => [],
                    'delivery_mappings' => [],
                    'sso_allowed' => false,
                    'sso_hosts' => [],
                    'destroy_allowed' => false,
                    'sort_order' => 10,
                ],
                [
                    'product_id' => 20,
                    'billing_cycles' => ['monthly', 'annually'],
                    'actions' => ['create', 'renew', 'suspend'],
                    'locations' => ['hk' => ['configoption1' => 'hk']],
                    'os_templates' => [],
                    'delivery_mappings' => [],
                    'sso_allowed' => false,
                    'sso_hosts' => [],
                    'destroy_allowed' => false,
                    'sort_order' => 20,
                ],
            ],
        ]);

        $rows = $template->policyRowsForApiKey(8);
        $this->assertSame(2, count($rows));
        $this->assertSame(8, $rows[0]['api_key_id']);
        $this->assertSame(19, $rows[0]['product_id']);
        $this->assertSame(['monthly', 'annually'], $rows[1]['billing_cycles']);
    }
}
```

- [ ] **Step 2: Run domain tests and verify RED**

Run:

```powershell
php tests\run.php tests\unit\upstream\OnboardingApplicationTest.php tests\unit\upstream\PolicyTemplateTest.php
```

Expected: FAIL because the domain classes do not exist.

- [ ] **Step 3: Create repository contracts**

Use these exact public methods:

```php
interface ApplicationRepository
{
    public function create(array $attributes): int;
    public function find(int $id): ?OnboardingApplication;
    public function findActiveForClient(int $clientId): ?OnboardingApplication;
    public function findApprovedForClient(int $clientId): ?OnboardingApplication;
    public function findPending(int $id): ?OnboardingApplication;
    public function save(OnboardingApplication $application): void;
    public function listByStatus(?string $status, int $limit = 100): array;
}

interface PolicyTemplateRepository
{
    public function saveTemplate(array $template, array $items): int;
    public function find(int $id): ?PolicyTemplate;
    public function listTemplates(bool $enabledOnly = false): array;
    public function deleteTemplate(int $id): void;
}

interface AuditRepository
{
    public function append(array $event): void;
}

interface SecretResetRepository
{
    public function record(array $event): void;
    public function countClientResetsInMonth(int $apiKeyId, int $clientId, int $monthStart, int $monthEnd): int;
    public function latestClientResetAt(int $apiKeyId, int $clientId): ?int;
}
```

- [ ] **Step 4: Create domain classes**

`OnboardingApplication` must define:

```php
public const PENDING = 'pending';
public const APPROVED = 'approved';
public const REJECTED = 'rejected';
public const NEEDS_INFO = 'needs_info';

public static function restore(array $row): self;
public function approve(int $apiKeyId, int $templateId, int $adminId, int $now): self;
public function reject(string $message, int $adminId, int $now): self;
public function markSecretDisplayed(int $now): self;
public function markSecretPendingDisplay(int $now): self;
public function toRow(): array;
```

`PolicyTemplate` must define:

```php
public static function restore(array $row): self;
public function id(): int;
public function enabled(): bool;
public function items(): array;
public function policyRowsForApiKey(int $apiKeyId): array;
```

`policyRowsForApiKey()` returns rows compatible with `PolicyRepository::save()` and keeps decoded arrays under keys `billing_cycles`, `actions`, `locations`, `os_templates`, `delivery_mappings`, `sso_hosts`, `sso_allowed`, and `destroy_allowed`.

- [ ] **Step 5: Run domain tests and verify GREEN**

Run:

```powershell
php tests\run.php tests\unit\upstream\OnboardingApplicationTest.php tests\unit\upstream\PolicyTemplateTest.php
```

Expected: both tests PASS.

- [ ] **Step 6: Commit**

```powershell
git add modules\addons\peakrack_upstream_api\lib\Contracts modules\addons\peakrack_upstream_api\lib\Domain tests\unit\upstream\OnboardingApplicationTest.php tests\unit\upstream\PolicyTemplateTest.php
git commit -m "Add onboarding domain contracts"
```

## Task 3: Validate Applications and Check Eligibility

**Files:**
- Create: `modules/addons/peakrack_upstream_api/lib/Contracts/ClientAccountGateway.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Application/OnboardingValidator.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Application/EligibilityService.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Whmcs/CapsuleClientAccountGateway.php`
- Create: `tests/unit/upstream/OnboardingValidatorTest.php`
- Create: `tests/unit/upstream/EligibilityServiceTest.php`

- [ ] **Step 1: Write failing validation tests**

Create `tests/unit/upstream/OnboardingValidatorTest.php`:

```php
<?php

namespace PeakRack\Tests\Unit\Upstream;

use PeakRack\Tests\TestCase;
use PeakRack\UpstreamApi\Application\OnboardingValidator;

final class OnboardingValidatorTest extends TestCase
{
    public function testNormalizesValidApplicationInput(): void
    {
        $input = (new OnboardingValidator(requireOutboundIp: true))->validate([
            'brand_name' => ' Example Reseller ',
            'downstream_domain' => 'billing.example.test',
            'outbound_ips' => "192.0.2.10\n2001:db8::/32",
            'business_type' => 'VPS and hosting',
            'telegram' => 't.me/example_support',
            'qq' => '123456',
            'phone' => '+1 555-0100',
            'notes' => '<script>alert(1)</script>',
            'terms_accepted' => '1',
        ], 1780617600, '198.51.100.1');

        $this->assertSame('Example Reseller', $input['brand_name']);
        $this->assertSame('billing.example.test', $input['downstream_domain']);
        $this->assertSame('["192.0.2.10","2001:db8::/32"]', $input['outbound_ips_json']);
        $this->assertSame('@example_support', $input['telegram']);
        $this->assertSame('123456', $input['qq']);
        $this->assertSame('+1 555-0100', $input['phone']);
        $this->assertSame(1780617600, $input['terms_accepted_at']);
        $this->assertSame('198.51.100.1', $input['terms_accepted_ip']);
    }

    public function testRejectsSchemePathBadIpBadTelegramAndMissingTerms(): void
    {
        $validator = new OnboardingValidator(requireOutboundIp: true);

        $this->assertThrows(
            fn () => $validator->validate([
                'brand_name' => 'A',
                'downstream_domain' => 'https://billing.example.test/path',
                'outbound_ips' => 'not-an-ip',
                'business_type' => '',
                'telegram' => 'http://example.test',
                'terms_accepted' => '0',
            ], 1780617600, '198.51.100.1'),
            \InvalidArgumentException::class
        );
    }
}
```

- [ ] **Step 2: Write failing eligibility tests**

Create `tests/unit/upstream/EligibilityServiceTest.php`:

```php
<?php

namespace PeakRack\Tests\Unit\Upstream;

use PeakRack\Tests\TestCase;
use PeakRack\UpstreamApi\Application\EligibilityService;
use PeakRack\UpstreamApi\Contracts\ClientAccountGateway;

final class EligibilityServiceTest extends TestCase
{
    public function testEligibleClientHasNoBlockingReasons(): void
    {
        $gateway = new EligibilityFakeClientGateway([
            'status' => 'Active',
            'email_verified' => true,
            'group_id' => 3,
            'overdue_invoices' => 0,
        ]);

        $result = (new EligibilityService($gateway, [3, 4]))->check(44, false);
        $this->assertTrue($result['eligible']);
        $this->assertSame([], $result['reasons']);
    }

    public function testReturnsAllBlockingReasons(): void
    {
        $gateway = new EligibilityFakeClientGateway([
            'status' => 'Closed',
            'email_verified' => false,
            'group_id' => 2,
            'overdue_invoices' => 1,
        ]);

        $result = (new EligibilityService($gateway, [3]))->check(44, true);
        $this->assertFalse($result['eligible']);
        $this->assertSame([
            'Your account status must be Active.',
            'Your email address must be verified.',
            'Your account is not in an allowed reseller group.',
            'Your account has overdue invoices.',
            'Your account already has a pending or approved integration application.',
        ], $result['reasons']);
    }
}

final class EligibilityFakeClientGateway implements ClientAccountGateway
{
    public function __construct(private readonly array $row)
    {
    }

    public function clientSummary(int $clientId): array
    {
        return $this->row;
    }
}
```

- [ ] **Step 3: Run tests and verify RED**

Run:

```powershell
php tests\run.php tests\unit\upstream\OnboardingValidatorTest.php tests\unit\upstream\EligibilityServiceTest.php
```

Expected: FAIL because validator, service, and gateway contract do not exist.

- [ ] **Step 4: Implement validator APIs**

`OnboardingValidator` constructor and method:

```php
public function __construct(private readonly bool $requireOutboundIp)
{
}

public function validate(array $request, int $now, string $sourceIp): array
{
    return [
        'brand_name' => $this->boundedString($request, 'brand_name', 2, 120),
        'downstream_domain' => $this->domain($request['downstream_domain'] ?? ''),
        'outbound_ips_json' => json_encode($this->ipList((string) ($request['outbound_ips'] ?? '')), JSON_THROW_ON_ERROR),
        'business_type' => $this->boundedString($request, 'business_type', 2, 200),
        'telegram' => $this->telegram((string) ($request['telegram'] ?? '')),
        'qq' => $this->optionalPattern((string) ($request['qq'] ?? ''), '/^[0-9]{5,12}$/', 'The QQ contact is invalid.'),
        'phone' => $this->optionalPattern((string) ($request['phone'] ?? ''), '/^[+0-9 -]{1,32}$/', 'The contact phone is invalid.'),
        'notes' => mb_substr(trim((string) ($request['notes'] ?? '')), 0, 2000),
        'terms_accepted_at' => $this->termsAccepted($request['terms_accepted'] ?? null) ? $now : 0,
        'terms_accepted_ip' => $sourceIp,
    ];
}
```

Validation details:

- Reject domains where `parse_url()` finds a scheme, path, query, fragment, user, pass, or port.
- Accept only host labels with letters, digits, hyphen, and dots.
- Normalize `t.me/example_support` to `@example_support`.
- For IP/CIDR validation, use `inet_pton()` and prefix max `32` for IPv4, `128` for IPv6.
- Throw `InvalidArgumentException` with field-specific messages.

- [ ] **Step 5: Implement eligibility APIs**

`ClientAccountGateway`:

```php
interface ClientAccountGateway
{
    public function clientSummary(int $clientId): array;
}
```

`EligibilityService::check()`:

```php
public function check(int $clientId, bool $hasActiveApplication): array
{
    $client = $this->gateway->clientSummary($clientId);
    $reasons = [];

    if (($client['status'] ?? '') !== 'Active') {
        $reasons[] = 'Your account status must be Active.';
    }
    if (($client['email_verified'] ?? false) !== true) {
        $reasons[] = 'Your email address must be verified.';
    }
    if (!in_array((int) ($client['group_id'] ?? 0), $this->allowedClientGroupIds, true)) {
        $reasons[] = 'Your account is not in an allowed reseller group.';
    }
    if ((int) ($client['overdue_invoices'] ?? 0) > 0) {
        $reasons[] = 'Your account has overdue invoices.';
    }
    if ($hasActiveApplication) {
        $reasons[] = 'Your account already has a pending or approved integration application.';
    }

    return ['eligible' => $reasons === [], 'reasons' => $reasons];
}
```

`CapsuleClientAccountGateway` reads:

- `tblclients.status`
- `tblclients.email_verified` when present, otherwise WHMCS-compatible fallback to `tblclients.email_verified` value `1`
- `tblclients.groupid`
- overdue invoices from `tblinvoices` where `userid = clientId`, `status = 'Unpaid'`, and `duedate < date('Y-m-d')`

- [ ] **Step 6: Run tests and verify GREEN**

Run:

```powershell
php tests\run.php tests\unit\upstream\OnboardingValidatorTest.php tests\unit\upstream\EligibilityServiceTest.php
```

Expected: both test files PASS.

- [ ] **Step 7: Commit**

```powershell
git add modules\addons\peakrack_upstream_api\lib\Contracts\ClientAccountGateway.php modules\addons\peakrack_upstream_api\lib\Application\OnboardingValidator.php modules\addons\peakrack_upstream_api\lib\Application\EligibilityService.php modules\addons\peakrack_upstream_api\lib\Whmcs\CapsuleClientAccountGateway.php tests\unit\upstream\OnboardingValidatorTest.php tests\unit\upstream\EligibilityServiceTest.php
git commit -m "Add onboarding validation and eligibility checks"
```

## Task 4: Add Capsule Repositories and Policy Template Admin

**Files:**
- Create: `modules/addons/peakrack_upstream_api/lib/Database/CapsuleApplicationRepository.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Database/CapsulePolicyTemplateRepository.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Database/CapsuleAuditRepository.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Database/CapsuleSecretResetRepository.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Admin/OnboardingAdminController.php`
- Create: `modules/addons/peakrack_upstream_api/templates/policy-templates.tpl`
- Modify: `modules/addons/peakrack_upstream_api/lib/Admin/TemplateRenderer.php`
- Modify: `modules/addons/peakrack_upstream_api/peakrack_upstream_api.php`
- Create: `tests/unit/upstream/PolicyTemplateAdminTest.php`

- [ ] **Step 1: Write failing policy-template admin test**

Create `tests/unit/upstream/PolicyTemplateAdminTest.php`:

```php
<?php

namespace PeakRack\Tests\Unit\Upstream;

use PeakRack\Tests\TestCase;
use PeakRack\UpstreamApi\Admin\Csrf;
use PeakRack\UpstreamApi\Admin\OnboardingAdminController;

final class PolicyTemplateAdminTest extends TestCase
{
    public function testAdminCanSaveTemplateWithMultipleItems(): void
    {
        $templates = new PolicyTemplateFakeRepository();
        $controller = new OnboardingAdminController(
            applications: new ApplicationFakeRepository(),
            templates: $templates,
            audits: new AuditFakeRepository(),
            csrf: new Csrf(static fn (): string => 'csrf-token'),
            policyValidator: static function (array $policy): void {
                if ((int) $policy['product_id'] < 1) {
                    throw new \InvalidArgumentException('The product is invalid.');
                }
            },
            clock: static fn (): int => 1780617600
        );

        $session = [];
        $token = (new Csrf(static fn (): string => 'csrf-token'))->token($session);

        $view = $controller->dispatch([
            'page' => 'policy_templates',
            'action' => 'save_template',
            'csrf_token' => $token,
            'name' => 'Starter',
            'description' => 'Starter template',
            'enabled' => '1',
            'items_json' => json_encode([
                [
                    'product_id' => 19,
                    'billing_cycles' => 'monthly',
                    'actions' => 'create,renew',
                    'locations' => '',
                    'os_templates' => '',
                    'delivery_mappings' => '',
                    'sso_hosts' => '',
                    'sso_allowed' => '0',
                    'destroy_allowed' => '0',
                ],
                [
                    'product_id' => 20,
                    'billing_cycles' => 'monthly,annually',
                    'actions' => 'create,renew,suspend',
                    'locations' => '{}',
                    'os_templates' => '{}',
                    'delivery_mappings' => '{}',
                    'sso_hosts' => '',
                    'sso_allowed' => '0',
                    'destroy_allowed' => '0',
                ],
            ], JSON_THROW_ON_ERROR),
        ], 9, $session, 'addonmodules.php?module=test');

        $this->assertSame('Policy template saved.', $view['notice']);
        $this->assertSame('Starter', $templates->savedTemplate['name']);
        $this->assertSame(2, count($templates->savedItems));
        $this->assertSame(['monthly', 'annually'], $templates->savedItems[1]['billing_cycles']);
    }
}
```

Add fake repositories in the same file implementing the new contracts. The fake `saveTemplate()` should store `$template` and `$items`, then return `1`.

- [ ] **Step 2: Run test and verify RED**

Run:

```powershell
php tests\run.php tests\unit\upstream\PolicyTemplateAdminTest.php
```

Expected: FAIL because `OnboardingAdminController` and fake contract imports do not exist.

- [ ] **Step 3: Implement Capsule repositories**

Repository behavior:

- `CapsuleApplicationRepository::findActiveForClient()` returns the newest application where `client_id = ?` and status is `pending` or `approved`.
- `CapsulePolicyTemplateRepository::saveTemplate()` upserts the template and replaces item rows for that template inside one transaction.
- `CapsuleAuditRepository::append()` stores `sanitized_context_json` through `Redactor::redact()`.
- `CapsuleSecretResetRepository::countClientResetsInMonth()` filters `actor_type = 'client'`, `reset_at >= monthStart`, and `reset_at < monthEnd`.

Use this transaction shape for template saving:

```php
return (int) Capsule::connection()->transaction(function () use ($template, $items): int {
    $now = time();
    $template['updated_at'] = $now;
    if (isset($template['id']) && (int) $template['id'] > 0) {
        $id = (int) $template['id'];
        Capsule::table(Schema::POLICY_TEMPLATES)->where('id', $id)->update($template);
        Capsule::table(Schema::POLICY_TEMPLATE_ITEMS)->where('template_id', $id)->delete();
    } else {
        $template['created_at'] = $now;
        $id = (int) Capsule::table(Schema::POLICY_TEMPLATES)->insertGetId($template);
    }

    foreach ($items as $index => $item) {
        $item['template_id'] = $id;
        $item['sort_order'] = $item['sort_order'] ?? (($index + 1) * 10);
        $item['created_at'] = $now;
        $item['updated_at'] = $now;
        Capsule::table(Schema::POLICY_TEMPLATE_ITEMS)->insert($item);
    }

    return $id;
});
```

- [ ] **Step 4: Implement policy-template admin controller**

`OnboardingAdminController::dispatch()` accepts:

```php
public function dispatch(array $request, ?int $adminId, array &$session, string $moduleLink): array;
```

Required actions:

- `save_template`
- `disable_template`

`save_template` parses `items_json` as a JSON array of item objects. For every item, reuse the same normalization rules as `AdminController::savePolicy()` by extracting the shared list/JSON helper logic into a small reusable class if needed:

```php
$item = [
    'product_id' => $this->positiveInt($raw, 'product_id'),
    'billing_cycles' => $this->stringList($raw, 'billing_cycles'),
    'actions' => $this->stringList($raw, 'actions'),
    'locations' => $this->jsonArray($raw, 'locations', true),
    'os_templates' => $this->jsonArray($raw, 'os_templates', true),
    'delivery_mappings' => $this->jsonArray($raw, 'delivery_mappings', true),
    'sso_hosts' => $this->stringList($raw, 'sso_hosts'),
    'sso_allowed' => $this->boolean($raw['sso_allowed'] ?? null) ? 1 : 0,
    'destroy_allowed' => $this->boolean($raw['destroy_allowed'] ?? null) ? 1 : 0,
];
```

Run `$policyValidator($item)` for every template item using a temporary policy array. This keeps template validation consistent with direct Product Policy validation.

- [ ] **Step 5: Add template page rendering**

Add `policy_templates` to `TemplateRenderer` navigation and render `policy-templates.tpl`.

The template must include:

```html
<div class="panel panel-default">
    <div class="panel-heading"><strong>PeakRack Upstream Policy Templates</strong></div>
    <div class="panel-body">{{navigation}}{{notice}}{{forms}}{{content}}</div>
</div>
```

The form can accept `items_json` as a textarea in v1.1 to avoid building a large dynamic JavaScript editor in the first implementation. The textarea label must explain that one JSON array item becomes one product policy row.

- [ ] **Step 6: Run admin template tests and verify GREEN**

Run:

```powershell
php tests\run.php tests\unit\upstream\PolicyTemplateAdminTest.php tests\unit\upstream\AdminControllerTest.php
```

Expected: all listed tests PASS.

- [ ] **Step 7: Commit**

```powershell
git add modules\addons\peakrack_upstream_api\lib\Database modules\addons\peakrack_upstream_api\lib\Admin modules\addons\peakrack_upstream_api\templates modules\addons\peakrack_upstream_api\peakrack_upstream_api.php tests\unit\upstream\PolicyTemplateAdminTest.php tests\unit\upstream\AdminControllerTest.php
git commit -m "Add policy template admin workflow"
```

## Task 5: Implement Application Approval and Credential Reset Services

**Files:**
- Create: `modules/addons/peakrack_upstream_api/lib/Application/OnboardingService.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Application/CredentialService.php`
- Modify: `modules/addons/peakrack_upstream_api/lib/Admin/OnboardingAdminController.php`
- Create: `modules/addons/peakrack_upstream_api/templates/onboarding-applications.tpl`
- Create: `tests/unit/upstream/OnboardingServiceTest.php`
- Create: `tests/unit/upstream/CredentialServiceTest.php`

- [ ] **Step 1: Write failing approval workflow test**

Create `tests/unit/upstream/OnboardingServiceTest.php`:

```php
<?php

namespace PeakRack\Tests\Unit\Upstream;

use PeakRack\Tests\TestCase;
use PeakRack\UpstreamApi\Application\OnboardingService;
use PeakRack\UpstreamApi\Domain\OnboardingApplication;

final class OnboardingServiceTest extends TestCase
{
    public function testApprovalCreatesKeyAppliesTemplateAndMarksSecretPending(): void
    {
        $applications = new ApplicationFakeRepository();
        $applications->rows[5] = OnboardingApplication::restore([
            'id' => 5,
            'client_id' => 44,
            'status' => OnboardingApplication::PENDING,
            'brand_name' => 'Example',
            'downstream_domain' => 'billing.example.test',
            'outbound_ips_json' => '["192.0.2.10"]',
            'business_type' => 'VPS',
            'telegram' => '@example',
            'secret_pending_display' => 0,
        ]);
        $keys = new OnboardingFakeKeys();
        $policies = new OnboardingFakePolicies();
        $templates = new PolicyTemplateFakeRepository();
        $templates->template = $this->starterTemplate();
        $audits = new AuditFakeRepository();

        $service = new OnboardingService(
            applications: $applications,
            keys: $keys,
            policies: $policies,
            templates: $templates,
            audits: $audits,
            encryptSecret: static fn (string $secret): string => 'encrypted:' . $secret,
            publicKeyGenerator: static fn (): string => 'prk_test',
            secretGenerator: static fn (): string => 'prs_secret',
            instanceIdGenerator: static fn (): string => '123e4567-e89b-42d3-a456-426614174000',
            defaultRateLimit: 120,
            clock: static fn (): int => 1780617600
        );

        $result = $service->approve(5, 3, 9);

        $this->assertSame('prk_test', $keys->rows[1]['public_key']);
        $this->assertSame('encrypted:prs_secret', $keys->rows[1]['encrypted_secret']);
        $this->assertSame('["192.0.2.10"]', $keys->rows[1]['ip_allowlist_json']);
        $this->assertSame(2, count($policies->saved));
        $this->assertTrue($applications->rows[5]->secretPendingDisplay());
        $this->assertSame('prs_secret', $result['secret']);
        $this->assertSame('application_approved', $audits->events[0]['event_type']);
    }
}
```

The helper `starterTemplate()` returns the same two-item `PolicyTemplate` from `PolicyTemplateTest`.

- [ ] **Step 2: Write failing credential reset test**

Create `tests/unit/upstream/CredentialServiceTest.php`:

```php
<?php

namespace PeakRack\Tests\Unit\Upstream;

use PeakRack\Tests\TestCase;
use PeakRack\UpstreamApi\Application\CredentialService;
use PeakRack\UpstreamApi\Domain\OnboardingApplication;

final class CredentialServiceTest extends TestCase
{
    public function testClientResetEnforcesMonthlyLimitAndTenDayInterval(): void
    {
        $applications = new ApplicationFakeRepository();
        $applications->rows[5] = OnboardingApplication::restore([
            'id' => 5,
            'client_id' => 44,
            'status' => OnboardingApplication::APPROVED,
            'brand_name' => 'Example',
            'downstream_domain' => 'billing.example.test',
            'outbound_ips_json' => '["192.0.2.10"]',
            'business_type' => 'VPS',
            'telegram' => '@example',
            'api_key_id' => 8,
            'template_id' => 3,
            'secret_pending_display' => 0,
        ]);
        $keys = new OnboardingFakeKeys();
        $keys->rows[8] = ['id' => 8, 'client_id' => 44, 'encrypted_secret' => 'old'];
        $resets = new SecretResetFakeRepository(latestClientResetAt: 1780272000, monthlyCount: 1);
        $audits = new AuditFakeRepository();

        $service = new CredentialService(
            applications: $applications,
            keys: $keys,
            resets: $resets,
            audits: $audits,
            encryptSecret: static fn (string $secret): string => 'encrypted:' . $secret,
            decryptSecret: static fn (string $secret): string => str_replace('encrypted:', '', $secret),
            secretGenerator: static fn (): string => 'prs_new',
            clock: static fn (): int => 1780617600
        );

        $this->assertThrows(
            fn () => $service->resetByClient(44, '198.51.100.1'),
            \InvalidArgumentException::class,
            'You can reset the API Secret again after'
        );

        $resets->latestClientResetAt = 1779667200;
        $result = $service->resetByClient(44, '198.51.100.1');

        $this->assertSame('prs_new', $result['secret']);
        $this->assertSame('encrypted:prs_new', $keys->rows[8]['encrypted_secret']);
        $this->assertSame(1, count($resets->recorded));
        $this->assertTrue($applications->rows[5]->secretPendingDisplay());
    }

    public function testAdminResetBypassesClientLimits(): void
    {
        $applications = new ApplicationFakeRepository();
        $applications->rows[5] = OnboardingApplication::restore([
            'id' => 5,
            'client_id' => 44,
            'status' => OnboardingApplication::APPROVED,
            'brand_name' => 'Example',
            'downstream_domain' => 'billing.example.test',
            'outbound_ips_json' => '["192.0.2.10"]',
            'business_type' => 'VPS',
            'telegram' => '@example',
            'api_key_id' => 8,
            'template_id' => 3,
            'secret_pending_display' => 0,
        ]);
        $keys = new OnboardingFakeKeys();
        $keys->rows[8] = ['id' => 8, 'client_id' => 44, 'encrypted_secret' => 'old'];
        $resets = new SecretResetFakeRepository(latestClientResetAt: 1780617500, monthlyCount: 3);

        $service = new CredentialService(
            applications: $applications,
            keys: $keys,
            resets: $resets,
            audits: new AuditFakeRepository(),
            encryptSecret: static fn (string $secret): string => 'encrypted:' . $secret,
            decryptSecret: static fn (string $secret): string => str_replace('encrypted:', '', $secret),
            secretGenerator: static fn (): string => 'prs_admin',
            clock: static fn (): int => 1780617600
        );

        $result = $service->resetByAdmin(applicationId: 5, adminId: 9, sourceIp: '203.0.113.9');
        $this->assertSame('prs_admin', $result['secret']);
        $this->assertSame(1, $resets->recorded[0]['bypassed_limits']);
    }
}
```

- [ ] **Step 3: Run service tests and verify RED**

Run:

```powershell
php tests\run.php tests\unit\upstream\OnboardingServiceTest.php tests\unit\upstream\CredentialServiceTest.php
```

Expected: FAIL because services do not exist.

- [ ] **Step 4: Implement `OnboardingService`**

Public methods:

```php
public function submit(int $clientId, array $validatedInput): int;
public function approve(int $applicationId, int $templateId, int $adminId): array;
public function reject(int $applicationId, string $message, int $adminId): void;
```

Approval rules:

- Load application with `findPending()`.
- Load enabled `PolicyTemplate`.
- Create API key with:

```php
[
    'public_key' => $publicKey,
    'encrypted_secret' => $encryptedSecret,
    'client_id' => $application->clientId(),
    'instance_id' => $instanceId,
    'enabled' => 1,
    'ip_allowlist_json' => $application->outboundIpsJson(),
    'rate_limit_per_minute' => $this->defaultRateLimit,
    'rate_window_count' => 0,
]
```

- Apply every template row through `PolicyRepository::save()`.
- Save approved application with `api_key_id`, `template_id`, `admin_id`, `reviewed_at`, and `secret_pending_display = 1`.
- Write audit events `application_approved`, `api_key_created_from_application`, and `policy_template_applied`.
- Return `['api_key_id' => $apiKeyId, 'public_key' => $publicKey, 'secret' => $secret]`.

If any write after key creation fails in pure tests, throw and leave fake repositories unchanged by applying changes in memory first. In Capsule repositories, wrap approval in a transaction in the factory or service when all repositories use Capsule.

- [ ] **Step 5: Implement `CredentialService`**

Public methods:

```php
public function displayPendingSecretForClient(int $clientId): ?array;
public function confirmSecretDisplayed(int $clientId): void;
public function resetByClient(int $clientId, string $sourceIp): array;
public function resetByAdmin(int $applicationId, int $adminId, string $sourceIp): array;
```

Limit logic:

```php
$monthStart = strtotime(date('Y-m-01 00:00:00', $now));
$monthEnd = strtotime('+1 month', $monthStart);
if ($this->resets->countClientResetsInMonth($apiKeyId, $clientId, $monthStart, $monthEnd) >= 3) {
    throw new \InvalidArgumentException('You have reached the 3 API Secret resets allowed for this month.');
}
$latest = $this->resets->latestClientResetAt($apiKeyId, $clientId);
if ($latest !== null && $latest + 864000 > $now) {
    throw new \InvalidArgumentException('You can reset the API Secret again after ' . date('Y-m-d H:i:s', $latest + 864000) . '.');
}
```

Reset records:

```php
[
    'api_key_id' => $apiKeyId,
    'client_id' => $clientId,
    'actor_type' => 'client',
    'actor_id' => $clientId,
    'reset_at' => $now,
    'bypassed_limits' => 0,
    'source_ip' => $sourceIp,
    'created_at' => $now,
]
```

Admin reset uses `actor_type = 'admin'`, `actor_id = $adminId`, and `bypassed_limits = 1`.

- [ ] **Step 6: Run service tests and verify GREEN**

Run:

```powershell
php tests\run.php tests\unit\upstream\OnboardingServiceTest.php tests\unit\upstream\CredentialServiceTest.php
```

Expected: both test files PASS.

- [ ] **Step 7: Add admin applications page**

Add `onboarding_applications` navigation and template with:

- Status filter.
- Application table.
- Approval form requiring `application_id` and `template_id`.
- Rejection form requiring `application_id` and `admin_message`.
- Admin secret reset form requiring `application_id`.

Admin reset must not print the secret to the admin page. It should set `secret_pending_display = 1` and show the notice: `API Secret reset. The client can view it once in the Client Area.`

- [ ] **Step 8: Commit**

```powershell
git add modules\addons\peakrack_upstream_api\lib\Application modules\addons\peakrack_upstream_api\lib\Admin modules\addons\peakrack_upstream_api\templates tests\unit\upstream\OnboardingServiceTest.php tests\unit\upstream\CredentialServiceTest.php
git commit -m "Add onboarding approval and credential reset services"
```

## Task 6: Add Client Area Application and Setup Guide

**Files:**
- Create: `modules/addons/peakrack_upstream_api/lib/Application/SetupGuideService.php`
- Create: `modules/addons/peakrack_upstream_api/lib/ClientArea/OnboardingClientAreaController.php`
- Create: `modules/addons/peakrack_upstream_api/templates/clientarea-onboarding.tpl`
- Modify: `modules/addons/peakrack_upstream_api/peakrack_upstream_api.php`
- Create: `tests/unit/upstream/SetupGuideServiceTest.php`
- Create: `tests/unit/upstream/ClientAreaOnboardingTest.php`

- [ ] **Step 1: Write failing setup guide test**

Create `tests/unit/upstream/SetupGuideServiceTest.php`:

```php
<?php

namespace PeakRack\Tests\Unit\Upstream;

use PeakRack\Tests\TestCase;
use PeakRack\UpstreamApi\Application\SetupGuideService;

final class SetupGuideServiceTest extends TestCase
{
    public function testBuildsGuideWithoutAssumingCpanelOrServerUsername(): void
    {
        $guide = (new SetupGuideService())->build([
            'download_url' => 'https://example.test/downloads/peakrackupstream.zip',
            'api_base_url' => 'https://www.peakrack.com/modules/addons/peakrack_upstream_api/api/v1',
            'public_key' => 'prk_public',
            'secret' => 'prs_secret',
            'downstream_domain' => 'billing.example.test',
        ]);

        $this->assertStringContains('peakrackupstream', $guide['module_name']);
        $this->assertStringContains('https://www.peakrack.com/modules/addons/peakrack_upstream_api/api/v1/health', $guide['health_url']);
        $this->assertStringContains('/modules/servers/peakrackupstream/cron/sync.php', $guide['cron_example']);
        $this->assertStringNotContains('/home/', $guide['cron_example']);
        $this->assertSame('prs_secret', $guide['secret']);
    }
}
```

- [ ] **Step 2: Write failing Client Area controller test**

Create `tests/unit/upstream/ClientAreaOnboardingTest.php`:

```php
<?php

namespace PeakRack\Tests\Unit\Upstream;

use PeakRack\Tests\TestCase;
use PeakRack\UpstreamApi\ClientArea\OnboardingClientAreaController;

final class ClientAreaOnboardingTest extends TestCase
{
    public function testEligibleClientCanSubmitApplicationWithCsrf(): void
    {
        $applications = new ApplicationFakeRepository();
        $controller = ClientAreaFactory::controller(applications: $applications);
        $session = ['uid' => 44, 'peakrack_upstream_api_client_csrf' => 'csrf-token'];

        $view = $controller->dispatch([
            'action' => 'submit_application',
            'csrf_token' => 'csrf-token',
            'brand_name' => 'Example',
            'downstream_domain' => 'billing.example.test',
            'outbound_ips' => '192.0.2.10',
            'business_type' => 'VPS',
            'telegram' => '@example',
            'terms_accepted' => '1',
        ], $session, '198.51.100.1');

        $this->assertSame('pending', $view['state']);
        $this->assertSame(44, $applications->created[0]['client_id']);
    }

    public function testClientCannotViewAnotherClientsApprovedApplication(): void
    {
        $applications = new ApplicationFakeRepository();
        $applications->approvedClientId = 99;
        $controller = ClientAreaFactory::controller(applications: $applications);
        $session = ['uid' => 44, 'peakrack_upstream_api_client_csrf' => 'csrf-token'];

        $view = $controller->dispatch([], $session, '198.51.100.1');
        $this->assertSame('empty', $view['state']);
    }
}
```

`ClientAreaFactory::controller()` is a test helper in the same file that injects fake eligibility, onboarding, credential, and setup guide services.

- [ ] **Step 3: Run Client Area tests and verify RED**

Run:

```powershell
php tests\run.php tests\unit\upstream\SetupGuideServiceTest.php tests\unit\upstream\ClientAreaOnboardingTest.php
```

Expected: FAIL because Client Area classes do not exist.

- [ ] **Step 4: Implement setup guide**

`SetupGuideService::build()` returns:

```php
[
    'download_url' => $downloadUrl,
    'module_name' => 'peakrackupstream',
    'api_base_url' => $apiBaseUrl,
    'health_url' => rtrim($apiBaseUrl, '/') . '/health',
    'public_key' => $publicKey,
    'secret' => $secret,
    'cron_example' => '*/5 * * * * /path/to/php -q /path/to/whmcs/modules/servers/peakrackupstream/cron/sync.php >/dev/null 2>&1',
    'server_module_label' => 'PeakRack Upstream',
    'downstream_domain' => $downstreamDomain,
]
```

Use `/path/to/php` and `/path/to/whmcs` placeholders in displayed examples because the actual server path differs between aaPanel, cPanel, DirectAdmin, and manual deployments.

- [ ] **Step 5: Implement Client Area controller**

Public method:

```php
public function dispatch(array $request, array &$session, string $sourceIp): array;
```

States:

- `login_required`
- `ineligible`
- `empty`
- `pending`
- `rejected`
- `approved`

Actions:

- `submit_application`
- `confirm_secret_displayed`
- `reset_secret`

Ownership rule:

```php
$clientId = (int) ($session['uid'] ?? 0);
if ($clientId < 1) {
    return ['state' => 'login_required', 'requirelogin' => true];
}
```

The controller must only call repository methods that filter by this `$clientId`. It must not accept `client_id`, `application_id`, or `api_key_id` from Client Area requests.

- [ ] **Step 6: Add WHMCS Addon Client Area function**

Add to `peakrack_upstream_api.php`:

```php
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
```

Create `peakrack_upstream_api_clientarea_controller(array $vars): OnboardingClientAreaController` to wire Capsule repositories, config fields, encryption/decryption helpers, generators, and services.

- [ ] **Step 7: Add Client Area template**

`clientarea-onboarding.tpl` must be Smarty-compatible and theme-neutral:

```smarty
{if $state eq "ineligible"}
    <div class="alert alert-warning">
        <ul>
            {foreach from=$reasons item=reason}<li>{$reason|escape}</li>{/foreach}
        </ul>
    </div>
{elseif $state eq "empty"}
    <form method="post">
        <input type="hidden" name="action" value="submit_application">
        <input type="hidden" name="csrf_token" value="{$csrf_token|escape}">
        <div class="form-group"><label>Company / Brand Name</label><input class="form-control" name="brand_name" value=""></div>
        <div class="form-group"><label>Downstream WHMCS Domain</label><input class="form-control" name="downstream_domain" value=""></div>
        <div class="form-group"><label>Downstream Server Outbound IPs</label><textarea class="form-control" name="outbound_ips"></textarea></div>
        <div class="form-group"><label>Business Type</label><input class="form-control" name="business_type" value=""></div>
        <div class="form-group"><label>Telegram</label><input class="form-control" name="telegram" value=""></div>
        <div class="form-group"><label>QQ</label><input class="form-control" name="qq" value=""></div>
        <div class="form-group"><label>Contact Phone</label><input class="form-control" name="phone" value=""></div>
        <div class="form-group"><label>Notes</label><textarea class="form-control" name="notes"></textarea></div>
        <label><input type="checkbox" name="terms_accepted" value="1"> I agree to the integration terms.</label>
        <button type="submit" class="btn btn-primary">Submit Application</button>
    </form>
{elseif $state eq "approved"}
    <div class="panel panel-default">
        <div class="panel-heading">Integration Configuration</div>
        <div class="panel-body">
            <p><strong>API Base URL:</strong> <code>{$guide.api_base_url|escape}</code></p>
            <p><strong>Public Key:</strong> <code>{$guide.public_key|escape}</code></p>
            {if $guide.secret}
                <div class="alert alert-warning"><strong>API Secret:</strong> <code>{$guide.secret|escape}</code></div>
            {/if}
            <p><a class="btn btn-default" href="{$guide.download_url|escape}">Download Downstream Module</a></p>
            <pre>{$guide.cron_example|escape}</pre>
        </div>
    </div>
{/if}
```

Do not hardcode Lagom-specific markup.

- [ ] **Step 8: Run Client Area tests and verify GREEN**

Run:

```powershell
php tests\run.php tests\unit\upstream\SetupGuideServiceTest.php tests\unit\upstream\ClientAreaOnboardingTest.php
```

Expected: both test files PASS.

- [ ] **Step 9: Commit**

```powershell
git add modules\addons\peakrack_upstream_api\lib\Application\SetupGuideService.php modules\addons\peakrack_upstream_api\lib\ClientArea modules\addons\peakrack_upstream_api\templates\clientarea-onboarding.tpl modules\addons\peakrack_upstream_api\peakrack_upstream_api.php tests\unit\upstream\SetupGuideServiceTest.php tests\unit\upstream\ClientAreaOnboardingTest.php
git commit -m "Add client area onboarding flow"
```

## Task 7: Wire Configuration, Language, Documentation, and Release Checks

**Files:**
- Modify: `VERSION`
- Modify: `modules/addons/peakrack_upstream_api/peakrack_upstream_api.php`
- Modify: `modules/addons/peakrack_upstream_api/lang/english.php`
- Modify: `modules/addons/peakrack_upstream_api/lang/chinese.php`
- Modify: `README.md`
- Modify: `README.zh-CN.md`
- Modify: `CHANGELOG.md`
- Modify: `UPGRADE.md`
- Modify: `UPGRADE.zh-CN.md`
- Modify: `tests/unit/DocumentationTest.php`
- Modify: `tests/unit/RepositoryStructureTest.php`

- [ ] **Step 1: Write failing configuration and docs tests**

Extend `tests/unit/RepositoryStructureTest.php`:

```php
public function testVersionIsV110AfterOnboardingRelease(): void
{
    $this->assertSame('1.1.0', trim(file_get_contents(ROOT . '/VERSION')));
}
```

Extend `tests/unit/DocumentationTest.php`:

```php
public function testDocumentationMentionsOnboardingAsImplementedAfterRelease(): void
{
    $readme = file_get_contents(ROOT . '/README.md');
    $this->assertTrue(is_string($readme));
    $this->assertStringContains('Client Area onboarding', $readme);
    $this->assertStringContains('manual administrator approval', $readme);
    $this->assertStringContains('API Secret is displayed once', $readme);

    $upgrade = file_get_contents(ROOT . '/UPGRADE.md');
    $this->assertTrue(is_string($upgrade));
    $this->assertStringContains('Allowed Client Group IDs', $upgrade);
    $this->assertStringContains('Downstream Module Download URL', $upgrade);
}
```

- [ ] **Step 2: Run docs tests and verify RED**

Run:

```powershell
php tests\run.php tests\unit\RepositoryStructureTest.php tests\unit\DocumentationTest.php
```

Expected: FAIL until version and docs are updated.

- [ ] **Step 3: Add Addon config fields**

In `peakrack_upstream_api_config()`, add:

```php
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
```

- [ ] **Step 4: Add language keys**

Add English keys:

```php
$_ADDONLANG['onboarding_applications'] = 'Onboarding Applications';
$_ADDONLANG['policy_templates'] = 'Policy Templates';
$_ADDONLANG['clientarea_title'] = 'PeakRack Upstream Integration';
$_ADDONLANG['application_submitted'] = 'Integration application submitted.';
$_ADDONLANG['application_approved'] = 'Integration application approved.';
$_ADDONLANG['application_rejected'] = 'Integration application rejected.';
$_ADDONLANG['secret_reset_client_limit'] = 'You have reached the API Secret reset limit.';
```

Add Chinese keys:

```php
$_ADDONLANG['onboarding_applications'] = '对接申请';
$_ADDONLANG['policy_templates'] = '策略模板';
$_ADDONLANG['clientarea_title'] = 'PeakRack Upstream 对接';
$_ADDONLANG['application_submitted'] = '对接申请已提交。';
$_ADDONLANG['application_approved'] = '对接申请已批准。';
$_ADDONLANG['application_rejected'] = '对接申请已拒绝。';
$_ADDONLANG['secret_reset_client_limit'] = '已达到 API Secret 重置限制。';
```

- [ ] **Step 5: Update documentation after code exists**

README additions must state:

- Onboarding is optional.
- Manual API key creation still exists.
- Client self-service applications require configured allowed client groups.
- Administrators approve applications with one policy template.
- API Secret is displayed once; resets are limited to 3 per natural month and 10 days between client resets.
- Admin reset bypasses client limits and writes an audit record.

UPGRADE additions must state:

- Schema changes are additive.
- Deactivation preserves tables.
- Configure `Allowed Client Group IDs` before enabling Client Area onboarding.
- Configure `Downstream Module Download URL`.
- Existing v1 API keys and policies continue to work.

- [ ] **Step 6: Set release version**

Set `VERSION` to:

```text
1.1.0
```

Set `Config::VERSION` to `1.1.0` if the current code stores a separate version constant.

- [ ] **Step 7: Run unit test suite**

Run:

```powershell
php tests\run.php
```

Expected: all unit tests PASS, with WHMCS integration tests skipped unless `PEAKRACK_WHMCS_TEST=1`.

- [ ] **Step 8: Run PHP lint**

Run:

```powershell
Get-ChildItem -Path modules,tests -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
```

Expected: every file reports `No syntax errors detected`.

- [ ] **Step 9: Run release check script**

Run:

```powershell
powershell -ExecutionPolicy Bypass -File scripts\check-release.ps1
```

Expected: script exits `0` and reports no secret, structure, lint, or documentation failures.

- [ ] **Step 10: Commit**

```powershell
git add VERSION README.md README.zh-CN.md CHANGELOG.md UPGRADE.md UPGRADE.zh-CN.md modules\addons\peakrack_upstream_api tests\unit
git commit -m "Document and configure v1.1 onboarding"
```

## Task 8: Final Verification and PR Update

**Files:**
- No source edits unless verification exposes a failure.

- [ ] **Step 1: Confirm worktree status**

Run:

```powershell
git status --short --branch
```

Expected: clean `develop/v1.1` branch after all task commits.

- [ ] **Step 2: Run complete local checks**

Run:

```powershell
php tests\run.php
Get-ChildItem -Path modules,tests -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
powershell -ExecutionPolicy Bypass -File scripts\check-release.ps1
```

Expected: tests pass, PHP lint passes, release check exits `0`.

- [ ] **Step 3: Push branch**

Run:

```powershell
git push origin develop/v1.1
```

Expected: branch pushes successfully.

- [ ] **Step 4: Open or update draft PR**

Create a draft PR from `develop/v1.1` to `main` after v1.0 is merged, or target the active integration branch if `main` still lacks v1.0. PR body must include:

```markdown
## Summary
- Adds Client Area onboarding applications with mandatory admin approval.
- Adds reusable policy templates that can create multiple product policies per approved API key.
- Adds one-time API Secret display and reset limits.
- Adds setup-guide data for downstream module download and configuration.

## Verification
- php tests\run.php
- php -l over modules and tests
- scripts\check-release.ps1
```

- [ ] **Step 5: Wait for CI**

Expected: GitHub Actions passes for PHP 8.2 and PHP 8.3.

## Spec Coverage Checklist

- Client Area self-service application: Tasks 3 and 6.
- Mandatory admin approval: Task 5.
- Policy template selected during approval: Tasks 4 and 5.
- One template creates multiple policy rows: Tasks 2, 4, and 5.
- API Secret displayed once: Tasks 5 and 6.
- Client reset max 3 per natural month: Task 5.
- Client reset minimum interval 10 days: Task 5.
- Admin reset bypass with audit: Task 5.
- Eligibility: verified email, no overdue invoices, active status, allowed group: Task 3.
- Download and setup guide: Task 6.
- Addon config: Task 7.
- Backward compatibility with v1 API keys and Product Policies: Tasks 4, 5, and 7.
- Additive schema and deactivation preservation: Tasks 1 and 7.
- Documentation and release checks: Tasks 7 and 8.
