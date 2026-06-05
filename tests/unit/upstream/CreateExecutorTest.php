<?php

namespace PeakRack\Tests\Unit\Upstream;

use PeakRack\Tests\TestCase;
use PeakRack\UpstreamApi\Application\CreateExecutor;
use PeakRack\UpstreamApi\Application\OperationExecutionException;
use PeakRack\UpstreamApi\Contracts\BillingGateway;
use PeakRack\UpstreamApi\Contracts\OperationRepository;
use PeakRack\UpstreamApi\Contracts\ProvisioningGateway;
use PeakRack\UpstreamApi\Contracts\ServiceRepository;
use PeakRack\UpstreamApi\Domain\Operation;
use PeakRack\UpstreamApi\Domain\ServiceRecord;
use RuntimeException;

final class CreateExecutorTest extends TestCase
{
    public function testCreatePersistsBillingReferencesBeforeConfirmingDelivery(): void
    {
        $trace = new CreateExecutorTrace();
        $billing = new CreateExecutorFakeBilling($trace);
        $provisioning = new CreateExecutorFakeProvisioning($trace, [[
            'service_status' => 'active',
            'primary_ip' => '192.0.2.10',
            'panel_url' => 'https://panel.example.test/',
        ]]);
        $services = new CreateExecutorFakeServices();
        $operations = new CreateExecutorFakeOperations();
        $executor = new CreateExecutor($billing, $provisioning, $services, $operations);

        $result = $executor->execute($this->operation());

        $this->assertSame(Operation::COMPLETED, $result->status());
        $this->assertSame(['billing.create', 'delivery.read'], $trace->events);
        $this->assertContains('provisioning_started', $operations->stages);
        $this->assertSame('12.34000000', $result->result()['applied_credit_amount']);
        $this->assertSame('active', $services->saved[count($services->saved) - 1]->status());
        $this->assertSame('temporary-secret', $billing->lastOrderFields['password']);
    }

    public function testProcessingDeliveryDoesNotCompensate(): void
    {
        $trace = new CreateExecutorTrace();
        $billing = new CreateExecutorFakeBilling($trace);
        $executor = new CreateExecutor(
            $billing,
            new CreateExecutorFakeProvisioning($trace, [['service_status' => 'provisioning']]),
            new CreateExecutorFakeServices(),
            new CreateExecutorFakeOperations()
        );

        $this->assertThrows(
            fn () => $executor->execute($this->operation()),
            OperationExecutionException::class
        );
        $this->assertSame(0, $billing->compensateCount);
    }

    public function testConfirmedFailureCompensatesExactlyOnce(): void
    {
        $trace = new CreateExecutorTrace();
        $billing = new CreateExecutorFakeBilling($trace);
        $operations = new CreateExecutorFakeOperations();
        $executor = new CreateExecutor(
            $billing,
            new CreateExecutorFakeProvisioning($trace, [
                ['service_status' => 'failed'],
                ['service_status' => 'failed'],
            ]),
            new CreateExecutorFakeServices(),
            $operations
        );

        $failed = $executor->execute($this->operation());
        $this->assertSame(Operation::FAILED, $failed->status());
        $this->assertSame(1, $billing->compensateCount);
        $this->assertContains('compensation_pending', $operations->stages);
        $this->assertContains('credit_restored', $operations->stages);

        $recovered = Operation::restore(
            id: 'create-op',
            apiKeyId: 7,
            action: 'create',
            idempotencyKey: 'create:123',
            requestHash: hash('sha256', 'create'),
            status: Operation::PROCESSING,
            result: $failed->result(),
            localServiceId: 123,
            sanitizedPayload: $this->payload('[REDACTED]'),
            stage: 'credit_restored',
            attemptCount: 2
        );
        $executor->verify($recovered->resumeVerification());
        $this->assertSame(1, $billing->compensateCount);
    }

    public function testAmbiguousCompensationNeverCompletesIfDeliveryLaterLooksActive(): void
    {
        $trace = new CreateExecutorTrace();
        $billing = new CreateExecutorFakeBilling($trace);
        $operation = Operation::restore(
            id: 'create-op',
            apiKeyId: 7,
            action: 'create',
            idempotencyKey: 'create:123',
            requestHash: hash('sha256', 'create'),
            status: Operation::PROCESSING,
            result: [
                'order_id' => 9,
                'invoice_id' => 11,
                'service_id' => 22,
                'applied_credit_amount' => '12.34000000',
            ],
            localServiceId: 123,
            sanitizedPayload: $this->payload('[REDACTED]'),
            stage: 'compensation_pending',
            attemptCount: 2
        );
        $executor = new CreateExecutor(
            $billing,
            new CreateExecutorFakeProvisioning($trace, [['service_status' => 'active']]),
            new CreateExecutorFakeServices(),
            new CreateExecutorFakeOperations()
        );

        $result = $executor->verify($operation->resumeVerification());

        $this->assertSame(Operation::MANUAL_REVIEW, $result->status());
        $this->assertSame(0, $billing->compensateCount);
    }

