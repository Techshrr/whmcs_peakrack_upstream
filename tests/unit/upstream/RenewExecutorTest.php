<?php

namespace PeakRack\Tests\Unit\Upstream;

use PeakRack\Tests\TestCase;
use PeakRack\UpstreamApi\Application\OperationExecutionException;
use PeakRack\UpstreamApi\Application\RenewExecutor;
use PeakRack\UpstreamApi\Contracts\BillingGateway;
use PeakRack\UpstreamApi\Contracts\OperationRepository;
use PeakRack\UpstreamApi\Domain\Operation;
use RuntimeException;

final class RenewExecutorTest extends TestCase
{
    public function testRenewCompletesOnlyAfterDueBoundaryAdvances(): void
    {
        $billing = new RenewExecutorFakeBilling();
        $states = [
            ['next_due_date' => '2026-07-01'],
            ['next_due_date' => '2026-08-01'],
        ];
        $executor = new RenewExecutor(
            $billing,
            new RenewExecutorFakeOperations(),
            static function () use (&$states): array {
                return array_shift($states);
            }
        );

        $result = $executor->execute($this->operation());

        $this->assertSame(Operation::COMPLETED, $result->status());
        $this->assertSame(1, $billing->renewCount);
    }

    public function testRecoveryVerifiesWithoutRepeatingBilling(): void
    {
        $billing = new RenewExecutorFakeBilling();
        $states = [
            ['next_due_date' => '2026-07-01'],
            ['next_due_date' => '2026-07-01'],
            ['next_due_date' => '2026-08-01'],
        ];
        $operations = new RenewExecutorFakeOperations();
        $executor = new RenewExecutor(
            $billing,
            $operations,
            static function () use (&$states): array {
                return array_shift($states);
            }
        );

        $this->assertThrows(
            fn () => $executor->execute($this->operation()),
            OperationExecutionException::class
        );
        $pending = $operations->last;
        $this->assertTrue($pending instanceof Operation);
        $completed = $executor->verify($pending);

        $this->assertSame(Operation::COMPLETED, $completed->status());
        $this->assertSame(1, $billing->renewCount);
    }

    public function testRenewLocatesExistingInvoiceBeforeCreatingAnother(): void
    {
        $billing = new RenewExecutorFakeBilling();
        $finderCalls = [];
        $executor = new RenewExecutor(
            $billing,
            new RenewExecutorFakeOperations(),
            static function () use (&$states): array {
                return array_shift($states);
            },
            static function (
                int $clientId,
                int $serviceId,
                string $boundary,
                string $operationId
            ) use (&$finderCalls): int {
                $finderCalls[] = [$clientId, $serviceId, $boundary, $operationId];
                return 51;
            }
        );
        $states = [
            ['next_due_date' => '2026-07-01'],
            ['next_due_date' => '2026-08-01'],
        ];

        $executor->execute($this->operation());

        $this->assertSame([[44, 22, '2026-07-01', 'renew-op']], $finderCalls);
        $this->assertSame(51, $billing->lastExistingInvoiceId);
    }

    public function testAlreadyAdvancedBoundaryCompletesWithoutBillingAgain(): void
    {
        $billing = new RenewExecutorFakeBilling();
        $executor = new RenewExecutor(
            $billing,
            new RenewExecutorFakeOperations(),
            static fn (): array => ['next_due_date' => '2026-08-01']
        );

        $result = $executor->execute($this->operation());

        $this->assertSame(Operation::COMPLETED, $result->status());
        $this->assertSame(0, $billing->renewCount);
    }

    private function operation(): Operation
    {
        $payload = [
            'upstream_service_id' => 22,
            'payment_method' => 'mailin',
            'renewal_boundary' => '2026-07-01',
            'existing_invoice_id' => null,
            '_billing_client_id' => 44,
        ];

        return Operation::admit('renew-op', 7, 'renew', 'renew:123:2026-07-01', hash('sha256', 'renew'), 123, $payload)
            ->start();
    }
}

final class RenewExecutorFakeBilling implements BillingGateway
{
    public int $renewCount = 0;
    public ?int $lastExistingInvoiceId = null;

    public function create(int $clientId, int $productId, string $billingCycle, string $paymentMethod, array $orderFields, string $operationId): array
    {
        throw new RuntimeException('Unexpected create call.');
    }

    public function renew(int $clientId, int $serviceId, string $paymentMethod, ?int $existingInvoiceId, string $operationId): array
    {
        $this->renewCount++;
        $this->lastExistingInvoiceId = $existingInvoiceId;
        return [
            'order_id' => 19,
            'invoice_id' => 52,
            'service_id' => $serviceId,
            'applied_credit_amount' => '5.00',
            'currency_code' => 'USD',
        ];
    }

    public function changePackage(int $clientId, int $serviceId, int $productId, string $billingCycle, string $paymentMethod, array $configOptions, string $operationId): array
    {
        throw new RuntimeException('Unexpected change call.');
    }

    public function compensateCreate(int $clientId, string $amount, int $orderId, int $invoiceId, string $operationId): void
    {
        throw new RuntimeException('Unexpected compensation call.');
    }
}

final class RenewExecutorFakeOperations implements OperationRepository
{
    public ?Operation $last = null;

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
        $this->last = $operation;
    }

    public function claimDue(int $limit, int $now, string $owner, int $lockUntil): array
    {
        return [];
    }

    public function appendEvent(string $operationId, string $stage, array $sanitizedContext): void
    {
    }
}
