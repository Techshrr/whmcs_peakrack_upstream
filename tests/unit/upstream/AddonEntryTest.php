<?php

namespace PeakRack\Tests\Unit\Upstream;

use PeakRack\Tests\TestCase;
use PeakRack\UpstreamApi\Admin\ActivationService;
use PeakRack\UpstreamApi\Support\Compatibility;

final class AddonEntryTest extends TestCase
{
    public function testAddonEntryDeclaresRequiredConfiguration(): void
    {
        $entry = PEAKRACK_UPSTREAM_ROOT . '/modules/addons/peakrack_upstream_api/peakrack_upstream_api.php';
        $this->assertFileExists($entry);
        require_once $entry;

        $config = peakrack_upstream_api_config();

        foreach (['name', 'description', 'version', 'author', 'fields'] as $key) {
            $this->assertArrayHasKey($key, $config);
        }
        $this->assertArrayHasKey('order_payment_method', $config['fields']);
        $this->assertArrayHasKey('worker_batch_size', $config['fields']);
    }

    public function testCompatibilityRejectsUnsupportedPhpAndWhmcsSeries(): void
    {
        $this->assertContains('PHP 8.2 or later is required.', Compatibility::errors('8.1.30', '9.0.3'));
        $this->assertContains('WHMCS 9.0.x is required.', Compatibility::errors('8.3.0', '8.13.1'));
        $this->assertSame([], Compatibility::errors('8.3.0', '9.0.3'));
    }

    public function testActivationInstallsSchemaAndDeactivationPreservesData(): void
    {
        $installCount = 0;
        $service = new ActivationService(static function () use (&$installCount): void {
            $installCount++;
        });

        $activated = $service->activate('8.3.0', '9.0.3');
        $deactivated = $service->deactivate();

        $this->assertSame('success', $activated['status']);
        $this->assertSame(1, $installCount);
        $this->assertSame('success', $deactivated['status']);
        $this->assertStringContains('preserved', strtolower($deactivated['description']));
    }

    public function testActivationRejectsUnsafeCreditSettingsBeforeInstalling(): void
    {
        $installCount = 0;
        $service = new ActivationService(
            static function () use (&$installCount): void {
                $installCount++;
            },
            static fn (): array => ['Automatic Credit Use must be disabled.']
        );

        $result = $service->activate('8.3.0', '9.0.3');

        $this->assertSame('error', $result['status']);
        $this->assertSame(0, $installCount);
        $this->assertStringContains('Automatic Credit Use', $result['description']);
    }
}
