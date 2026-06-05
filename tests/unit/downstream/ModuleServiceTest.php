<?php

namespace PeakRack\Tests\Unit\Downstream;

use PeakRack\Tests\TestCase;
use PeakRack\Upstream\Api\ApiException;
use PeakRack\Upstream\Api\ApiClientInterface;
use PeakRack\Upstream\Idempotency;
use PeakRack\Upstream\ModuleService;
use PeakRack\Upstream\ServiceProperties;

final class ModuleServiceTest extends TestCase
{
    public function testDuplicateCreateRefusesSecondUpstreamCreate(): void
    {
        [$service, $api] = $this->service(['Upstream Service ID' => '456']);

        $result = $service->create($this->params());

        $this->assertStringContains('already bound', $result);
        $this->assertSame([], $api->calls);
    }

    public function testCompletedCreateReturnsSuccessAndPersistsSafeProperties(): void
    {
        [$service, $api, $store] = $this->service();
        $api->responses['createService'] = $this->response('completed', [
            'upstream_service_id' => 456,
            'upstream_order_id' => 789,
            'upstream_invoice_id' => 321,
            'service_status' => 'active',
            'primary_ip' => '192.0.2.10',
        ]);

        $result = $service->create($this->params());

        $this->assertSame('success', $result);
        $this->assertSame('456', $store->values['Upstream Service ID']);
        $this->assertSame('192.0.2.10', $store->values['Dedicated IP']);
        $this->assertSame('', $store->values['Current Idempotency Key']);
        $this->assertSame('createService', $api->calls[0]['method']);
    }

    public function testProcessingCreatePersistsOperationAndKeepsPendingKey(): void
    {
        [$service, $api, $store] = $this->service();
        $api->responses['createService'] = $this->response('processing');

        $result = $service->create($this->params());

        $this->assertStringContains('processing', strtolower($result));
        $this->assertSame(
            '123e4567-e89b-42d3-a456-426614174000',
            $store->values['Upstream Operation ID']
        );
        $this->assertSame('service:create:123', $store->values['Current Idempotency Key']);
    }

    public function testLifecycleMethodsUseExpectedApiCallsAndClearTerminalKeys(): void
    {
        [$service, $api, $store] = $this->service(['Upstream Service ID' => '456']);
        foreach ([
            'suspendService',
            'unsuspendService',
            'renewService',
            'changePackage',
            'terminateService',
        ] as $method) {
            $api->responses[$method] = $this->response('completed', ['service_status' => 'active']);
        }
        $params = $this->params();
        $params['nextduedate'] = '2026-07-01';

        $this->assertSame('success', $service->suspend($params));
        $this->assertSame('success', $service->unsuspend($params));
        $this->assertSame('success', $service->renew($params));
        $this->assertSame('success', $service->changePackage($params));
        $this->assertSame('success', $service->terminate($params));
        $params['configoption5'] = 'destroy';
        $this->assertSame('success', $service->terminate($params));

        $this->assertSame([
            'suspendService',
            'unsuspendService',
            'renewService',
            'changePackage',
            'terminateService',
            'terminateService',
        ], array_column($api->calls, 'method'));
        $this->assertSame('2026-07-01', $api->calls[2]['arguments'][1]);
        $this->assertSame('cancel_only', $api->calls[4]['arguments'][1]);
        $this->assertSame('destroy', $api->calls[5]['arguments'][1]);
        $this->assertSame('', $store->values['Current Idempotency Key']);
    }

    public function testTerminalApiFailureSavesReadableErrorAndClearsKey(): void
    {
        [$service, $api, $store] = $this->service(['Upstream Service ID' => '456']);
        $api->errors['suspendService'] = new ApiException(
            'The reseller client does not have enough Credit.',
            'INSUFFICIENT_CREDIT',
            422,
            '123e4567-e89b-42d3-a456-426614174000',
            'failed'
        );

        $result = $service->suspend($this->params());

        $this->assertStringContains('INSUFFICIENT_CREDIT', $result);
        $this->assertStringContains('INSUFFICIENT_CREDIT', $store->values['Provisioning Error']);
        $this->assertSame('', $store->values['Current Idempotency Key']);
    }

    private function service(array $values = []): array
    {
        $store = new ModuleServiceFakeStore($values);
        $properties = new ServiceProperties($store);
        $api = new ModuleServiceFakeApi();
        $idempotency = new Idempotency($properties, static fn (): string => 'random-one');
        $service = new ModuleService(
            $api,
            $properties,
            $idempotency,
            static fn (): int => 1780660800
        );

        return [$service, $api, $store];
    }

    private function params(): array
    {
        return [
            'serviceid' => '123',
            'domain' => 'server.example.test',
            'password' => 'temporary-secret',
            'billingcycle' => 'Monthly',
            'configoption1' => '10',
            'configoption2' => 'auto',
            'configoption3' => 'hk-1',
            'configoption4' => 'ubuntu-24.04',
            'configoption5' => 'cancel_only',
            'configoption6' => '30',
        ];
    }

    private function response(string $status, array $data = []): array
    {
        return [
            'success' => true,
            'status' => $status,
            'data' => $data === [] ? null : $data,
            'operation_id' => '123e4567-e89b-42d3-a456-426614174000',
            'error' => null,
        ];
    }
}

final class ModuleServiceFakeApi implements ApiClientInterface
{
    public array $calls = [];
    public array $responses = [];
    public array $errors = [];

    public function health(): array
    {
        return $this->record(__FUNCTION__, []);
    }

    public function catalog(): array
    {
        return $this->record(__FUNCTION__, []);
    }

    public function createService(array $payload, string $idempotencyKey): array
    {
        return $this->record(__FUNCTION__, [$payload, $idempotencyKey]);
    }

    public function getService(int $localServiceId): array
    {
        return $this->record(__FUNCTION__, [$localServiceId]);
    }

    public function getOperation(string $operationId): array
    {
        return $this->record(__FUNCTION__, [$operationId]);
    }

    public function suspendService(int $localServiceId, string $idempotencyKey): array
    {
        return $this->record(__FUNCTION__, [$localServiceId, $idempotencyKey]);
    }

    public function unsuspendService(int $localServiceId, string $idempotencyKey): array
    {
        return $this->record(__FUNCTION__, [$localServiceId, $idempotencyKey]);
    }

    public function terminateService(int $localServiceId, string $mode, string $idempotencyKey): array
    {
        return $this->record(__FUNCTION__, [$localServiceId, $mode, $idempotencyKey]);
    }

    public function renewService(int $localServiceId, string $renewalBoundary, string $idempotencyKey): array
    {
        return $this->record(__FUNCTION__, [$localServiceId, $renewalBoundary, $idempotencyKey]);
    }

    public function changePackage(int $localServiceId, array $payload, string $idempotencyKey): array
    {
        return $this->record(__FUNCTION__, [$localServiceId, $payload, $idempotencyKey]);
    }

    public function getSsoUrl(int $localServiceId, string $idempotencyKey): array
    {
        return $this->record(__FUNCTION__, [$localServiceId, $idempotencyKey]);
    }

    private function record(string $method, array $arguments): array
    {
        $this->calls[] = ['method' => $method, 'arguments' => $arguments];
        if (isset($this->errors[$method])) {
            throw $this->errors[$method];
        }
        return $this->responses[$method] ?? [
            'success' => true,
            'status' => 'completed',
            'data' => null,
            'operation_id' => '123e4567-e89b-42d3-a456-426614174000',
            'error' => null,
        ];
    }
}

final class ModuleServiceFakeStore
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
