<?php

namespace PeakRack\Tests\Unit\Downstream;

use PeakRack\Tests\TestCase;
use PeakRack\Upstream\Api\RequestSigner;

final class RequestSignerTest extends TestCase
{
    public function testSignsTheSharedProtocolVector(): void
    {
        $body = '{"hostname":"example.com"}';
        $canonical = "POST\n/services\npage=1&sort=name\n"
            . hash('sha256', $body)
            . "\n1710000000\nnonce-123";

        $this->assertSame(
            hash_hmac('sha256', $canonical, 'secret'),
            RequestSigner::sign(
                'secret',
                'post',
                '/services',
                ['sort' => 'name', 'page' => '1'],
                $body,
                1710000000,
                'nonce-123'
            )
        );
    }

    public function testCanonicalizesRepeatedAndEncodedQueryValues(): void
    {
        $canonical = RequestSigner::canonicalize(
            'GET',
            '/catalog',
            ['filter' => ['z value', 'a/value'], 'empty' => ''],
            '',
            1710000001,
            'nonce-encoded'
        );

        $expectedQuery = 'empty=&filter=a%2Fvalue&filter=z%20value';
        $this->assertStringContains("\n{$expectedQuery}\n", $canonical);
        $this->assertStringContains("\n" . hash('sha256', '') . "\n", $canonical);
    }
}
