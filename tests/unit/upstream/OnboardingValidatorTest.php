<?php

namespace PeakRack\Tests\Unit\Upstream;

use PeakRack\Tests\TestCase;
use PeakRack\UpstreamApi\Application\OnboardingValidator;

final class OnboardingValidatorTest extends TestCase
{
    public function testNormalizesValidApplicationInput(): void
    {
        $input = (new OnboardingValidator(requireOutboundIp: true))->validate([
            'brand_name' => ' Example Reseller ',
            'downstream_domain' => 'billing.example.test',
            'outbound_ips' => "192.0.2.10\n2001:db8::/32",
            'business_type' => 'VPS and hosting',
            'telegram' => 't.me/example_support',
            'qq' => '123456',
            'phone' => '+1 555-0100',
            'notes' => '<script>alert(1)</script>',
            'terms_accepted' => '1',
        ], 1780617600, '198.51.100.1');

        $this->assertSame('Example Reseller', $input['brand_name']);
        $this->assertSame('billing.example.test', $input['downstream_domain']);
        $this->assertSame('["192.0.2.10","2001:db8::/32"]', $input['outbound_ips_json']);
        $this->assertSame('@example_support', $input['telegram']);
        $this->assertSame('123456', $input['qq']);
        $this->assertSame('+1 555-0100', $input['phone']);
        $this->assertSame(1780617600, $input['terms_accepted_at']);
        $this->assertSame('198.51.100.1', $input['terms_accepted_ip']);
    }

    public function testRejectsSchemePathBadIpBadTelegramAndMissingTerms(): void
    {
        $validator = new OnboardingValidator(requireOutboundIp: true);

        $this->assertThrows(
            fn () => $validator->validate([
                'brand_name' => 'A',
                'downstream_domain' => 'https://billing.example.test/path',
                'outbound_ips' => 'not-an-ip',
                'business_type' => '',
                'telegram' => 'http://example.test',
                'terms_accepted' => '0',
            ], 1780617600, '198.51.100.1'),
            \InvalidArgumentException::class
        );
    }
}
