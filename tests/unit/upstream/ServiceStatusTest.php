<?php

namespace PeakRack\Tests\Unit\Upstream;

use PeakRack\Tests\TestCase;
use PeakRack\UpstreamApi\Domain\ServiceStatus;

final class ServiceStatusTest extends TestCase
{
    public function testNormalizesKnownWhmcsStatuses(): void
    {
        $this->assertSame(ServiceStatus::PENDING, ServiceStatus::normalize('Pending'));
        $this->assertSame(ServiceStatus::ACTIVE, ServiceStatus::normalize('Active'));
        $this->assertSame(ServiceStatus::SUSPENDED, ServiceStatus::normalize('Suspended'));
        $this->assertSame(ServiceStatus::CANCELLATION_PENDING, ServiceStatus::normalize('Pending Cancellation'));
        $this->assertSame(ServiceStatus::TERMINATED, ServiceStatus::normalize('Terminated'));
    }

    public function testUnknownStatusNeverBecomesActive(): void
    {
        $this->assertSame(ServiceStatus::UNKNOWN, ServiceStatus::normalize('ProviderCustomState'));
        $this->assertFalse(ServiceStatus::normalize('ProviderCustomState') === ServiceStatus::ACTIVE);
    }
}
