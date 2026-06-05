<?php

namespace PeakRack\Tests\Unit\Downstream;

use PeakRack\Tests\TestCase;
use PeakRack\Upstream\Mapper;

final class MapperTest extends TestCase
{
    public function testMapsAutoCycleAndCreatesOnlyApprovedTransportPayload(): void
    {
        $payload = Mapper::createPayload($this->params());

        $this->assertSame(123, $payload['local_service_id']);
        $this->assertSame(10, $payload['product_id']);
        $this->assertSame('monthly', $payload['billing_cycle']);
        $this->assertSame('server.example.test', $payload['hostname']);
        $this->assertSame('temporary-secret', $payload['password']);
        $this->assertSame('hk-1', $payload['location']);
        $this->assertSame('ubuntu-24.04', $payload['os_template']);
        foreach (['client_id', 'clientid', 'clientsdetails', 'price', 'amount', 'userid'] as $forbidden) {
            $this->assertFalse(array_key_exists($forbidden, $payload));
        }
    }

    public function testChangePackageExcludesPasswordAndBuildsSecureApiBaseUrl(): void
    {
        $params = $this->params();
        $params['serverhostname'] = 'api.example.test';
        $params['serverport'] = '8443';
        $params['serversecure'] = 'on';
        $params['serveraccesshash'] = '/billing/';

        $payload = Mapper::changePackagePayload($params);

        $this->assertSame(10, $payload['product_id']);
        $this->assertSame('monthly', $payload['billing_cycle']);
        $this->assertFalse(array_key_exists('password', $payload));
        $this->assertFalse(array_key_exists('local_service_id', $payload));
        $this->assertSame(
            'https://api.example.test:8443/billing/modules/addons/peakrack_upstream_api/api/v1',
            Mapper::apiBaseUrl($params)
        );
    }

    public function testMapsTerminateModeAndTimeout(): void
    {
        $params = $this->params();
        $params['configoption5'] = 'destroy';
        $params['configoption6'] = '999';

        $this->assertSame('destroy', Mapper::terminateMode($params));
        $this->assertSame(120, Mapper::timeout($params));
    }

    private function params(): array
    {
        return [
            'serviceid' => '123',
            'domain' => 'server.example.test',
            'password' => 'temporary-secret',
            'billingcycle' => 'Monthly',
            'configoption1' => '10',
            'configoption2' => 'auto',
            'configoption3' => 'hk-1',
            'configoption4' => 'ubuntu-24.04',
            'configoption5' => 'cancel_only',
            'configoption6' => '30',
            'clientsdetails' => ['id' => 44],
            'amount' => '99.00',
        ];
    }
}
