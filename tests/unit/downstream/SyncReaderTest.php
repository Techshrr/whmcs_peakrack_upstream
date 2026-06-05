<?php

namespace PeakRack\Tests\Unit\Downstream;

use PeakRack\Tests\TestCase;
use PeakRack\Upstream\Api\ApiClientInterface;
use PeakRack\Upstream\Api\ApiException;
use PeakRack\Upstream\Idempotency;
use PeakRack\Upstream\ServiceProperties;
use PeakRack\Upstream\SyncReader;

final class SyncReaderTest extends TestCase
{
    private const OPERATION_ID = '123e4567-e89b-42d3-a456-426614174000';
    private const IDEMPOTENCY_KEY = 'service:create:123';

    public function testPendingOperationIsPolledWithoutClearingItsIdempotencyKey(): void
    {
        [$reader, $api, $store] = $this->reader();
        $api->responses['getOperation'] = $this->response('processing');

        $response = $reader->read(123);

        $this->assertSame('processing', $response['status']);
        $this->assertSame(['getOperation'], $api->calls);
        $this->assertSame(self::IDEMPOTENCY_KEY, $store->values['Current Idempotency Key']);
    }

    public function testCompletedOperationClearsItsKeyAndReturnsConfirmedServiceState(): void
    {
        [$reader, $api, $store] = $this->reader();
        $api->responses['getOperation'] = $this->response('completed');
        $api->responses['getService'] = $this->response('completed', [
            'upstream_service_id' => 456,
            'service_status' => 'active',
        ]);

        $response = $reader->read(123);

        $this->assertSame('active', $response['data']['service_status']);
        $this->assertSame(['getOperation', 'getService'], $api->calls);
        $this->assertSame('', $store->values['Current Idempotency Key']);
    }

    public function testCompletedOperationKeepsItsKeyUntilConfirmedServiceStateCanBeRead(): void
    {
        [$reader, $api, $store] = $this->reader();
        $api->responses['getOperation'] = $this->response('completed');
        $api->errors['getService'] = new ApiException(
            'The upstream API request could not be completed.'
        );

        $this->assertThrows(fn () => $reader->read(123), ApiException::class);

        $this->assertSame(['getOperation', 'getService'], $api->calls);
        $this->assertSame(self::IDEMPOTENCY_KEY, $store->values['Current Idempotency Key']);
    }

    public function testTerminalOperationFailureClearsItsKeyAndRecordsSafeAdminState(): void
    {
        [$reader, $api, $store] = $this->reader();
        $api->errors['getOperation'] = new ApiException(
            'Provisioning failed.',
            'PROVISIONING_FAILED',
            422,
            self::OPERATION_ID,
            'failed'
        );

        $this->assertThrows(fn () => $reader->read(123), ApiException::class);

        $this->assertSame('', $store->values['Current Idempotency Key']);
        $this->assertSame('failed', $store->values['Upstream Status']);
        $this->assertStringContains('PROVISIONING_FAILED', $store->values['Provisioning Error']);
        $this->assertSame('2026-06-05T00:00:00Z', $store->values['Last Sync Time']);
    }

    private function reader(): array
    {
        $api = new SyncReaderFakeApi();
        $store = new SyncReaderFakeStore([
            'Upstream Operation ID' => self::OPERATION_ID,
            'Current Idempotency Key' => self::IDEMPOTENCY_KEY,
        ]);
        $properties = new ServiceProperties($store);

        return [
            new SyncReader(
                $api,
                $properties,
                new Idempotency($properties),
                static fn (): int => 1780617600
            ),
            $api,
            $store,
        ];
    }

    private function response(string $status, ?array $data = null): array
    {
        return [
            'success' => true,
            'status' => $status,
            'data' => $data,
            'operation_id' => self::OPERATION_ID,
            'error' => null,
        ];
    }
}

final class SyncReaderFakeApi implements ApiClientInterface
{
    public array $calls = [];
    public array $responses = [];
    public array $errors = [];

    public function health(): array
    {
        return [];
    }

    public function catalog(): array
    {
        return [];
    }

    public function createService(array $payload, string $idempotencyKey): array
    {
        return [];
    }

    public function getService(int $localServiceId): array
    {
        return $this->record(__FUNCTION__);
    }

    public function getOperation(string $operationId): array
    {
        return $this->record(__FUNCTION__);
    }

    public function suspendService(int $localServiceId, string $idempotencyKey): array
    {
        return [];
    }

    public function unsuspendService(int $localServiceId, string $idempotencyKey): array
    {
        return [];
    }

    public function terminateService(int $localServiceId, string $mode, string $idempotencyKey): array
    {
        return [];
    }

    public function renewService(int $localServiceId, string $renewalBoundary, string $idempotencyKey): array
    {
        return [];
    }

    public function changePackage(int $localServiceId, array $payload, string $idempotencyKey): array
    {
        return [];
    }

    public function getSsoUrl(int $localServiceId, string $idempotencyKey): array
    {
        return [];
    }

    private function record(string $method): array
    {
        $this->calls[] = $method;
        if (isset($this->errors[$method])) {
            throw $this->errors[$method];
        }

        return $this->responses[$method] ?? [];
    }
}

final class SyncReaderFakeStore
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
