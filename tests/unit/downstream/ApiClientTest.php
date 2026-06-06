<?php

namespace PeakRack\Tests\Unit\Downstream;

use InvalidArgumentException;
use PeakRack\Tests\TestCase;
use PeakRack\Upstream\Config;
use PeakRack\Upstream\Api\ApiClient;
use PeakRack\Upstream\Api\ApiException;
use PeakRack\Upstream\Api\RequestSigner;

final class ApiClientTest extends TestCase
{
    public function testSignsRequestsAndForcesSecureTransportOptions(): void
    {
        $requests = [];
        $client = $this->client(
            static function (array $request) use (&$requests): array {
                $requests[] = $request;
                return [
                    'status' => 200,
                    'body' => '{"success":true,"status":"completed","data":{"protocol":"v1"},'
                        . '"operation_id":null,"error":null}',
                ];
            },
            1
        );

        $result = $client->health();
        $request = $requests[0];
        $expectedPath = '/billing/modules/addons/peakrack_upstream_api/api/v1/health';

        $this->assertSame('v1', $result['data']['protocol']);
        $this->assertSame('GET', $request['method']);
        $this->assertSame($expectedPath, $request['path']);
        $this->assertSame('application/json', $request['headers']['Content-Type']);
        $this->assertSame('public-key', $request['headers']['X-PeakRack-Key']);
        $this->assertSame('1710000000', $request['headers']['X-PeakRack-Timestamp']);
        $this->assertSame('nonce-fixed', $request['headers']['X-PeakRack-Nonce']);
        $this->assertSame(
            RequestSigner::sign('api-secret', 'GET', $expectedPath, [], '', 1710000000, 'nonce-fixed'),
            $request['headers']['X-PeakRack-Signature']
        );
        $this->assertTrue($request['options']['verify_peer']);
        $this->assertTrue($request['options']['verify_host']);
        $this->assertFalse($request['options']['follow_redirects']);
        $this->assertSame(5, $request['options']['timeout']);

        $requests = [];
        $this->client(
            static function (array $request) use (&$requests): array {
                $requests[] = $request;
                return [
                    'status' => 200,
                    'body' => '{"success":true,"status":"completed","data":[],"operation_id":null,"error":null}',
                ];
            },
            999
        )->catalog();
        $this->assertSame(120, $requests[0]['options']['timeout']);
    }

    public function testRequiresHttpsAndRejectsUrlCredentials(): void
    {
        $transport = static fn (): array => [];

        $this->assertThrows(
            fn () => new ApiClient('http://upstream.example.test/api/v1', 'key', 'secret', 30, $transport),
            InvalidArgumentException::class
        );
        $this->assertThrows(
            fn () => new ApiClient('https://user@upstream.example.test/api/v1', 'key', 'secret', 30, $transport),
            InvalidArgumentException::class
        );
    }

    public function testRejectsHeadersLongerThanUpstreamSchemaLimits(): void
    {
        $transport = static fn (): array => [];

        $this->assertSame(160, Config::MAX_IDEMPOTENCY_KEY_LENGTH);
        $this->assertSame(128, Config::MAX_NONCE_LENGTH);
        $this->assertThrows(
            fn () => $this->client($transport)->suspendService(123, str_repeat('a', 161)),
            InvalidArgumentException::class,
            'idempotency key'
        );
        $this->assertThrows(
            fn () => (new ApiClient(
                baseUrl: 'https://upstream.example.test/api/v1',
                apiKey: 'key',
                apiSecret: 'secret',
                timeout: 30,
                transport: $transport,
                clock: static fn (): int => 1710000000,
                nonceGenerator: static fn (): string => str_repeat('n', 129)
            ))->health(),
            \RuntimeException::class,
            'authentication values'
        );
    }

