<?php

namespace PeakRack\Tests\Unit\Upstream;

use PeakRack\Tests\TestCase;
use PeakRack\UpstreamApi\Application\OperationService;
use PeakRack\UpstreamApi\Contracts\Clock;
use PeakRack\UpstreamApi\Contracts\IdGenerator;
use PeakRack\UpstreamApi\Contracts\LockRepository;
use PeakRack\UpstreamApi\Contracts\OperationExecutor;
use PeakRack\UpstreamApi\Contracts\OperationRepository;
use PeakRack\UpstreamApi\Domain\ApiError;
use PeakRack\UpstreamApi\Domain\Operation;
use PeakRack\UpstreamApi\Domain\ValidationException;
use RuntimeException;

final class OperationServiceTest extends TestCase
{
    public function testPersistsAndRedactsBeforeSynchronousExecution(): void
    {
        [$service, $repository, , $executor, $trace] = $this->service();

        $result = $service->admit(
            7,
            123,
            'create',
            'service:create:123',
            [
                'product_id' => 10,
                'local_service_id' => 123,
                'password' => 'temporary-secret',
                'nested' => ['sso_url' => 'https://panel.example.test/sso'],
            ],
            true,
            44
        );

        $this->assertSame(Operation::COMPLETED, $result->status());
        $this->assertSame(1, $executor->executeCount);
        $this->assertSame('admit', $trace->events[0]);
        $this->assertTrue(
            array_search('admit', $trace->events, true) < array_search('execute', $trace->events, true)
        );
        $this->assertSame('[REDACTED]', $repository->lastPayload['password']);
        $this->assertSame('[REDACTED]', $repository->lastPayload['nested']['sso_url']);
    }

    public function testSameCanonicalRequestReturnsOriginalWithoutExecutingTwice(): void
    {
        [$service, , , $executor] = $this->service();

        $first = $service->admit(
            7,
            123,
            'suspend',
            'service:suspend:123:1',
            ['local_service_id' => 123, 'reason' => 'billing'],
            false
        );
        $replay = $service->admit(
            7,
            123,
            'suspend',
            'service:suspend:123:1',
            ['reason' => 'billing', 'local_service_id' => 123],
            false
        );

        $this->assertSame($first->id(), $replay->id());
        $this->assertSame(Operation::COMPLETED, $replay->status());
        $this->assertSame(1, $executor->executeCount);
    }

    public function testSameIdempotencyKeyWithDifferentHashReturnsConflict(): void
    {
        [$service] = $this->service();

        $service->admit(7, 123, 'change_package', 'change:123:target', [
            'local_service_id' => 123,
            'product_id' => 10,
        ], true, 44);

        try {
            $service->admit(7, 123, 'change_package', 'change:123:target', [
                'local_service_id' => 123,
                'product_id' => 11,
            ], true, 44);
        } catch (ValidationException $exception) {
            $this->assertSame(ApiError::SERVICE_STATE_CONFLICT, $exception->apiErrorCode());
            $this->assertSame(409, $exception->httpStatus());
            return;
        }

        throw new RuntimeException('Expected an idempotency conflict.');
    }

    public function testServiceLockConflictQueuesWithoutExecuting(): void
    {
        [$service, , $locks, $executor] = $this->service();
        $locks->deny['service:7:123'] = true;

        $operation = $service->admit(
            7,
            123,
            'unsuspend',
            'service:unsuspend:123:1',
            ['local_service_id' => 123],
            false
        );

        $this->assertSame(Operation::QUEUED, $operation->status());
        $this->assertSame(0, $executor->executeCount);
    }

    public function testCreditConsumingOperationAcquiresServiceAndBillingLocks(): void
    {
        [$service, , $locks] = $this->service();

        $service->admit(
            7,
            123,
            'renew',
            'service:renew:123:2026-07-01',
            ['local_service_id' => 123, 'renewal_boundary' => '2026-07-01'],
            true,
            44
        );

        $this->assertSame(['service:7:123', 'billing:44'], $locks->acquired);
        $this->assertSame(['billing:44', 'service:7:123'], $locks->released);
    }

