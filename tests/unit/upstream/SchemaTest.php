<?php

namespace PeakRack\Tests\Unit\Upstream;

use PeakRack\Tests\TestCase;
use PeakRack\UpstreamApi\Database\Schema;

final class SchemaTest extends TestCase
{
    public function testDefinesAllAddonOwnedTables(): void
    {
        $definitions = Schema::definitions();

        $expected = [
            'mod_peakrack_upstream_api_keys',
            'mod_peakrack_upstream_product_policies',
            'mod_peakrack_upstream_services',
            'mod_peakrack_upstream_operations',
            'mod_peakrack_upstream_operation_events',
            'mod_peakrack_upstream_nonces',
            'mod_peakrack_upstream_worker_locks',
        ];

        $this->assertSame($expected, array_keys($definitions));
    }

    public function testDefinesRequiredUniqueOwnershipAndIdempotencyKeys(): void
    {
        $definitions = Schema::definitions();

        $this->assertContains(
            ['api_key_id', 'idempotency_key'],
            $definitions['mod_peakrack_upstream_operations']['unique']
        );
        $this->assertContains(
            ['api_key_id', 'local_service_id'],
            $definitions['mod_peakrack_upstream_services']['unique']
        );
        $this->assertContains(
            ['api_key_id', 'nonce'],
            $definitions['mod_peakrack_upstream_nonces']['unique']
        );
        $this->assertContains(
            ['api_key_id', 'product_id'],
            $definitions['mod_peakrack_upstream_product_policies']['unique']
        );
    }

    public function testDefinitionsIncludeRequiredFinancialAndRetryColumns(): void
    {
        $operations = Schema::definitions()['mod_peakrack_upstream_operations']['columns'];

        $this->assertContains('request_hash', $operations);
        $this->assertContains('applied_credit_amount', $operations);
        $this->assertContains('attempt_count', $operations);
        $this->assertContains('next_attempt_at', $operations);
        $this->assertContains('last_error_code', $operations);
    }
}