    public function testParsesAcceptedProcessingResponseAndSendsWriteIdempotency(): void
    {
        $requests = [];
        $client = $this->client(static function (array $request) use (&$requests): array {
            $requests[] = $request;
            return [
                'status' => 202,
                'body' => '{"success":true,"status":"processing","data":null,'
                    . '"operation_id":"123e4567-e89b-42d3-a456-426614174000","error":null}',
            ];
        });

        $result = $client->createService([
            'local_service_id' => 123,
            'product_id' => 10,
            'billing_cycle' => 'monthly',
            'hostname' => 'server.example.test',
            'password' => 'temporary-secret',
        ], 'service:create:123');

        $this->assertSame('processing', $result['status']);
        $this->assertSame('service:create:123', $requests[0]['headers']['Idempotency-Key']);
        $this->assertStringContains('"password":"temporary-secret"', $requests[0]['body']);
    }

    public function testRenewalAlwaysSendsRequiredBoundary(): void
    {
        $requests = [];
        $client = $this->client(static function (array $request) use (&$requests): array {
            $requests[] = $request;
            return [
                'status' => 200,
                'body' => '{"success":true,"status":"completed","data":{"next_due_date":"2026-07-01"},'
                    . '"operation_id":"123e4567-e89b-42d3-a456-426614174000","error":null}',
            ];
        });

        $client->renewService(123, '2026-06-01', 'service:renew:123:2026-06-01');

        $this->assertSame(
            '{"renewal_boundary":"2026-06-01"}',
            $requests[0]['body']
        );
        $this->assertThrows(
            fn () => $client->renewService(123, '2026-02-30', 'service:renew:123:invalid'),
            InvalidArgumentException::class
        );
    }

    public function testProcessingResponseRequiresOperationId(): void
    {
        $client = $this->client(static fn (): array => [
            'status' => 202,
            'body' => '{"success":true,"status":"processing","data":null,"operation_id":null,"error":null}',
        ]);

        $this->assertThrows(
            fn () => $client->suspendService(123, 'service:suspend:123:1'),
            ApiException::class,
            'mismatched'
        );
    }

    public function testGetsAnOperationByValidatedUuid(): void
    {
        $requests = [];
        $client = $this->client(static function (array $request) use (&$requests): array {
            $requests[] = $request;
            return [
                'status' => 202,
                'body' => '{"success":true,"status":"processing","data":null,'
                    . '"operation_id":"123e4567-e89b-42d3-a456-426614174000","error":null}',
            ];
        });

        $response = $client->getOperation('123e4567-e89b-42d3-a456-426614174000');

        $this->assertSame('processing', $response['status']);
        $this->assertSame(
            '/billing/modules/addons/peakrack_upstream_api/api/v1/operations/'
                . '123e4567-e89b-42d3-a456-426614174000',
            $requests[0]['path']
        );
        $this->assertThrows(
            fn () => $client->getOperation('not-an-operation-id'),
            InvalidArgumentException::class
        );
    }

    public function testMalformedOrMismatchedResponsesThrowSanitizedApiException(): void
    {
        foreach ([
            ['status' => 502, 'body' => 'database password=secret-value'],
            [
                'status' => 200,
                'body' => '{"success":true,"status":"completed","data":null,"operation_id":null,'
                    . '"error":{"code":"BAD","message":"secret-value"}}',
            ],
        ] as $response) {
            try {
                $this->client(static fn (): array => $response)->health();
            } catch (ApiException $exception) {
                $this->assertStringNotContains('secret-value', $exception->getMessage());
                $this->assertStringNotContains('password', $exception->getMessage());
                continue;
            }

            throw new \RuntimeException('Expected a sanitized API exception.');
        }
    }

    private function client(callable $transport, int $timeout = 30): ApiClient
    {
        return new ApiClient(
            baseUrl: 'https://upstream.example.test/billing/modules/addons/peakrack_upstream_api/api/v1',
            apiKey: 'public-key',
            apiSecret: 'api-secret',
            timeout: $timeout,
            transport: $transport,
            clock: static fn (): int => 1710000000,
            nonceGenerator: static fn (): string => 'nonce-fixed'
        );
    }
}
