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
