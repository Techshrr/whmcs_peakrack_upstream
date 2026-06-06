<?php

namespace PeakRack\Tests\Unit\Upstream;

use PeakRack\Tests\TestCase;
use PeakRack\UpstreamApi\Application\OperationExecutionException;
use PeakRack\UpstreamApi\Application\WorkerService;
use PeakRack\UpstreamApi\Contracts\Clock;
use PeakRack\UpstreamApi\Contracts\LockRepository;
use PeakRack\UpstreamApi\Contracts\NonceRepository;
use PeakRack\UpstreamApi\Contracts\OperationExecutor;
use PeakRack\UpstreamApi\Contracts\OperationRepository;
use PeakRack\UpstreamApi\Domain\Operation;
use RuntimeException;

final class WorkerServiceTest extends TestCase
{
    private const NOW = 1780617600;

    public function testDueTasksAreClaimedAndExecutedOnlyOnce(): void
    {
        [$worker, $repository, , $executor] = $this->worker([
            $this->operation('op-1', 'suspend', 1),
        ]);

        $first = $worker->run('worker-1', 10);
        $second = $worker->run('worker-1', 10);

        $this->assertSame(1, $first['completed']);
        $this->assertSame(0, $second['claimed']);
        $this->assertSame(1, $executor->executeCount);
        $this->assertSame([10, self::NOW, 'worker-1', self::NOW + 60], $repository->lastClaim);
    }

    public function testSafeFailureSchedulesExponentialRetryCappedAtOneHour(): void
    {
        [$worker, $repository, , $executor] = $this->worker([
            $this->operation('op-1', 'suspend', 1),
            $this->operation('op-2', 'renew', 10),
        ]);
        $executor->behavior = 'retry';

        $worker->run('worker-1', 10);

        $first = $repository->saved['op-1'];
        $capped = $repository->saved['op-2'];
        $this->assertSame(Operation::QUEUED, $first->status());
        $this->assertSame(self::NOW + 30, $first->nextAttemptAt());
        $this->assertSame(self::NOW + 3600, $capped->nextAttemptAt());
    }

    public function testTwelfthFailedAttemptBecomesManualReview(): void
    {
        [$worker, $repository, , $executor] = $this->worker([
            $this->operation('op-12', 'create', 12),
        ]);
        $executor->behavior = 'retry';

        $summary = $worker->run('worker-1', 10);

        $this->assertSame(1, $summary['manual_review']);
        $this->assertSame(Operation::MANUAL_REVIEW, $repository->saved['op-12']->status());
    }

    public function testUnknownOutcomeIsVerifiedInsteadOfReplayed(): void
    {
        [$worker, $repository, , $executor] = $this->worker([
            $this->operation('op-unknown', 'create', 1),
        ]);
        $executor->behavior = 'unknown';

        $worker->run('worker-1', 10);
        $pending = $repository->saved['op-unknown'];
        $this->assertSame('verify', $pending->stage());
        $this->assertSame(1, $executor->executeCount);
        $this->assertSame(0, $executor->verifyCount);

        $executor->behavior = 'complete';
        $repository->requeue($pending);
        $worker->run('worker-1', 10);

        $this->assertSame(1, $executor->executeCount);
        $this->assertSame(1, $executor->verifyCount);
        $this->assertSame(Operation::COMPLETED, $repository->saved['op-unknown']->status());
    }

    public function testPreviouslyProcessingOperationIsVerifiedAfterWorkerRecovery(): void
    {
        [$worker, , , $executor] = $this->worker([
            $this->operation('op-recovered', 'create', 2, Operation::PROCESSING, 'provisioning_started'),
        ]);

        $worker->run('worker-1', 10);

        $this->assertSame(0, $executor->executeCount);
        $this->assertSame(1, $executor->verifyCount);
    }

    public function testVerificationFailureStaysOnVerificationPath(): void
    {
        [$worker, $repository, , $executor] = $this->worker([
            $this->operation('op-verify-retry', 'create', 2, Operation::PROCESSING, 'verify'),
        ]);
        $executor->behavior = 'retry';

        $worker->run('worker-1', 10);
        $pending = $repository->saved['op-verify-retry'];
        $this->assertSame(Operation::PROCESSING, $pending->status());
        $this->assertSame('verify', $pending->stage());

        $executor->behavior = 'complete';
        $repository->requeue($pending);
        $worker->run('worker-1', 10);

        $this->assertSame(0, $executor->executeCount);
        $this->assertSame(2, $executor->verifyCount);
    }

