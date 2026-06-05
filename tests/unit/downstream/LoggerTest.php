<?php

namespace PeakRack\Tests\Unit\Downstream;

use PeakRack\Tests\TestCase;
use PeakRack\Upstream\Logger;

final class LoggerTest extends TestCase
{
    public function testRecursivelyRedactsModuleCallRequestAndResponse(): void
    {
        $calls = [];
        $logger = new Logger(static function (...$arguments) use (&$calls): void {
            $calls[] = $arguments;
        });

        $logger->log('POST /services', [
            'headers' => [
                'X-PeakRack-Key' => 'public-key-value',
                'X-PeakRack-Signature' => 'signature-value',
            ],
            'body' => [
                'password' => 'temporary-secret',
                'nested' => ['token' => 'token-value'],
            ],
        ], [
            'data' => ['sso_url' => 'https://panel.example.test/sso-token'],
            'status' => 'completed',
        ]);

        $serialized = serialize($calls);
        foreach ([
            'public-key-value',
            'signature-value',
            'temporary-secret',
            'token-value',
            'https://panel.example.test/sso-token',
        ] as $secret) {
            $this->assertStringNotContains($secret, $serialized);
        }
        $this->assertStringContains('[REDACTED]', $serialized);
        $this->assertSame('peakrackupstream', $calls[0][0]);
        $this->assertSame('POST /services', $calls[0][1]);
    }
}
