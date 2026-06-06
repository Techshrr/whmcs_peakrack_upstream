<?php

namespace PeakRack\Tests\Unit\Downstream;

use PeakRack\Tests\TestCase;
use PeakRack\Upstream\Idempotency;
use PeakRack\Upstream\ServiceProperties;
use RuntimeException;

final class IdempotencyTest extends TestCase
{
    public function testCreateUsesOneDeterministicPersistedKey(): void
    {
        $store = new IdempotencyFakeStore();
        $keys = new Idempotency(new ServiceProperties($store), static fn (): string => 'random-one');

        $first = $keys->create(123);
        $second = $keys->create(123);

        $this->assertSame('service:create:123', $first);
        $this->assertSame($first, $second);
        $this->assertSame($first, $store->values['Current Idempotency Key']);
    }

    public function testPendingLifecycleReusesKeyAndTerminalResultClearsIt(): void
    {
        $store = new IdempotencyFakeStore();
        $values = ['random-one', 'random-two'];
        $keys = new Idempotency(
            new ServiceProperties($store),
            static function () use (&$values): string {
                return (string) array_shift($values);
            }
        );

        $first = $keys->lifecycle('suspend', 123);
        $reused = $keys->lifecycle('suspend', 123);
        $this->assertSame($first, $reused);
        $this->assertThrows(
            fn () => $keys->lifecycle('unsuspend', 123),
            RuntimeException::class
        );

        $keys->clearIfMatches($first);
        $second = $keys->lifecycle('unsuspend', 123);
        $this->assertFalse($first === $second);
    }

    public function testRenewalAndPackageChangeKeysIncludeTheirTarget(): void
    {
        $store = new IdempotencyFakeStore();
        $keys = new Idempotency(new ServiceProperties($store), static fn (): string => 'random-one');

        $renewal = $keys->renewal(123, '2026-07-01');
        $keys->clearIfMatches($renewal);
        $change = $keys->changePackage(123, ['product_id' => 10, 'billing_cycle' => 'monthly']);

        $this->assertSame('service:renew:123:2026-07-01', $renewal);
        $this->assertStringContains('service:change_package:123:', $change);
        $this->assertStringContains(
            hash('sha256', '{"billing_cycle":"monthly","product_id":10}'),
            $change
        );
    }
}

final class IdempotencyFakeStore
{
    public function __construct(public array $values = [])
    {
    }

    public function get(string $name): mixed
    {
        return $this->values[$name] ?? null;
    }

    public function save(array $values): void
    {
        $this->values = array_replace($this->values, $values);
    }
}
