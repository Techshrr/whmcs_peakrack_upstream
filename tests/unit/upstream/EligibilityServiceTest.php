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
