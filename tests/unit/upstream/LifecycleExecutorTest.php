<?php

namespace PeakRack\Tests\Unit\Upstream;

use PeakRack\Tests\TestCase;
use PeakRack\UpstreamApi\Application\LifecycleExecutor;
use PeakRack\UpstreamApi\Contracts\OperationRepository;
use PeakRack\UpstreamApi\Contracts\ProvisioningGateway;
use PeakRack\UpstreamApi\Contracts\ServiceRepository;
use PeakRack\UpstreamApi\Domain\Operation;
use PeakRack\UpstreamApi\Domain\ServiceRecord;
use RuntimeException;

final class LifecycleExecutorTest extends TestCase
{
    public function testCancelOnlyDefersTerminationUntilWorkerVerification(): void
    {
        $provisioning = new LifecycleExecutorFakeProvisioning();
        $services = new LifecycleExecutorFakeServices();
        $cancelCalls = 0;
        $executor = new LifecycleExecutor(
            $provisioning,
            $services,
            new LifecycleExecutorFakeOperations(),
            static function () use (&$cancelCalls): array {
                $cancelCalls++;
                return ['due_at' => 1783296000];
            }
        );

        $pending = $executor->execute($this->operation('cancel_only'));
        $this->assertSame(Operation::PROCESSING, $pending->status());
        $this->assertSame('cancellation_pending', $pending->stage());
        $this->assertSame(1783296000, $pending->nextAttemptAt());
        $this->assertSame(0, $provisioning->terminateCount);
        $this->assertSame(1, $cancelCalls);

        $completed = $executor->verify($pending->resumeVerification());
        $this->assertSame(Operation::COMPLETED, $completed->status());
        $this->assertSame(1, $provisioning->terminateCount);
    }

    public function testDestroyTerminatesImmediately(): void
    {
        $provisioning = new LifecycleExecutorFakeProvisioning();
        $executor = new LifecycleExecutor(
            $provisioning,
            new LifecycleExecutorFakeServices(),
            new LifecycleExecutorFakeOperations(),
            static fn (): array => ['due_at' => 1783296000]
        );

        $result = $executor->execute($this->operation('destroy'));

        $this->assertSame(Operation::COMPLETED, $result->status());
        $this->assertSame(1, $provisioning->terminateCount);
    }

    public function testRecoveredAmbiguousCancellationRequestRequiresManualReview(): void
    {
        $provisioning = new LifecycleExecutorFakeProvisioning();
        $executor = new LifecycleExecutor(
            $provisioning,
            new LifecycleExecutorFakeServices(),
            new LifecycleExecutorFakeOperations(),
            static fn (): array => ['due_at' => 1783296000]
        );
        $operation = Operation::restore(
            id: 'terminate-op',
            apiKeyId: 7,
            action: 'terminate',
            idempotencyKey: 'terminate:123',
            requestHash: hash('sha256', 'cancel_only'),
            status: Operation::PROCESSING,
            localServiceId: 123,
            sanitizedPayload: [
                'upstream_service_id' => 22,
                'mode' => 'cancel_only',
                'delivery_mappings' => [],
            ],
            stage: 'cancellation_request_pending',
            attemptCount: 2
        );

        $result = $executor->verify($operation->resumeVerification());

        $this->assertSame(Operation::MANUAL_REVIEW, $result->status());
        $this->assertSame(0, $provisioning->terminateCount);
    }

    public function testAlreadySuspendedServiceCompletesWithoutModuleCall(): void
    {
        $provisioning = new LifecycleExecutorFakeProvisioning();
        $provisioning->status = 'suspended';
        $executor = new LifecycleExecutor(
            $provisioning,
            new LifecycleExecutorFakeServices(),
            new LifecycleExecutorFakeOperations(),
            static fn (): array => ['due_at' => 1783296000]
        );
        $operation = Operation::admit(
            'suspend-op',
            7,
            'suspend',
            'suspend:123',
            hash('sha256', 'suspend'),
            123,
            ['upstream_service_id' => 22, 'delivery_mappings' => []]
        )->start();

        $result = $executor->execute($operation);

        $this->assertSame(Operation::COMPLETED, $result->status());
        $this->assertSame(0, $provisioning->suspendCount);
    }

    private function operation(string $mode): Operation
    {
        $payload = [
            'upstream_service_id' => 22,
            'mode' => $mode,
            'delivery_mappings' => [],
        ];

        return Operation::admit('terminate-op', 7, 'terminate', 'terminate:123', hash('sha256', $mode), 123, $payload)
            ->start();
    }
}

final class LifecycleExecutorFakeProvisioning implements ProvisioningGateway
{
    public int $terminateCount = 0;
    public int $suspendCount = 0;
    public int $unsuspendCount = 0;
    public string $status = 'active';

    public function create(int $serviceId, string $operationId): array
    {
        return [];
    }

    public function suspend(int $serviceId, string $reason, string $operationId): array
    {
        $this->suspendCount++;
        $this->status = 'suspended';
        return [];
    }

    public function unsuspend(int $serviceId, string $operationId): array
    {
        $this->unsuspendCount++;
        $this->status = 'active';
        return [];
    }

    public function terminate(int $serviceId, string $operationId): array
    {
        $this->terminateCount++;
        $this->status = 'terminated';
        return [];
    }

    public function readDelivery(int $serviceId, array $mappings): array
    {
        return ['service_status' => $this->status];
    }

    public function sso(int $serviceId, array $allowedHosts, string $operationId): string
    {
        throw new RuntimeException('Unexpected SSO call.');
    }
}

final class LifecycleExecutorFakeServices implements ServiceRepository
{
    public array $saved = [];

    public function findByLocalId(int $apiKeyId, int $localServiceId): ?ServiceRecord
    {
        return new ServiceRecord(7, 123, 22, 9, 11, 10, 'monthly', 'active');
    }

    public function findByUpstreamServiceId(int $upstreamServiceId): ?ServiceRecord
    {
        return null;
    }

    public function save(ServiceRecord $service): void
    {
        $this->saved[] = $service;
    }

    public function isManagedUpstreamService(int $upstreamServiceId): bool
    {
        return true;
    }
}

final class LifecycleExecutorFakeOperations implements OperationRepository
{
    public function admit(Operation $operation, array $sanitizedPayload): Operation
    {
        return $operation;
    }

    public function findById(string $operationId): ?Operation
    {
        return null;
    }

    public function findByIdempotency(int $apiKeyId, string $idempotencyKey): ?Operation
    {
        return null;
    }

    public function save(Operation $operation): void
    {
    }

    public function claimDue(int $limit, int $now, string $owner, int $lockUntil): array
    {
        return [];
    }

    public function appendEvent(string $operationId, string $stage, array $sanitizedContext): void
    {
    }
}
