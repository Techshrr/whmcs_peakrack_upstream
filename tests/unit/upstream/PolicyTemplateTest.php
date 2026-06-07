<?php

namespace PeakRack\Tests\Unit\Upstream;

use PeakRack\Tests\TestCase;
use PeakRack\UpstreamApi\Domain\PolicyTemplate;

final class PolicyTemplateTest extends TestCase
{
    public function testPolicyRowsForApiKeyPreserveDecodedArrays(): void
    {
        $template = PolicyTemplate::restore([
            'id' => 4,
            'enabled' => '1',
            'items' => [
                [
                    'product_id' => 10,
                    'billing_cycles' => ['monthly', 'annually'],
                    'actions' => ['create', 'renew'],
                    'locations' => ['hk-1' => ['field' => 'configoption', 'id' => 1, 'value' => 2]],
                    'os_templates' => ['ubuntu-24.04' => ['field' => 'customfield', 'id' => 3, 'value' => 'ubuntu']],
                    'delivery_mappings' => ['hostname' => ['source' => 'domain']],
                    'sso_hosts' => ['sso.example.test'],
                    'sso_allowed' => true,
                    'destroy_allowed' => false,
                ],
                [
                    'product_id' => 11,
                    'billing_cycles' => ['monthly'],
                    'actions' => ['create'],
                    'locations' => [],
                    'os_templates' => [],
                    'delivery_mappings' => [],
                    'sso_hosts' => [],
                    'sso_allowed' => '0',
                    'destroy_allowed' => '1',
                ],
            ],
        ]);

        $rows = $template->policyRowsForApiKey(8);

        $this->assertSame(2, count($rows));
        $this->assertSame(8, $rows[0]['api_key_id']);
        $this->assertSame(10, $rows[0]['product_id']);
        $this->assertSame(['monthly', 'annually'], $rows[0]['billing_cycles']);
        $this->assertSame(['create', 'renew'], $rows[0]['actions']);
        $this->assertSame(['hk-1' => ['field' => 'configoption', 'id' => 1, 'value' => 2]], $rows[0]['locations']);
        $this->assertSame(['ubuntu-24.04' => ['field' => 'customfield', 'id' => 3, 'value' => 'ubuntu']], $rows[0]['os_templates']);
        $this->assertSame(['hostname' => ['source' => 'domain']], $rows[0]['delivery_mappings']);
        $this->assertSame(['sso.example.test'], $rows[0]['sso_hosts']);
        $this->assertTrue($rows[0]['sso_allowed']);
        $this->assertFalse($rows[0]['destroy_allowed']);
        $this->assertFalse($rows[1]['sso_allowed']);
        $this->assertTrue($rows[1]['destroy_allowed']);
    }
}
