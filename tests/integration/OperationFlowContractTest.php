<?php

namespace PeakRack\Tests\Integration;

use PeakRack\Tests\TestCase;
use PeakRack\Upstream\Api\ApiClient;
use PeakRack\Upstream\Api\ApiException;

final class OperationFlowContractTest extends TestCase
{
    public static function group(): string
    {
        return 'integration-contract';
    }

    public function testCompletedCreateIsIdempotentAndConflictingReuseIsRejected(): void
    {
        $server = $this->server();
        $client = $this->client($server);
        $key = 'service:create:100';
        $payload = $this->payload(100, 'completed.example.test');

        try {
            $first = $client->createService($payload, $key);
            $repeated = $client->createService($payload, $key);

            $this->assertSame('completed', $first['status']);
            $this->assertSame($first, $repeated);
            $this->assertSame(1, $server->state()['create_executions']);

            try {
                $client->createService($this->payload(100, 'different.example.test'), $key);
            } catch (ApiException $exception) {
                $this->assertSame('IDEMPOTENCY_CONFLICT', $exception->errorCode());
                return;
            }

            throw new \RuntimeException('Expected idempotency conflict.');
        } finally {
            $server->stop();
        }
    }

    public function testProcessingCreateCompletesOnServicePoll(): void
    {
        $server = $this->server();
        $client = $this->client($server);

        try {
            $created = $client->createService(
                $this->payload(200, 'processing.example.test'),
                'service:create:200'
            );
            $operation = $client->getOperation((string) $created['operation_id']);
            $polled = $client->getService(200);

            $this->assertSame('processing', $created['status']);
            $this->assertSame('completed', $operation['status']);
            $this->assertSame('active', $operation['data']['service_status']);
            $this->assertSame('completed', $polled['status']);
            $this->assertSame('active', $polled['data']['service_status']);
            $this->assertSame(2000, $polled['data']['upstream_service_id']);
        } finally {
            $server->stop();
        }
    }

    private function server(): object
    {
        $fixture = PEAKRACK_UPSTREAM_ROOT . '/tests/fixtures/mockserver/state.php';
        $this->assertFileExists($fixture);
        require_once $fixture;

        return new \PeakRackMockServerHarness();
    }

    private function client(object $server): ApiClient
    {
        return new ApiClient(
            baseUrl: $server->baseUrl(),
            apiKey: 'public-key',
            apiSecret: 'api-secret',
            timeout: 5,
            transport: [$server, 'transport'],
            clock: static fn (): int => 1780660800,
            nonceGenerator: static function (): string {
                static $counter = 0;
                $counter++;
                return 'flow-nonce-' . $counter;
            }
        );
    }

    private function payload(int $serviceId, string $hostname): array
    {
        return [
            'local_service_id' => $serviceId,
            'product_id' => 10,
            'billing_cycle' => 'monthly',
            'hostname' => $hostname,
            'password' => 'temporary-secret',
        ];
    }
}
