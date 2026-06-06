<?php

namespace PeakRack\Tests\Unit\Upstream;

use PeakRack\Tests\TestCase;
use PeakRack\UpstreamApi\Http\RequestPath;

final class RequestPathTest extends TestCase
{
    public function testExtractsRelativeRoutesFromTheFullPublicPath(): void
    {
        $this->assertSame(
            '/health',
            RequestPath::route(
                '/billing/modules/addons/peakrack_upstream_api/api/v1/health',
                ''
            )
        );
        $this->assertSame(
            '/services',
            RequestPath::route(
                '/api/v1/billing/modules/addons/peakrack_upstream_api/api/v1/services',
                ''
            )
        );
        $this->assertSame(
            '/health',
            RequestPath::route(
                '/modules/addons/peakrack_upstream_api/api/v1/index.php/health',
                ''
            )
        );
    }

    public function testUsesPathInfoWhenAvailableAndRejectsUnrelatedPaths(): void
    {
        $this->assertSame(
            '/catalog',
            RequestPath::route(
                '/modules/addons/peakrack_upstream_api/api/v1/index.php/catalog',
                '/catalog'
            )
        );
        $this->assertSame('/', RequestPath::route('/unrelated/path', ''));
    }
}
