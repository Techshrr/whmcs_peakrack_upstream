<?php

namespace PeakRack\Tests\Integration;

use PeakRack\Tests\TestCase;
use PeakRack\Upstream\Api\ApiClient;
use PeakRack\Upstream\Api\ApiException;
use PeakRack\Upstream\Logger;

final class DownstreamApiContractTest extends TestCase
{
    public static function group(): string
    {
        return 'integration-contract';
    }

    public function testSignedHealthRequestAndInvalidSignature(): void
    {
        $server = $this->server();

        try {
            $health = $this->client($server)->health();
            $this->assertSame('v1', $health['data']['protocol']);
            $this->assertSame([], $health['data']['blocking_errors']);

            try {
                $this->client($server, 'wrong-secret')->health();
            } catch (ApiException $exception) {
                $this->assertSame('AUTHENTICATION_FAILED', $exception->errorCode());
                $state = $server->state();
                $this->assertSame(1, $state['valid_requests']);
                $this->assertSame(1, $state['invalid_signatures']);
                return;
            }

            throw new \RuntimeException('Expected invalid signature rejection.');
        } finally {
            $server->stop();
        }
    }

    public function testFailureIsSanitizedAndSsoUrlIsNotLogged(): void
    {
        $server = $this->server();
        $logs = [];
        $logger = new Logger(static function (...$arguments) use (&$logs): void {
            $logs[] = $arguments;
        });
        $client = $this->client($server, 'api-secret', $logger);
        $password = 'never-log-this-password';

        try {
            $failed = false;
            try {
                $client->createService($this->payload(300, 'failure.example.test', $password), 'service:create:300');
            } catch (ApiException $exception) {
                $failed = true;
                $this->assertSame('PROVISIONING_FAILED', $exception->errorCode());
                $this->assertStringNotContains($password, $exception->getMessage());
            }
            $this->assertTrue($failed, 'Expected the mock provisioning request to fail.');

            $response = $client->getSsoUrl(300, 'service:sso:300:contract');
            $url = $response['data']['sso_url'];
            $this->assertStringContains('https://panel.example.test/sso/', $url);

            $serialized = serialize($logs);
            $this->assertStringNotContains($password, $serialized);
            $this->assertStringNotContains($url, $serialized);
            $this->assertStringContains('[REDACTED]', $serialized);
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

    private function client(object $server, string $secret = 'api-secret', ?Logger $logger = null): ApiClient
    {
        return new ApiClient(
            baseUrl: $server->baseUrl(),
            apiKey: 'public-key',
            apiSecret: $secret,
            timeout: 5,
            transport: [$server, 'transport'],
            logger: $logger,
            clock: static fn (): int => 1780660800,
            nonceGenerator: static function (): string {
                static $counter = 0;
                $counter++;
                return 'contract-nonce-' . $counter;
            }
        );
    }

    private function payload(int $serviceId, string $hostname, string $password = 'temporary-secret'): array
    {
        return [
            'local_service_id' => $serviceId,
            'product_id' => 10,
            'billing_cycle' => 'monthly',
            'hostname' => $hostname,
            'password' => $password,
        ];
    }
}
