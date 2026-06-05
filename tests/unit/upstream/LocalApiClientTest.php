<?php

namespace PeakRack\Tests\Unit\Upstream;

use InvalidArgumentException;
use PeakRack\Tests\TestCase;
use PeakRack\UpstreamApi\Domain\ValidationException;
use PeakRack\UpstreamApi\Whmcs\LocalApiClient;
use RuntimeException;

final class LocalApiClientTest extends TestCase
{
    public function testCallsOnlyAllowlistedCommandWithExactParameters(): void
    {
        $calls = [];
        $client = new LocalApiClient(
            static function (string $command, array $parameters, ?string $adminUsername) use (&$calls): array {
                $calls[] = [$command, $parameters, $adminUsername];
                return ['result' => 'success', 'invoiceid' => 12];
            },
            'api-admin'
        );

        $result = $client->call('GetInvoice', ['invoiceid' => 12]);

        $this->assertSame(12, $result['invoiceid']);
        $this->assertSame([['GetInvoice', ['invoiceid' => 12], 'api-admin']], $calls);
        $this->assertThrows(
            static fn () => $client->call('DeleteClient', ['clientid' => 44]),
            InvalidArgumentException::class,
            'not allowlisted'
        );
    }

    public function testConvertsFailuresToSanitizedDomainException(): void
    {
        $client = new LocalApiClient(
            static fn (): array => [
                'result' => 'error',
                'message' => 'SQL failed with api_secret=do-not-leak',
            ]
        );

        try {
            $client->call('GetInvoice', ['invoiceid' => 12]);
        } catch (ValidationException $exception) {
            $this->assertSame(500, $exception->httpStatus());
            $this->assertStringContains('GetInvoice', $exception->getMessage());
            $this->assertStringNotContains('do-not-leak', $exception->getMessage());
            $this->assertStringNotContains('SQL', $exception->getMessage());
            return;
        }

        throw new RuntimeException('Expected a sanitized Local API exception.');
    }
}
