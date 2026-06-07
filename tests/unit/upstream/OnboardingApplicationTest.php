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

    public function testActiveClientKeyOnlyExistsForActiveStates(): void
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
        ]);

        $this->assertSame('client:44', $pending->toRow()['active_client_key']);
        $this->assertSame('client:44', $pending->approve(7, 3, 9, 1780617600)->toRow()['active_client_key']);
        $this->assertSame(null, $pending->reject('missing domain', 9, 1780617600)->toRow()['active_client_key']);
        $this->assertSame(null, OnboardingApplication::restore([
            'id' => 11,
            'client_id' => 44,
            'status' => OnboardingApplication::NEEDS_INFO,
            'brand_name' => 'Example Reseller',
            'downstream_domain' => 'billing.example.test',
            'outbound_ips_json' => '["192.0.2.10"]',
            'business_type' => 'VPS',
            'telegram' => '@example',
        ])->toRow()['active_client_key']);
    }
}
