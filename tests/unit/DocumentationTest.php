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
            'PEAKRACK_WHMCS_TEST',
            '--group whmcs-safe',
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
            'System.Management.Automation.Language.Parser',
            'ls-files --others --exclude-standard',
            'InstallPath',
            'peakrackupstream-whmcs-root',
            'modules/servers/peakrackupstream',
        ] as $required) {
            $this->assertStringContains($required, $release);
        }

        $sync = (string) file_get_contents(PEAKRACK_UPSTREAM_ROOT . '/scripts/sync-installed.ps1');
        foreach ([
            'modules/addons/peakrack_upstream_api',
            'modules/servers/peakrackupstream',
            'Get-FileHash',
            'Assert-SafeTarget',
            'ls-files --others --ignored --exclude-standard',
        ] as $required) {
            $this->assertStringContains($required, $sync);
        }
        $this->assertStringNotContains('Remove-Item -LiteralPath $WhmcsRoot', $sync);

        $workflow = (string) file_get_contents(PEAKRACK_UPSTREAM_ROOT . '/.github/workflows/php-lint.yml');
        $this->assertStringContains('8.2', $workflow);
        $this->assertStringContains('8.3', $workflow);
        $this->assertStringContains('scripts/check-release.ps1', $workflow);
    }

    public function testReadmesDocumentWhmcsRootPackagesAndExactDownstreamModuleName(): void
    {
        $english = (string) file_get_contents(PEAKRACK_UPSTREAM_ROOT . '/README.md');
        $chinese = (string) file_get_contents(PEAKRACK_UPSTREAM_ROOT . '/README.zh-CN.md');

        foreach ([
            'peakrackupstream-whmcs-root-v',
            'Extract this package from the downstream WHMCS root',
            'technical module name is `peakrackupstream`',
            'single lowercase word',
        ] as $required) {
            $this->assertStringContains($required, $english);
        }

        foreach ([
            'peakrackupstream-whmcs-root-v',
            '在下游 WHMCS 根目录解压',
            '技术模块名是 `peakrackupstream`',
            '单个小写单词',
        ] as $required) {
            $this->assertStringContains($required, $chinese);
        }
    }

    public function testDownstreamCronPrioritizesPendingServicesBeforeApplyingTheBatchLimit(): void
    {
        $cron = (string) file_get_contents(
            PEAKRACK_UPSTREAM_ROOT . '/modules/servers/peakrackupstream/cron/sync.php'
        );
        $priority = "CASE WHEN tblhosting.domainstatus = 'Pending' THEN 0 ELSE 1 END";

        $this->assertStringContains($priority, $cron);
        $this->assertTrue(
            strpos($cron, $priority) < strpos($cron, '->limit($limit)'),
            'The pending-service priority must be applied before the batch limit.'
        );
    }

    public function testDocumentationMentionsOnboardingAsImplementedAfterRelease(): void
    {
        $readme = (string) file_get_contents(PEAKRACK_UPSTREAM_ROOT . '/README.md');
        $this->assertStringContains('Client Area onboarding', $readme);
        $this->assertStringContains('manual administrator approval', $readme);
        $this->assertStringContains('API Secret is displayed once', $readme);

        $upgrade = (string) file_get_contents(PEAKRACK_UPSTREAM_ROOT . '/UPGRADE.md');
        $this->assertStringContains('Allowed Client Group IDs', $upgrade);
        $this->assertStringContains('Downstream Module Download URL', $upgrade);
    }
}
