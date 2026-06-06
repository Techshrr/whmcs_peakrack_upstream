<?php

namespace PeakRack\Tests\Unit\Upstream;

use PeakRack\Tests\TestCase;
use PeakRack\UpstreamApi\Contracts\ApiKeyRepository;
use PeakRack\UpstreamApi\Contracts\Clock;
use PeakRack\UpstreamApi\Contracts\IdGenerator;
use PeakRack\UpstreamApi\Contracts\LockRepository;
use PeakRack\UpstreamApi\Contracts\NonceRepository;
use PeakRack\UpstreamApi\Contracts\OperationRepository;
use PeakRack\UpstreamApi\Contracts\PolicyRepository;
use PeakRack\UpstreamApi\Contracts\ServiceRepository;
use ReflectionClass;

final class PersistenceContractTest extends TestCase
{
    public function testDefinesEveryPersistenceAndRuntimeContract(): void
    {
        $interfaces = [
            ApiKeyRepository::class,
            PolicyRepository::class,
            ServiceRepository::class,
            OperationRepository::class,
            NonceRepository::class,
            LockRepository::class,
            Clock::class,
            IdGenerator::class,
        ];

        foreach ($interfaces as $interface) {
            $this->assertTrue(interface_exists($interface), "Missing interface {$interface}.");
        }
    }

    public function testOperationRepositoryRequiresAtomicAdmissionAndDueClaiming(): void
    {
        $reflection = new ReflectionClass(OperationRepository::class);
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods()
        );

        foreach (['admit', 'findById', 'findByIdempotency', 'save', 'claimDue', 'appendEvent'] as $method) {
            $this->assertContains($method, $methods);
        }
    }

    public function testOwnershipAndReplayContractsAreExplicit(): void
    {
        $serviceMethods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(ServiceRepository::class))->getMethods()
        );
        $nonceMethods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(NonceRepository::class))->getMethods()
        );
        $lockMethods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(LockRepository::class))->getMethods()
        );

        $this->assertContains('findByLocalId', $serviceMethods);
        $this->assertContains('isManagedUpstreamService', $serviceMethods);
        $this->assertContains('claim', $nonceMethods);
        $this->assertContains('purgeExpired', $nonceMethods);
        $this->assertContains('acquire', $lockMethods);
        $this->assertContains('release', $lockMethods);
    }
}
