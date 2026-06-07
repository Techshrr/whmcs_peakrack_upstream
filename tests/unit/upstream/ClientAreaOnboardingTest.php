<?php

namespace PeakRack\Tests\Unit\Upstream;

use PeakRack\Tests\TestCase;
use PeakRack\UpstreamApi\Application\CredentialService;
use PeakRack\UpstreamApi\Application\EligibilityService;
use PeakRack\UpstreamApi\Application\OnboardingService;
use PeakRack\UpstreamApi\Application\OnboardingValidator;
use PeakRack\UpstreamApi\Application\SetupGuideService;
use PeakRack\UpstreamApi\ClientArea\OnboardingClientAreaController;
use PeakRack\UpstreamApi\Contracts\ApiKeyRepository;
use PeakRack\UpstreamApi\Contracts\ApplicationRepository;
use PeakRack\UpstreamApi\Contracts\AuditRepository;
use PeakRack\UpstreamApi\Contracts\ClientAccountGateway;
use PeakRack\UpstreamApi\Contracts\PolicyRepository;
use PeakRack\UpstreamApi\Contracts\PolicyTemplateRepository;
use PeakRack\UpstreamApi\Contracts\SecretResetRepository;
use PeakRack\UpstreamApi\Domain\OnboardingApplication;
use PeakRack\UpstreamApi\Domain\PolicyTemplate;
use PeakRack\UpstreamApi\Domain\ProductPolicy;

final class ClientAreaOnboardingTest extends TestCase
{
    public function testEligibleClientCanSubmitApplicationWithCsrf(): void
    {
        $applications = new ClientAreaFakeApplications();
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
        $applications = new ClientAreaFakeApplications();
        $applications->rows[5] = OnboardingApplication::restore([
            'id' => 5,
            'client_id' => 99,
            'status' => OnboardingApplication::APPROVED,
            'brand_name' => 'Other',
            'downstream_domain' => 'other.example.test',
            'outbound_ips_json' => '["192.0.2.10"]',
            'business_type' => 'VPS',
            'telegram' => '@other',
            'api_key_id' => 8,
            'template_id' => 3,
        ]);
        $controller = ClientAreaFactory::controller(applications: $applications);
        $session = ['uid' => 44, 'peakrack_upstream_api_client_csrf' => 'csrf-token'];

        $view = $controller->dispatch([], $session, '198.51.100.1');
        $this->assertSame('empty', $view['state']);
    }
}

final class ClientAreaFactory
{
    public static function controller(?ClientAreaFakeApplications $applications = null): OnboardingClientAreaController
    {
        $applications ??= new ClientAreaFakeApplications();
        $keys = new ClientAreaFakeKeys();
        $keys->rows[8] = [
            'id' => 8,
            'public_key' => 'prk_public',
            'client_id' => 44,
            'encrypted_secret' => 'encrypted:prs_secret',
        ];
        $audits = new ClientAreaFakeAudits();
        $clock = static fn (): int => 1780617600;

        return new OnboardingClientAreaController(
            applications: $applications,
            eligibility: new EligibilityService(new ClientAreaFakeGateway(), [3]),
            validator: new OnboardingValidator(requireOutboundIp: true),
            onboarding: new OnboardingService(
                applications: $applications,
                keys: $keys,
                policies: new ClientAreaFakePolicies(),
                templates: new ClientAreaFakeTemplates(),
                audits: $audits,
                encryptSecret: static fn (string $secret): string => 'encrypted:' . $secret,
                publicKeyGenerator: static fn (): string => 'prk_public',
                secretGenerator: static fn (): string => 'prs_secret',
                instanceIdGenerator: static fn (): string => '123e4567-e89b-42d3-a456-426614174000',
                defaultRateLimit: 120,
                clock: $clock
            ),
            credentials: new CredentialService(
                applications: $applications,
                keys: $keys,
                resets: new ClientAreaFakeResets(),
                audits: $audits,
                encryptSecret: static fn (string $secret): string => 'encrypted:' . $secret,
                decryptSecret: static fn (string $secret): string => str_replace('encrypted:', '', $secret),
                secretGenerator: static fn (): string => 'prs_reset',
                clock: $clock
            ),
            guides: new SetupGuideService(),
            config: [
                'download_url' => 'https://example.test/downloads/peakrackupstream.zip',
                'api_base_url' => 'https://www.peakrack.com/modules/addons/peakrack_upstream_api/api/v1',
                'terms_url' => 'https://example.test/terms',
            ],
            csrfGenerator: static fn (): string => 'csrf-token'
        );
    }
}

final class ClientAreaFakeGateway implements ClientAccountGateway
{
    public function clientSummary(int $clientId): array
    {
        return [
            'status' => 'Active',
            'email_verified' => true,
            'group_id' => 3,
            'overdue_invoices' => 0,
        ];
    }
}

final class ClientAreaFakeApplications implements ApplicationRepository
{
    public array $created = [];
    public array $rows = [];

    public function create(array $attributes): int
    {
        $id = count($this->created) + 1;
        $attributes['id'] = $id;
        $this->created[] = $attributes;
        $this->rows[$id] = OnboardingApplication::restore($attributes);

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

final class ClientAreaFakeKeys implements ApiKeyRepository
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

final class ClientAreaFakePolicies implements PolicyRepository
{
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
        return 1;
    }

    public function delete(int $id): void
    {
    }
}

final class ClientAreaFakeTemplates implements PolicyTemplateRepository
{
    public function saveTemplate(array $template, array $items): int
    {
        return 1;
    }

    public function find(int $id): ?PolicyTemplate
    {
        return PolicyTemplate::restore([
            'id' => $id,
            'name' => 'Starter',
            'enabled' => 1,
            'items' => [],
        ]);
    }

    public function listTemplates(bool $enabledOnly = false): array
    {
        return [];
    }

    public function deleteTemplate(int $id): void
    {
    }
}

final class ClientAreaFakeAudits implements AuditRepository
{
    public function append(array $event): void
    {
    }
}

final class ClientAreaFakeResets implements SecretResetRepository
{
    public function record(array $event): void
    {
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
