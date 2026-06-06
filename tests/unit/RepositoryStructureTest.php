<?php

namespace PeakRack\Tests\Unit;

use PeakRack\Tests\TestCase;

final class RepositoryStructureTest extends TestCase
{
    public function testReleaseMetadataAndModuleLicensesExist(): void
    {
        $this->assertSame(
            '1.0.0',
            trim((string) file_get_contents(PEAKRACK_UPSTREAM_ROOT . '/VERSION'))
        );

        $paths = [
            '/LICENSE',
            '/NOTICE',
            '/modules/addons/peakrack_upstream_api/LICENSE',
            '/modules/addons/peakrack_upstream_api/NOTICE',
            '/modules/servers/peakrackupstream/LICENSE',
            '/modules/servers/peakrackupstream/NOTICE',
        ];

        foreach ($paths as $path) {
            $this->assertFileExists(PEAKRACK_UPSTREAM_ROOT . $path);
        }
    }

    public function testModuleLicenseCopiesMatchRepositoryLicense(): void
    {
        $rootLicense = PEAKRACK_UPSTREAM_ROOT . '/LICENSE';
        $addonLicense = PEAKRACK_UPSTREAM_ROOT . '/modules/addons/peakrack_upstream_api/LICENSE';
        $serverLicense = PEAKRACK_UPSTREAM_ROOT . '/modules/servers/peakrackupstream/LICENSE';

        $this->assertFileExists($rootLicense);
        $this->assertFileExists($addonLicense);
        $this->assertFileExists($serverLicense);

        $rootHash = hash_file('sha256', $rootLicense);
        $this->assertSame($rootHash, hash_file('sha256', $addonLicense));
        $this->assertSame($rootHash, hash_file('sha256', $serverLicense));
    }
}
