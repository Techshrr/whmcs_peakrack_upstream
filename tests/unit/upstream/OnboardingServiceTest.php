<?php

namespace PeakRack\Tests\Unit\Upstream;

use PeakRack\Tests\TestCase;
use PeakRack\UpstreamApi\Admin\Csrf;
use PeakRack\UpstreamApi\Admin\OnboardingAdminController;
use PeakRack\UpstreamApi\Application\CredentialService;
use PeakRack\UpstreamApi\Application\OnboardingService;
use PeakRack\UpstreamApi\Contracts\ApiKeyRepository;
use PeakRack\UpstreamApi\Contracts\ApplicationRepository;
use PeakRack\UpstreamApi\Contracts\AuditRepository;
use PeakRack\UpstreamApi\Contracts\PolicyRepository;
use PeakRack\UpstreamApi\Contracts\PolicyTemplateRepository;
use PeakRack\UpstreamApi\Contracts\SecretResetRepository;
use PeakRack\UpstreamApi\Domain\OnboardingApplication;
use PeakRack\UpstreamApi\Domain\PolicyTemplate;
use PeakRack\UpstreamApi\Domain\ProductPolicy;

final class OnboardingServiceTest extends TestCase
{
    public function testApprovalCreatesKeyAppliesTemplateAndMarksSecretPending(): void
    {
        $applications = new OnboardingServiceFakeApplications();
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
        $keys = new OnboardingServiceFakeKeys();
        $policies = new OnboardingServiceFakePolicies();
        $templates = new OnboardingServiceFakeTemplates($this->starterTemplate());
        $audits = new OnboardingServiceFakeAudits();

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

    public function testAdminControllerApprovalAndResetNeverExposeSecret(): void
    {
        $applications = new OnboardingServiceFakeApplications();
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
        $keys = new OnboardingServiceFakeKeys();
        $policies = new OnboardingServiceFakePolicies();
        $templates = new OnboardingServiceFakeTemplates($this->starterTemplate());
        $audits = new OnboardingServiceFakeAudits();
        $clock = static fn (): int => 1780617600;
        $controller = new OnboardingAdminController(
            applications: $applications,
            templates: $templates,
            audits: $audits,
            csrf: new Csrf(static fn (): string => 'csrf-token'),
            policyValidator: static fn (array $policy): null => null,
            clock: $clock,
            onboarding: new OnboardingService(
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
                clock: $clock
            ),
            credentials: new CredentialService(
                applications: $applications,
                keys: $keys,
                resets: new OnboardingServiceFakeResets(),
                audits: $audits,
                encryptSecret: static fn (string $secret): string => 'encrypted:' . $secret,
                decryptSecret: static fn (string $secret): string => str_replace('encrypted:', '', $secret),
                secretGenerator: static fn (): string => 'prs_admin',
                clock: $clock
            )
        );
        $session = [];
        $token = (new Csrf(static fn (): string => 'csrf-token'))->token($session);

        $approved = $controller->dispatch([
            'page' => 'onboarding_applications',
            'action' => 'approve_application',
            'csrf_token' => $token,
            'application_id' => 5,
            'template_id' => 3,
        ], 9, $session, 'addonmodules.php?module=test');
        $this->assertStringNotContains('prs_secret', (string) $approved['notice']);
        $this->assertSame(null, $approved['secret_once']);

        $reset = $controller->dispatch([
            'page' => 'onboarding_applications',
            'action' => 'reset_application_secret',
            'csrf_token' => $token,
            'application_id' => 5,
        ], 9, $session, 'addonmodules.php?module=test');
        $this->assertStringNotContains('prs_admin', (string) $reset['notice']);
        $this->assertSame(null, $reset['secret_once']);
    }

    private function starterTemplate(): PolicyTemplate
    {
        return PolicyTemplate::restore([
            'id' => 3,
            'name' => 'Starter',
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
                ],
            ],
        ]);
    }
}

