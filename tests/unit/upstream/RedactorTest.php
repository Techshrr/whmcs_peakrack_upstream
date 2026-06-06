<?php

namespace PeakRack\Tests\Unit\Upstream;

use PeakRack\Tests\TestCase;
use PeakRack\UpstreamApi\Security\Redactor;

final class RedactorTest extends TestCase
{
    public function testRecursivelyRedactsArraysAndJsonStrings(): void
    {
        $redacted = Redactor::redact([
            'password' => 'one',
            'nested' => [
                'Api_Secret' => 'two',
                'hostname' => 'safe.example',
            ],
            'json' => '{"token":"three","status":"active"}',
        ]);

        $this->assertSame('[REDACTED]', $redacted['password']);
        $this->assertSame('[REDACTED]', $redacted['nested']['Api_Secret']);
        $this->assertSame('safe.example', $redacted['nested']['hostname']);
        $this->assertStringNotContains('three', $redacted['json']);
        $this->assertStringContains('[REDACTED]', $redacted['json']);
        $this->assertStringContains('active', $redacted['json']);
    }

    public function testRedactsSensitiveSubstringKeysCaseInsensitively(): void
    {
        $redacted = Redactor::redact([
            'Authorization_Header' => 'Bearer one',
            'rootPwValue' => 'two',
            'sso_url' => 'https://secret.example',
            'status' => 'active',
        ]);

        $this->assertSame('[REDACTED]', $redacted['Authorization_Header']);
        $this->assertSame('[REDACTED]', $redacted['rootPwValue']);
        $this->assertSame('[REDACTED]', $redacted['sso_url']);
        $this->assertSame('active', $redacted['status']);
    }
}