    public function testDueCancelOnlyTerminationUsesAuthorizedActionAndRunsCleanup(): void
    {
        [$worker, , $cleanup, $executor] = $this->worker([
            $this->operation('op-terminate', 'terminate_due', 1),
        ]);

        $worker->run('worker-1', 10);

        $this->assertSame(['terminate_due'], $executor->actions);
        $this->assertSame(self::NOW, $cleanup->noncePurgedAt);
        $this->assertSame(self::NOW, $cleanup->lockPurgedAt);
    }

    private function worker(array $operations): array
    {
        $repository = new WorkerServiceFakeRepository($operations);
        $cleanup = new WorkerServiceCleanup();
        $executor = new WorkerServiceFakeExecutor();
        $worker = new WorkerService(
            $repository,
            $cleanup,
            $cleanup,
            $executor,
            new WorkerServiceFakeClock(self::NOW)
        );

        return [$worker, $repository, $cleanup, $executor];
    }

    private function operation(
        string $id,
        string $action,
        int $attemptCount,
        string $status = Operation::QUEUED,
        string $stage = 'accepted'
    ): Operation
    {
        return Operation::restore(
            id: $id,
            apiKeyId: 7,
            action: $action,
            idempotencyKey: 'idempotency-' . $id,
            requestHash: hash('sha256', $id),
            status: $status,
            result: [],
            errorCode: null,
            errorMessage: null,
            localServiceId: 123,
            sanitizedPayload: ['local_service_id' => 123, '_billing_client_id' => 44],
            stage: $stage,
            attemptCount: $attemptCount,
            nextAttemptAt: null
        );
    }
}

final class WorkerServiceFakeRepository implements OperationRepository
{
    public array $saved = [];
    public ?array $lastClaim = null;
    private array $due;

    public function __construct(array $due)
    {
        $this->due = $due;
    }

    public function admit(Operation $operation, array $sanitizedPayload): Operation
    {
        throw new RuntimeException('Admission is not expected in worker tests.');
    }

    public function findById(string $operationId): ?Operation
    {
        return $this->saved[$operationId] ?? null;
    }

    public function findByIdempotency(int $apiKeyId, string $idempotencyKey): ?Operation
    {
        return null;
    }

    public function save(Operation $operation): void
    {
        $this->saved[$operation->id()] = $operation;
    }

    public function claimDue(int $limit, int $now, string $owner, int $lockUntil): array
    {
        $this->lastClaim = [$limit, $now, $owner, $lockUntil];
        $claimed = array_slice($this->due, 0, $limit);
        $this->due = array_slice($this->due, count($claimed));
        return $claimed;
    }

    public function appendEvent(string $operationId, string $stage, array $sanitizedContext): void
    {
    }

    public function requeue(Operation $operation): void
    {
        $this->due[] = $operation;
    }
}

final class WorkerServiceCleanup implements NonceRepository, LockRepository
{
    public ?int $noncePurgedAt = null;
    public ?int $lockPurgedAt = null;

    public function claim(int $apiKeyId, string $nonce, int $expiresAt): bool
    {
        return true;
    }

    public function acquire(string $resource, string $owner, int $expiresAt): bool
    {
        return true;
    }

    public function release(string $resource, string $owner): void
    {
    }

    public function purgeExpired(int $now): int
    {
        if ($this->noncePurgedAt === null) {
            $this->noncePurgedAt = $now;
        } else {
            $this->lockPurgedAt = $now;
        }

        return 0;
    }
}

final class WorkerServiceFakeExecutor implements OperationExecutor
{
    public string $behavior = 'complete';
    public int $executeCount = 0;
    public int $verifyCount = 0;
    public array $actions = [];

    public function execute(Operation $operation): Operation
    {
        $this->executeCount++;
        $this->actions[] = $operation->action();

        if ($this->behavior === 'retry') {
            throw OperationExecutionException::retryable('PROVISIONING_FAILED');
        }
        if ($this->behavior === 'unknown') {
            throw OperationExecutionException::unknownOutcome('PROVISIONING_FAILED');
        }

        return $operation->complete(['service_status' => 'active']);
    }

    public function verify(Operation $operation): Operation
    {
        $this->verifyCount++;

        if ($this->behavior === 'retry') {
            throw OperationExecutionException::retryable('PROVISIONING_FAILED');
        }
        if ($this->behavior === 'unknown') {
            throw OperationExecutionException::unknownOutcome('PROVISIONING_FAILED');
        }

        return $operation->complete(['service_status' => 'active']);
    }
}

final class WorkerServiceFakeClock implements Clock
{
    public function __construct(private readonly int $now)
    {
    }

    public function now(): int
    {
        return $this->now;
    }
}
