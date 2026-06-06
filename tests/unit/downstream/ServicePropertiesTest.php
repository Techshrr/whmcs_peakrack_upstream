<?php

namespace PeakRack\Tests\Unit\Downstream;

use PeakRack\Tests\TestCase;
use PeakRack\Upstream\ServiceProperties;

final class ServicePropertiesTest extends TestCase
{
    public function testAdminOnlyPropertyNamesAreStableAndPrimaryIpUsesDedicatedIp(): void
    {
        $store = new ServicePropertiesFakeStore();
        $properties = new ServiceProperties($store);

        $properties->applyResponse([
            'success' => true,
            'status' => 'completed',
            'operation_id' => '123e4567-e89b-42d3-a456-426614174000',
            'data' => [
                'upstream_service_id' => 456,
                'upstream_order_id' => 789,
                'upstream_invoice_id' => 321,
                'service_status' => 'active',
                'primary_ip' => '192.0.2.10',
                'panel_url' => 'https://panel.example.test/',
            ],
            'error' => null,
        ]);

        $this->assertSame([
            'Upstream Service ID',
            'Upstream Order ID',
            'Upstream Invoice ID',
            'Upstream Operation ID',
            'Upstream Status',
            'Upstream Panel URL',
            'Provisioning Error',
            'Last Sync Time',
            'Current Idempotency Key',
        ], ServiceProperties::adminPropertyNames());
        $this->assertSame('456', $store->values['Upstream Service ID']);
        $this->assertSame('192.0.2.10', $store->values['Dedicated IP']);
        $this->assertSame('https://panel.example.test/', $store->values['Upstream Panel URL']);
    }

    public function testCustomerDataExcludesInternalIdsAndErrors(): void
    {
        $store = new ServicePropertiesFakeStore([
            'Dedicated IP' => '192.0.2.10',
            'Upstream Panel URL' => 'https://panel.example.test/',
            'Last Sync Time' => '2026-06-05T12:00:00Z',
            'Upstream Service ID' => '456',
            'Upstream Operation ID' => 'internal-operation',
            'Provisioning Error' => 'internal-error',
        ]);
        $properties = new ServiceProperties($store);

        $data = $properties->customerData('Active', true);

        $this->assertSame([
            'status' => 'Active',
            'primary_ip' => '192.0.2.10',
            'panel_url' => 'https://panel.example.test/',
            'last_sync_time' => '2026-06-05T12:00:00Z',
            'sso_available' => true,
        ], $data);
        $serialized = serialize($data);
        $this->assertStringNotContains('internal-operation', $serialized);
        $this->assertStringNotContains('internal-error', $serialized);
        $this->assertStringNotContains('456', $serialized);
    }

    public function testCustomerDataDropsUnsafeCachedNetworkValues(): void
    {
        $properties = new ServiceProperties(new ServicePropertiesFakeStore([
            'Dedicated IP' => '<script>',
            'Upstream Panel URL' => 'javascript:alert(1)',
        ]));

        $data = $properties->customerData('Active', false);

        $this->assertSame(null, $data['primary_ip']);
        $this->assertSame(null, $data['panel_url']);
    }
}

final class ServicePropertiesFakeStore
{
    public function __construct(public array $values = [])
    {
    }

    public function get(string $name): mixed
    {
        return $this->values[$name] ?? null;
    }

    public function save(array $values): void
    {
        $this->values = array_replace($this->values, $values);
    }
}
