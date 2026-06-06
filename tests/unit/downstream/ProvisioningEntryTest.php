<?php

namespace PeakRack\Tests\Unit\Downstream;

use PeakRack\Tests\TestCase;

final class ProvisioningEntryTest extends TestCase
{
    public function testDefinesApprovedFunctionsAndOmitsCustomerLifecycleButtons(): void
    {
        $entry = PEAKRACK_UPSTREAM_ROOT . '/modules/servers/peakrackupstream/peakrackupstream.php';
        $this->assertFileExists($entry);
        require_once $entry;

        foreach ([
            'peakrackupstream_MetaData',
            'peakrackupstream_ConfigOptions',
            'peakrackupstream_TestConnection',
            'peakrackupstream_CreateAccount',
            'peakrackupstream_SuspendAccount',
            'peakrackupstream_UnsuspendAccount',
            'peakrackupstream_TerminateAccount',
            'peakrackupstream_Renew',
            'peakrackupstream_ChangePackage',
            'peakrackupstream_ClientArea',
            'peakrackupstream_ServiceSingleSignOn',
        ] as $function) {
            $this->assertTrue(function_exists($function), $function);
        }
        $this->assertFalse(function_exists('peakrackupstream_ClientAreaCustomButtonArray'));
    }

    public function testDeclaresExactServerAndProductConfigurationContract(): void
    {
        require_once PEAKRACK_UPSTREAM_ROOT . '/modules/servers/peakrackupstream/peakrackupstream.php';

        $metadata = peakrackupstream_MetaData();
        $this->assertSame('PeakRack Upstream', $metadata['DisplayName']);
        $this->assertSame('1.1', $metadata['APIVersion']);
        $this->assertTrue($metadata['RequiresServer']);
        $this->assertSame(443, $metadata['DefaultSSLPort']);
        $this->assertSame('Open Control Panel', $metadata['ServiceSingleSignOnLabel']);

        $options = peakrackupstream_ConfigOptions();
        $this->assertSame([
            'Upstream Product ID',
            'Upstream Billing Cycle',
            'Upstream Location',
            'Default OS Template',
            'Terminate Mode',
            'Request Timeout',
        ], array_keys($options));
        $this->assertSame('auto', $options['Upstream Billing Cycle']['Default']);
        $this->assertSame('cancel_only', $options['Terminate Mode']['Default']);
        $this->assertSame('30', $options['Request Timeout']['Default']);
        $this->assertFalse(array_key_exists('Debug Mode', $options));
        $this->assertFalse(array_key_exists('Auto Suspend', $options));
        $this->assertFalse(array_key_exists('Auto Terminate', $options));
    }
}
