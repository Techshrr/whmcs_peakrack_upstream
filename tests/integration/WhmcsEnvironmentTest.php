<?php

namespace PeakRack\Tests\Integration;

use PeakRack\Tests\TestCase;

require_once __DIR__ . '/WhmcsTestSupport.php';

final class WhmcsEnvironmentTest extends TestCase
{
    public static function group(): string
    {
        return 'whmcs-safe';
    }

    public function testEnvironmentTargetsWhmcsNineAndSupportedPhp(): void
    {
        if (!WhmcsTestSupport::enabled()) {
            $this->skip('Set PEAKRACK_WHMCS_TEST=1 to run WHMCS integration checks.');
        }

        $root = WhmcsTestSupport::boot();
        $version = WhmcsTestSupport::version();

        $this->assertFileExists($root . '/init.php');
        $this->assertTrue(str_starts_with($version, '9.0.'), 'Expected WHMCS 9.0.x, got ' . $version);
        $this->assertTrue(version_compare(PHP_VERSION, '8.2.0', '>='), 'PHP 8.2 or later is required.');
    }
}
