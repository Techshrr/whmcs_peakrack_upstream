<?php

namespace PeakRack\Tests\Unit\Upstream;

use PeakRack\Tests\TestCase;
use PeakRack\UpstreamApi\Application\ChangePackageExecutor;
use PeakRack\UpstreamApi\Contracts\BillingGateway;
use PeakRack\UpstreamApi\Contracts\OperationRepository;
use PeakRack\UpstreamApi\Domain\Operation;
use RuntimeException;

final class ChangePackageExecutorTest extends TestCase
{
    public function testDowngradeNeverAddsCreditAndConfirmsTargetPackage(): void
    {
        $billing = new ChangePackageExecutorFakeBilling();
        $services = new ChangePackageExecutorFakeServices();
        $states = [
            ['product_id' => 10, 'billing_cycle' => 'annual'],
            ['product_id' => 11, 'billing_cycle' => 'monthly'],
        ];
        $executor = new ChangePackageExecutor(
            $billing,
            new ChangePackageExecutorFakeOperations(),
            $services,
            static function () use (&$states): array {
                return array_shift($states);
            }
        );

        $result = $executor->execute($this->operation());

        $this->assertSame(Operation::COMPLETED, $result->status());
        $this->assertSame(1, $billing->changeCount);
        $this->assertSame(0, $billing->compensateCount);
        $this->assertSame(true, $result->result()['is_downgrade']);
        $this->assertSame(11, $services->saved->productId());
        $this->assertSame('monthly', $services->saved->billingCycle());
    }

    public function testAlreadyChangedPackageCompletesWithoutBillingAgain(): void
    {
        $billing = new ChangePackageExecutorFakeBilling();
        $services = new ChangePackageExecutorFakeServices();
        $executor = new ChangePackageExecutor(
            $billing,
            new ChangePackageExecutorFakeOperations(),
            $services,
            static fn (): array => ['product_id' => 11, 'billing_cycle' => 'monthly']
        );

        $result = $executor->execute($this->operation());

        $this->assertSame(Operation::COMPLETED, $result->status());
        $this->assertSame(0, $billing->changeCount);
        $this->assertSame(11, $services->saved->productId());
    }

    private function operation(): Operation
    {
        $payload = [
            'local_service_id' => 123,
            'upstream_service_id' => 22,
            'product_id' => 11,
            'billing_cycle' => 'monthly',
            'payment_method' => 'mailin',
            'configoptions' => [1 => 4],
            '_billing_client_id' => 44,
        ];

        return Operation::admit('change-op', 7, 'change_package', 'change:123:11', hash('sha256', 'change'), 123, $payload)
            ->start();
    }
}

final class ChangePackageExecutorFakeBilling implements BillingGateway
{
    public int $changeCount = 0;
    public int $compensateCount = 0;

    public function create(int $clientId, int $productId, string $billingCycle, string $paymentMethod, array $orderFields, string $operationId): array
    {
        throw new RuntimeException('Unexpected create call.');
    }

    public function renew(int $clientId, int $serviceId, string $paymentMethod, ?int $existingInvoiceId, string $operationId): array
    {
        throw new RuntimeException('Unexpected renew call.');
    }

    public function changePackage(int $clientId, int $serviceId, int $productId, string $billingCycle, string $paymentMethod, array $configOptions, string $operationId): array
    {
        $this->changeCount++;
        return [
            'order_id' => 73,
            'invoice_id' => null,
            'applied_credit_amount' => '0.00000000',
            'currency_code' => null,
            'is_downgrade' => true,
        ];
    }

    public function compensateCreate(int $clientId, string $amount, int $orderId, int $invoiceId, string $operationId): void
    {
        $this->compensateCount++;
    }
}

final class ChangePackageExecutorFakeOperations implements OperationRepository
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

final class ChangePackageExecutorFakeServices implements \PeakRack\UpstreamApi\Contracts\ServiceRepository
{
    public ?\PeakRack\UpstreamApi\Domain\ServiceRecord $saved = null;

    public function findByLocalId(int $apiKeyId, int $localServiceId): ?\PeakRack\UpstreamApi\Domain\ServiceRecord
    {
        return new \PeakRack\UpstreamApi\Domain\ServiceRecord(7, 123, 22, 9, 11, 10, 'annual', 'active');
    }

    public function findByUpstreamServiceId(int $upstreamServiceId): ?\PeakRack\UpstreamApi\Domain\ServiceRecord
    {
        return null;
    }

    public function save(\PeakRack\UpstreamApi\Domain\ServiceRecord $service): void
    {
        $this->saved = $service;
    }

    public function isManagedUpstreamService(int $upstreamServiceId): bool
    {
        return true;
    }
}