final class OnboardingServiceFakeApplications implements ApplicationRepository
{
    public array $created = [];
    public array $rows = [];

    public function create(array $attributes): int
    {
        $id = count($this->created) + 1;
        $attributes['id'] = $id;
        $this->created[] = $attributes;
        return $id;
    }

    public function find(int $id): ?OnboardingApplication
    {
        return $this->rows[$id] ?? null;
    }

    public function findActiveForClient(int $clientId): ?OnboardingApplication
    {
        foreach ($this->rows as $row) {
            if ($row->clientId() === $clientId && in_array($row->status(), [OnboardingApplication::PENDING, OnboardingApplication::APPROVED], true)) {
                return $row;
            }
        }

        return null;
    }

    public function findApprovedForClient(int $clientId): ?OnboardingApplication
    {
        foreach ($this->rows as $row) {
            if ($row->clientId() === $clientId && $row->status() === OnboardingApplication::APPROVED) {
                return $row;
            }
        }

        return null;
    }

    public function findPending(int $id): ?OnboardingApplication
    {
        $application = $this->rows[$id] ?? null;
        return $application instanceof OnboardingApplication && $application->status() === OnboardingApplication::PENDING
            ? $application
            : null;
    }

    public function save(OnboardingApplication $application): void
    {
        $this->rows[$application->id()] = $application;
    }

    public function listByStatus(?string $status, int $limit = 100): array
    {
        return [];
    }
}

final class OnboardingServiceFakeKeys implements ApiKeyRepository
{
    public array $rows = [];

    public function find(int $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    public function findEnabledByPublicKey(string $publicKey): ?array
    {
        return null;
    }

    public function all(): array
    {
        return array_values($this->rows);
    }

    public function create(array $attributes): int
    {
        $id = count($this->rows) + 1;
        $attributes['id'] = $id;
        $this->rows[$id] = $attributes;
        return $id;
    }

    public function updateEncryptedSecret(int $id, string $encryptedSecret): void
    {
        $this->rows[$id]['encrypted_secret'] = $encryptedSecret;
    }

    public function updateUsage(int $id, string $sourceIp, int $usedAt): void
    {
    }

    public function consumeRateLimit(int $id, int $now, int $windowSeconds): bool
    {
        return true;
    }

    public function hasServices(int $id): bool
    {
        return false;
    }

    public function delete(int $id): void
    {
    }
}

final class OnboardingServiceFakePolicies implements PolicyRepository
{
    public array $saved = [];

    public function findForProduct(int $apiKeyId, int $productId): ?ProductPolicy
    {
        return null;
    }

    public function catalogForApiKey(int $apiKeyId): array
    {
        return [];
    }

    public function save(array $attributes): int
    {
        $this->saved[] = $attributes;
        return count($this->saved);
    }

    public function delete(int $id): void
    {
    }
}

final class OnboardingServiceFakeTemplates implements PolicyTemplateRepository
{
    public function __construct(private readonly PolicyTemplate $template)
    {
    }

    public function saveTemplate(array $template, array $items): int
    {
        return 1;
    }

    public function find(int $id): ?PolicyTemplate
    {
        return $id === $this->template->id() ? $this->template : null;
    }

    public function listTemplates(bool $enabledOnly = false): array
    {
        return [];
    }

    public function deleteTemplate(int $id): void
    {
    }
}

final class OnboardingServiceFakeAudits implements AuditRepository
{
    public array $events = [];

    public function append(array $event): void
    {
        $this->events[] = $event;
    }
}

final class OnboardingServiceFakeResets implements SecretResetRepository
{
    public array $recorded = [];

    public function record(array $event): void
    {
        $this->recorded[] = $event;
    }

    public function countClientResetsInMonth(int $apiKeyId, int $clientId, int $monthStart, int $monthEnd): int
    {
        return 0;
    }

    public function latestClientResetAt(int $apiKeyId, int $clientId): ?int
    {
        return null;
    }
}
