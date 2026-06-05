<?php

namespace PeakRack\Tests\Unit\Upstream;

use PeakRack\Tests\TestCase;
use PeakRack\UpstreamApi\Whmcs\OperationContext;
use RuntimeException;

final class OperationContextTest extends TestCase
{
    public function testContextIsActiveOnlyInsideApplicationCallbackAndAlwaysClears(): void
    {
        $this->assertFalse(OperationContext::isActive());
        $inside = OperationContext::run('operation-1', static fn (): array => [
            'active' => OperationContext::isActive(),
            'id' => OperationContext::operationId(),
        ]);

        $this->assertSame(['active' => true, 'id' => 'operation-1'], $inside);
        $this->assertFalse(OperationContext::isActive());

        try {
            OperationContext::run('operation-error', static function (): void {
                throw new RuntimeException('expected');
            });
        } catch (RuntimeException) {
        }

        $this->assertFalse(OperationContext::isActive());
    }

    public function testGuardAllowsUnrelatedOrAuthorizedServicesOnly(): void
    {
        $this->assertSame([], OperationContext::guardManagedService(false));
        $this->assertSame(['abortcmd' => true], OperationContext::guardManagedService(true));

        $authorized = OperationContext::run(
            'operation-1',
            static fn (): array => OperationContext::guardManagedService(true)
        );
        $this->assertSame([], $authorized);
    }

    public function testHooksRegisterEveryApprovedGuard(): void
    {
        $path = PEAKRACK_UPSTREAM_ROOT . '/modules/addons/peakrack_upstream_api/hooks.php';
        $this->assertFileExists($path);
        $contents = (string) file_get_contents($path);

        foreach ([
            'PreModuleSuspend',
            'PreModuleUnsuspend',
            'PreModuleTerminate',
            'PreModuleRenew',
            'PreModuleChangePackage',
        ] as $hook) {
            $this->assertStringContains($hook, $contents);
        }
    }
}
