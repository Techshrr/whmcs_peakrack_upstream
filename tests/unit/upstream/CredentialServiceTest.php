<?php

namespace PeakRack\Tests\Unit\Upstream;

use PeakRack\Tests\TestCase;
use PeakRack\UpstreamApi\Application\CredentialService;
use PeakRack\UpstreamApi\Contracts\SecretResetRepository;
use PeakRack\UpstreamApi\Domain\OnboardingApplication;

final class CredentialServiceTest extends TestCase
{
    public function testClientResetEnforcesMonthlyLimitAndTenDayInterval(): void
    {
        $applications = new OnboardingServiceFakeApplications();
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
        $keys = new OnboardingServiceFakeKeys();
        $keys->rows[8] = ['id' => 8, 'client_id' => 44, 'encrypted_secret' => 'old'];
        $resets = new CredentialServiceFakeResets(latestClientResetAt: 1780272000, monthlyCount: 1);
        $audits = new OnboardingServiceFakeAudits();

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
        $applications = new OnboardingServiceFakeApplications();
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
        $keys = new OnboardingServiceFakeKeys();
        $keys->rows[8] = ['id' => 8, 'client_id' => 44, 'encrypted_secret' => 'old'];
        $resets = new CredentialServiceFakeResets(latestClientResetAt: 1780617500, monthlyCount: 3);

        $service = new CredentialService(
            applications: $applications,
            keys: $keys,
            resets: $resets,
            audits: new OnboardingServiceFakeAudits(),
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

final class CredentialServiceFakeResets implements SecretResetRepository
{
    public array $recorded = [];

    public function __construct(
        public ?int $latestClientResetAt = null,
        public int $monthlyCount = 0
    ) {
    }

    public function record(array $event): void
    {
        $this->recorded[] = $event;
    }

    public function countClientResetsInMonth(int $apiKeyId, int $clientId, int $monthStart, int $monthEnd): int
    {
        return $this->monthlyCount;
    }

    public function latestClientResetAt(int $apiKeyId, int $clientId): ?int
    {
        return $this->latestClientResetAt;
    }
}