    public function testCreateLockConflictDoesNotPersistPasswordlessQueuedOperation(): void
    {
        [$service, , $locks, $executor, $trace] = $this->service();
        $locks->deny['service:7:123'] = true;

        try {
            $service->admit(
                7,
                123,
                'create',
                'service:create:123',
                [
                    'local_service_id' => 123,
                    'product_id' => 10,
                    'billing_cycle' => 'monthly',
                    'password' => 'temporary-secret',
                ],
                true,
                44
            );
        } catch (ValidationException $exception) {
            $this->assertSame(ApiError::OPERATION_PROCESSING, $exception->apiErrorCode());
            $this->assertFalse(in_array('admit', $trace->events, true));
            $this->assertSame(0, $executor->executeCount);
            return;
        }

        throw new RuntimeException('Expected create lock conflict.');
    }

    private function service(): array
    {
        $trace = new OperationServiceTrace();
        $repository = new OperationServiceFakeRepository($trace);
        $locks = new OperationServiceFakeLocks();
        $executor = new OperationServiceFakeExecutor($trace);
        $service = new OperationService(
            $repository,
            $locks,
            $executor,
            new OperationServiceFakeIds(),
            new OperationServiceFakeClock(1780617600)
        );

        return [$service, $repository, $locks, $executor, $trace];
    }
}

final class OperationServiceTrace
{
    public array $events = [];
}

final class OperationServiceFakeRepository implements OperationRepository
{
    public array $operations = [];
    public array $lastPayload = [];

    public function __construct(private readonly OperationServiceTrace $trace)
    {
    }

    public function admit(Operation $operation, array $sanitizedPayload): Operation
    {
        $this->trace->events[] = 'admit';
        $this->lastPayload = $sanitizedPayload;
        $key = $operation->apiKeyId() . ':' . $operation->idempotencyKey();

        if (isset($this->operations[$key])) {
            $existing = $this->operations[$key];
            if (!hash_equals($existing->requestHash(), $operation->requestHash())) {
                throw new ValidationException(
                    ApiError::SERVICE_STATE_CONFLICT,
                    'The idempotency key conflicts.',
                    409
                );
            }

            return $existing;
        }

        $this->operations[$key] = $operation;
        return $operation;
    }

    public function findById(string $operationId): ?Operation
    {
        foreach ($this->operations as $operation) {
            if ($operation->id() === $operationId) {
                return $operation;
            }
        }

        return null;
    }

    public function findByIdempotency(int $apiKeyId, string $idempotencyKey): ?Operation
    {
        return $this->operations[$apiKeyId . ':' . $idempotencyKey] ?? null;
    }

    public function save(Operation $operation): void
    {
        $this->trace->events[] = 'save:' . $operation->status();
        $this->operations[$operation->apiKeyId() . ':' . $operation->idempotencyKey()] = $operation;
    }

    public function claimDue(int $limit, int $now, string $owner, int $lockUntil): array
    {
        return [];
    }

    public function appendEvent(string $operationId, string $stage, array $sanitizedContext): void
    {
        $this->trace->events[] = 'event:' . $stage;
    }
}

final class OperationServiceFakeLocks implements LockRepository
{
    public array $deny = [];
    public array $acquired = [];
    public array $released = [];

    public function acquire(string $resource, string $owner, int $expiresAt): bool
    {
        if (isset($this->deny[$resource])) {
            return false;
        }

        $this->acquired[] = $resource;
        return true;
    }

    public function release(string $resource, string $owner): void
    {
        $this->released[] = $resource;
    }

    public function purgeExpired(int $now): int
    {
        return 0;
    }
}

final class OperationServiceFakeExecutor implements OperationExecutor
{
    public int $executeCount = 0;

    public function __construct(private readonly OperationServiceTrace $trace)
    {
    }

    public function execute(Operation $operation): Operation
    {
        $this->trace->events[] = 'execute';
        $this->executeCount++;
        return $operation->complete(['service_status' => 'active']);
    }

    public function verify(Operation $operation): Operation
    {
        throw new RuntimeException('Verification is not expected in this test.');
    }
}

final class OperationServiceFakeIds implements IdGenerator
{
    private int $next = 1;

    public function uuid(): string
    {
        return 'operation-' . $this->next++;
    }
}

final class OperationServiceFakeClock implements Clock
{
    public function __construct(private readonly int $now)
    {
    }

    public function now(): int
    {
        return $this->now;
    }
}
