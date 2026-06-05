<?php

namespace PeakRack\Tests\Unit\Upstream;

use PeakRack\Tests\TestCase;
use PeakRack\UpstreamApi\Domain\ProductPolicy;
use PeakRack\UpstreamApi\Domain\ValidationException;

final class ProductPolicyTest extends TestCase
{
    public function testAllowsAnAuthorizedCreateRequest(): void
    {
        $policy = $this->policy();

        $policy->assertAllows('create', 10, 'monthly', 'hk-1', 'ubuntu-24.04', false);
        $this->assertTrue(true);
    }

    public function testRejectsUnauthorizedProductCycleAndMappings(): void
    {
        $policy = $this->policy();

        $this->assertThrows(
            static fn () => $policy->assertAllows('create', 11, 'monthly', 'hk-1', 'ubuntu-24.04', false),
            ValidationException::class,
            'Product is not allowed'
        );
        $this->assertThrows(
            static fn () => $policy->assertAllows('create', 10, 'annually', 'hk-1', 'ubuntu-24.04', false),
            ValidationException::class,
            'Billing cycle is not allowed'
        );
        $this->assertThrows(
            static fn () => $policy->assertAllows('create', 10, 'monthly', 'us-1', 'ubuntu-24.04', false),
            ValidationException::class,
            'Location is not allowed'
        );
        $this->assertThrows(
            static fn () => $policy->assertAllows('create', 10, 'monthly', 'hk-1', 'windows', false),
            ValidationException::class,
            'OS template is not allowed'
        );
    }

    public function testRejectsUnauthorizedActionAndDestroy(): void
    {
        $policy = $this->policy();

        $this->assertThrows(
            static fn () => $policy->assertAllows('renew', 10, 'monthly', null, null, false),
            ValidationException::class,
            'Action is not allowed'
        );
        $this->assertThrows(
            static fn () => $policy->assertAllows('terminate', 10, 'monthly', null, null, true),
            ValidationException::class,
            'Destroy is not allowed'
        );
    }

    private function policy(): ProductPolicy
    {
        return new ProductPolicy(
            10,
            ['monthly'],
            ['create', 'terminate'],
            ['hk-1' => ['field' => 'configoption', 'id' => 1, 'value' => 2]],
            ['ubuntu-24.04' => ['field' => 'customfield', 'id' => 3, 'value' => 'ubuntu']],
            false
        );
    }
}
