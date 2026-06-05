<?php

namespace PeakRack\Tests\Unit;

use PeakRack\Tests\TestCase;

final class DocumentationTest extends TestCase
{
    public function testRequiredReleaseDocumentsAndAutomationFilesExist(): void
    {
        foreach ([
            '/README.md',
            '/README.zh-CN.md',
            '/CHANGELOG.md',
            '/UPGRADE.md',
            '/UPGRADE.zh-CN.md',
            '/SECURITY.md',
            '/docs/api-v1.md',
            '/.github/workflows/php-lint.yml',
            '/scripts/check-release.ps1',
            '/scripts/sync-installed.ps1',
        ] as $path) {
            $this->assertFileExists(PEAKRACK_UPSTREAM_ROOT . $path);
        }
    }

    public function testReadmesUseOfficialRepositoryAndDocumentBothInstallations(): void
    {
        $english = (string) file_get_contents(PEAKRACK_UPSTREAM_ROOT . '/README.md');
        $chinese = (string) file_get_contents(PEAKRACK_UPSTREAM_ROOT . '/README.zh-CN.md');
        $repository = 'https://github.com/Techshrr/whmcs_peakrack_upstream';

        $this->assertStringContains('Official repository: ' . $repository, substr($english, 0, 500));
        $this->assertStringContains('官方仓库：' . $repository, substr($chinese, 0, 500));
        foreach ([
            '/modules/addons/peakrack_upstream_api/',
            '/modules/servers/peakrackupstream/',
            'modules/addons/peakrack_upstream_api/cron/worker.php',
            'modules/servers/peakrackupstream/cron/sync.php',
            'Automatic Credit Use',
            'Credit on Downgrade',
        ] as $required) {
            $this->assertStringContains($required, $english . $chinese);
        }
        $this->assertStringContains('disabled', strtolower($english));
        $this->assertStringContains('real upstream Provisioning Module', $english);
    }

    public function testDocumentationDoesNotClaimUnverifiedProviderCompatibilityOrUseMarketingLanguage(): void
    {
        $contents = '';
        foreach ([
            '/README.md',
            '/README.zh-CN.md',
            '/CHANGELOG.md',
            '/UPGRADE.md',
            '/UPGRADE.zh-CN.md',
            '/SECURITY.md',
            '/docs/api-v1.md',
        ] as $path) {
            $contents .= "\n" . (string) file_get_contents(PEAKRACK_UPSTREAM_ROOT . $path);
        }

        foreach ([
            'supports all providers',
            'compatible with all providers',
            'enterprise-grade',
            'cutting-edge',
            'revolutionary',
            'seamless solution',
        ] as $claim) {
            $this->assertStringNotContains($claim, strtolower($contents));
        }
    }

    public function testPrimaryModulePhpFilesContainLicenseAndRepositoryHeaders(): void
    {
        foreach ([
            PEAKRACK_UPSTREAM_ROOT . '/modules/addons/peakrack_upstream_api',
            PEAKRACK_UPSTREAM_ROOT . '/modules/servers/peakrackupstream',
        ] as $modulePath) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($modulePath, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                $path = str_replace('\\', '/', $file->getPathname());
                if (
                    !$file->isFile()
                    || $file->getExtension() !== 'php'
                    || str_contains($path, '/lang/')
                ) {
                    continue;
                }
                $contents = (string) file_get_contents($file->getPathname());
                $this->assertStringContains('SPDX-License-Identifier: Apache-2.0', $contents, $path);
                $this->assertStringContains(
                    'https://github.com/Techshrr/whmcs_peakrack_upstream',
                    $contents,
                    $path
                );
                $this->assertStringContains('Copyright 2026 PeakRack.', $contents, $path);
                $this->assertStringNotContains('declare(strict_types=1)', $contents, $path);
            }
        }
    }

    public function testReleaseAndSyncScriptsDeclareRequiredChecks(): void
    {
        $release = (string) file_get_contents(PEAKRACK_UPSTREAM_ROOT . '/scripts/check-release.ps1');
        foreach ([
            'tests/run.php',
            'php -l',
            'Get-FileHash',
            'Compress-Archive',
            'tests/fixtures',
            'SPDX-License-Identifier: Apache-2.0',
        ] as $required) {
            $this->assertStringContains($required, $release);
        }

        $sync = (string) file_get_contents(PEAKRACK_UPSTREAM_ROOT . '/scripts/sync-installed.ps1');
        foreach ([
            'modules/addons/peakrack_upstream_api',
            'modules/servers/peakrackupstream',
            'Get-FileHash',
            'Assert-SafeTarget',
        ] as $required) {
            $this->assertStringContains($required, $sync);
        }
        $this->assertStringNotContains('Remove-Item -LiteralPath $WhmcsRoot', $sync);

        $workflow = (string) file_get_contents(PEAKRACK_UPSTREAM_ROOT . '/.github/workflows/php-lint.yml');
        $this->assertStringContains('8.2', $workflow);
        $this->assertStringContains('8.3', $workflow);
        $this->assertStringContains('scripts/check-release.ps1', $workflow);
    }
}
