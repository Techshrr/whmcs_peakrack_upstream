<?php

namespace PeakRack\Tests\Unit\Downstream;

use InvalidArgumentException;
use PeakRack\Tests\TestCase;
use PeakRack\Upstream\Validator;

final class ValidatorTest extends TestCase
{
    public function testValidatesHostnameProductCycleIdentifiersAndTerminateMode(): void
    {
        $this->assertSame('server.example.test', Validator::hostname('Server.Example.Test'));
        $this->assertSame(10, Validator::positiveInt('10', 'product ID'));
        $this->assertSame('semiannually', Validator::billingCycle('Semi-Annually'));
        $this->assertSame('monthly', Validator::billingCycleOption('auto', 'Monthly'));
        $this->assertSame('hk-1', Validator::identifier('hk-1', 'location'));
        $this->assertSame('ubuntu-24.04', Validator::identifier('ubuntu-24.04', 'OS template'));
        $this->assertSame('cancel_only', Validator::terminateMode(''));
        $this->assertSame('destroy', Validator::terminateMode('destroy'));
        $this->assertSame('2026-06-01', Validator::date('2026-06-01', 'renewal boundary'));

        foreach ([
            fn () => Validator::hostname('https://server.example.test'),
            fn () => Validator::hostname("server.example.test\n"),
            fn () => Validator::positiveInt('0', 'product ID'),
            fn () => Validator::billingCycle('One Time'),
            fn () => Validator::identifier('../hk', 'location'),
            fn () => Validator::terminateMode('delete'),
            fn () => Validator::date('2026-02-30', 'renewal boundary'),
        ] as $invalid) {
            $this->assertThrows($invalid, InvalidArgumentException::class);
        }
    }

    public function testNormalizesBasePathAndRejectsAuthorityQueryFragmentAndTraversal(): void
    {
        $this->assertSame('', Validator::basePath(''));
        $this->assertSame('/billing/whmcs', Validator::basePath('/billing//whmcs/'));

        foreach ([
            '//evil.example/path',
            'https://evil.example/path',
            '/billing?debug=1',
            '/billing#fragment',
            '/billing/../admin',
            '/billing/%2e%2e/admin',
            '/billing\\admin',
        ] as $path) {
            $this->assertThrows(
                fn () => Validator::basePath($path),
                InvalidArgumentException::class
            );
        }
    }
}
