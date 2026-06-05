<?php

namespace PeakRack\Tests\Unit\Upstream;

use InvalidArgumentException;
use PeakRack\Tests\TestCase;
use PeakRack\UpstreamApi\Config;
use PeakRack\UpstreamApi\Http\Request;

final class RequestTest extends TestCase
{
    public function testParsesJsonAndRejectsUnknownFields(): void
    {
        $request = new Request(
            'POST',
            '/services',
            [],
            ['Content-Type' => 'application/json; charset=utf-8'],
            '{"local_service_id":123}',
            '192.0.2.10'
        );

        $this->assertSame(
            ['local_service_id' => 123],
            $request->json(['local_service_id'], ['local_service_id'])
        );

        $unknown = new Request(
            'POST',
            '/services',
            [],
            ['Content-Type' => 'application/json'],
            '{"local_service_id":123,"client_id":456}',
            '192.0.2.10'
        );

        $this->assertThrows(
            static fn () => $unknown->json(['local_service_id'], ['local_service_id']),
            InvalidArgumentException::class,
            'Unknown JSON field'
        );
    }

    public function testRejectsNonJsonInvalidJsonAndListBodies(): void
    {
        $notJson = new Request('POST', '/services', [], ['Content-Type' => 'text/plain'], '{}', '192.0.2.10');
        $invalid = new Request('POST', '/services', [], ['Content-Type' => 'application/json'], '{', '192.0.2.10');
        $list = new Request('POST', '/services', [], ['Content-Type' => 'application/json'], '[1,2]', '192.0.2.10');

        $this->assertThrows(
            static fn () => $notJson->json([]),
            InvalidArgumentException::class,
            'application/json'
        );
        $this->assertThrows(
            static fn () => $invalid->json([]),
            InvalidArgumentException::class,
            'valid JSON'
        );
        $this->assertThrows(
            static fn () => $list->json([]),
            InvalidArgumentException::class,
            'JSON object'
        );
    }

    public function testRejectsOversizedBodyAndMissingRequiredField(): void
    {
        $oversized = new Request(
            'POST',
            '/services',
            [],
            ['Content-Type' => 'application/json'],
            str_repeat('a', Config::MAX_REQUEST_BODY_BYTES + 1),
            '192.0.2.10'
        );
        $missing = new Request('POST', '/services', [], ['Content-Type' => 'application/json'], '{}', '192.0.2.10');

        $this->assertThrows(
            static fn () => $oversized->json([]),
            InvalidArgumentException::class,
            'maximum'
        );
        $this->assertThrows(
            static fn () => $missing->json(['local_service_id'], ['local_service_id']),
            InvalidArgumentException::class,
            'required'
        );
    }

    public function testNormalizesHeadersButPreservesExactPathAndBody(): void
    {
        $request = new Request(
            'post',
            '/services?not-part-of-path',
            ['b' => '2', 'a' => '1'],
            ['X-PeakRack-Key' => 'public-key'],
            '{"exact":true}',
            '2001:db8::1'
        );

        $this->assertSame('POST', $request->method());
        $this->assertSame('/services?not-part-of-path', $request->path());
        $this->assertSame('public-key', $request->header('x-peakrack-key'));
        $this->assertSame('{"exact":true}', $request->rawBody());
        $this->assertSame('2001:db8::1', $request->sourceIp());
        $this->assertSame(['b' => '2', 'a' => '1'], $request->query());
    }
}