    public function testExistingFailedBindingDoesNotBecomeCompletedCreate(): void
    {
        $trace = new CreateExecutorTrace();
        $existing = new ServiceRecord(7, 123, 22, 9, 11, 10, 'monthly', 'failed');
        $executor = new CreateExecutor(
            new CreateExecutorFakeBilling($trace),
            new CreateExecutorFakeProvisioning($trace, []),
            new CreateExecutorFakeServices($existing),
            new CreateExecutorFakeOperations()
        );

        $result = $executor->execute($this->operation());

        $this->assertSame(Operation::FAILED, $result->status());
    }

    private function operation(): Operation
    {
        return Operation::admit(
            id: 'create-op',
            apiKeyId: 7,
            action: 'create',
            idempotencyKey: 'create:123',
            requestHash: hash('sha256', 'create'),
            localServiceId: 123,
            sanitizedPayload: $this->payload('[REDACTED]'),
            executionPayload: $this->payload('temporary-secret')
        )->start();
    }

    private function payload(string $password): array
    {
        return [
            'local_service_id' => 123,
            'product_id' => 10,
            'billing_cycle' => 'monthly',
            'payment_method' => 'mailin',
            'hostname' => 'server.example.test',
            'password' => $password,
            'delivery_mappings' => [],
            '_billing_client_id' => 44,
        ];
    }
}

final class CreateExecutorTrace
{
    public array $events = [];
}

final class CreateExecutorFakeBilling implements BillingGateway
{
    public int $compensateCount = 0;
    public array $lastOrderFields = [];

    public function __construct(private readonly CreateExecutorTrace $trace)
    {
    }

    public function create(
        int $clientId,
        int $productId,
        string $billingCycle,
        string $paymentMethod,
        array $orderFields,
        string $operationId
    ): array {
        $this->trace->events[] = 'billing.create';
        $this->lastOrderFields = $orderFields;
        return [
            'order_id' => 9,
            'invoice_id' => 11,
            'service_id' => 22,
            'applied_credit_amount' => '12.34000000',
            'currency_code' => 'USD',
        ];
    }

    public function renew(int $clientId, int $serviceId, string $paymentMethod, ?int $existingInvoiceId, string $operationId): array
    {
        throw new RuntimeException('Unexpected renew call.');
    }

    public function changePackage(
        int $clientId,
        int $serviceId,
        int $productId,
        string $billingCycle,
        string $paymentMethod,
        array $configOptions,
        string $operationId
    ): array {
        throw new RuntimeException('Unexpected change call.');
    }

    public function compensateCreate(int $clientId, string $amount, int $orderId, int $invoiceId, string $operationId): void
    {
        $this->compensateCount++;
    }
}

final class CreateExecutorFakeProvisioning implements ProvisioningGateway
{
    public function __construct(
        private readonly CreateExecutorTrace $trace,
        private array $deliveries
    ) {
    }

    public function create(int $serviceId, string $operationId): array
    {
        return [];
    }

    public function suspend(int $serviceId, string $reason, string $operationId): array
    {
        return [];
    }

    public function unsuspend(int $serviceId, string $operationId): array
    {
        return [];
    }

    public function terminate(int $serviceId, string $operationId): array
    {
        return [];
    }

    public function readDelivery(int $serviceId, array $mappings): array
    {
        $this->trace->events[] = 'delivery.read';
        $delivery = array_shift($this->deliveries);
        return is_array($delivery) ? $delivery : ['service_status' => 'unknown'];
    }

    public function sso(int $serviceId, array $allowedHosts, string $operationId): string
    {
        throw new RuntimeException('Unexpected SSO call.');
    }
}

final class CreateExecutorFakeServices implements ServiceRepository
{
    public array $saved = [];

    public function __construct(private readonly ?ServiceRecord $existing = null)
    {
    }

    public function findByLocalId(int $apiKeyId, int $localServiceId): ?ServiceRecord
    {
        return $this->existing;
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
        return false;
    }
}

final class CreateExecutorFakeOperations implements OperationRepository
{
    public array $stages = [];

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
        $this->stages[] = $operation->stage();
    }

    public function claimDue(int $limit, int $now, string $owner, int $lockUntil): array
    {
        return [];
    }

    public function appendEvent(string $operationId, string $stage, array $sanitizedContext): void
    {
    }
}
