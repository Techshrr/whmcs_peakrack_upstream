<?php

namespace PeakRack\Tests\Integration;

use PeakRack\Tests\TestCase;

require_once __DIR__ . '/WhmcsTestSupport.php';

final class WhmcsModuleRegistrationTest extends TestCase
{
    public static function group(): string
    {
        return 'whmcs-safe';
    }

    public function testInstalledModuleEntriesLoadAndDeclareExpectedFunctions(): void
    {
        if (!WhmcsTestSupport::enabled()) {
            $this->skip('Set PEAKRACK_WHMCS_TEST=1 to run WHMCS integration checks.');
        }

        $root = WhmcsTestSupport::boot();
        require_once $root . '/modules/addons/peakrack_upstream_api/peakrack_upstream_api.php';
        require_once $root . '/modules/servers/peakrackupstream/peakrackupstream.php';

        foreach ([
            'peakrack_upstream_api_config',
            'peakrack_upstream_api_activate',
            'peakrack_upstream_api_deactivate',
            'peakrack_upstream_api_output',
            'peakrackupstream_MetaData',
            'peakrackupstream_ConfigOptions',
            'peakrackupstream_TestConnection',
            'peakrackupstream_CreateAccount',
            'peakrackupstream_Renew',
            'peakrackupstream_ServiceSingleSignOn',
        ] as $function) {
            $this->assertTrue(function_exists($function), 'Missing installed module function: ' . $function);
        }

        $this->assertSame('1.0.0', peakrack_upstream_api_config()['version']);
        $this->assertSame('PeakRack Upstream', peakrackupstream_MetaData()['DisplayName']);
    }

    public function testInstalledModuleTreesMatchSourceHashes(): void
    {
        if (!WhmcsTestSupport::enabled()) {
            $this->skip('Set PEAKRACK_WHMCS_TEST=1 to run WHMCS integration checks.');
        }

        $root = WhmcsTestSupport::boot();
        foreach ([
            'modules/addons/peakrack_upstream_api',
            'modules/servers/peakrackupstream',
        ] as $relative) {
            $this->assertSame(
                WhmcsTestSupport::fileHashes(PEAKRACK_UPSTREAM_ROOT . '/' . $relative),
                WhmcsTestSupport::fileHashes($root . '/' . $relative),
                'Installed module tree does not match source: ' . $relative
            );
        }
    }
}
