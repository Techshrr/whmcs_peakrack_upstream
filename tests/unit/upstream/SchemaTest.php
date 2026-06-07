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
            'mod_peakrack_upstream_applications',
            'mod_peakrack_upstream_policy_templates',
            'mod_peakrack_upstream_policy_template_items',
            'mod_peakrack_upstream_secret_reset_events',
            'mod_peakrack_upstream_audit_events',
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

    public function testCompositeUniqueStringColumnsFitLegacyInnoDbIndexLimits(): void
    {
        $this->assertSame(160, Schema::IDEMPOTENCY_KEY_LENGTH);
        $this->assertSame(128, Schema::NONCE_LENGTH);
    }

    public function testIndexesUseExplicitMysqlSafeNames(): void
    {
        $indexes = Schema::indexDefinitions();

        $this->assertContains(
            ['columns' => ['api_key_id', 'idempotency_key'], 'name' => 'pru_op_key_idem_uq'],
            $indexes[Schema::OPERATIONS]['unique']
        );
        $this->assertContains(
            ['columns' => ['api_key_id', 'nonce'], 'name' => 'pru_nonce_key_nonce_uq'],
            $indexes[Schema::NONCES]['unique']
        );

        foreach ($indexes as $table => $groups) {
            foreach ($groups as $type => $definitions) {
                foreach ($definitions as $definition) {
                    $this->assertTrue(
                        strlen($definition['name']) <= 64,
                        sprintf('%s %s index name is too long: %s', $table, $type, $definition['name'])
                    );
                }
            }
        }

        $schemaSource = file_get_contents(dirname(__DIR__, 3) . '/modules/addons/peakrack_upstream_api/lib/Database/Schema.php');
        $this->assertTrue(is_string($schemaSource));
        $this->assertFalse(
            (bool) preg_match('/->(?:unique|index)\(\s*\)/', $schemaSource),
            'Schema indexes must not rely on auto-generated names.'
        );
        $this->assertFalse(
            (bool) preg_match('/->(?:unique|index)\(\s*\[[^\)]*\]\s*\)/s', $schemaSource),
            'Composite schema indexes must use explicit names.'
        );
        $this->assertStringContains(
            "\$table->string('business_type', 200)",
            $schemaSource,
            'Application business type storage must match the 200-character validator limit.'
        );
    }

    public function testDefinesOnboardingTablesWithShortIndexNames(): void
    {
        $definitions = Schema::definitions();

        $expectedTables = [
            Schema::APPLICATIONS,
            Schema::POLICY_TEMPLATES,
            Schema::POLICY_TEMPLATE_ITEMS,
            Schema::SECRET_RESET_EVENTS,
            Schema::AUDIT_EVENTS,
        ];

        foreach ($expectedTables as $table) {
            $this->assertArrayHasKey($table, $definitions);
        }

        $this->assertContains('active_client_key', $definitions[Schema::APPLICATIONS]['columns']);
        $this->assertContains('secret_pending_display', $definitions[Schema::APPLICATIONS]['columns']);
        $this->assertContains('terms_accepted_ip', $definitions[Schema::APPLICATIONS]['columns']);
        $this->assertContains(['active_client_key'], $definitions[Schema::APPLICATIONS]['unique']);
        $this->assertContains('billing_cycles_json', $definitions[Schema::POLICY_TEMPLATE_ITEMS]['columns']);
        $this->assertContains('bypassed_limits', $definitions[Schema::SECRET_RESET_EVENTS]['columns']);
        $this->assertContains('sanitized_context_json', $definitions[Schema::AUDIT_EVENTS]['columns']);

        $this->assertContains(
            ['columns' => ['active_client_key'], 'name' => 'pru_app_active_client_uq'],
            Schema::indexDefinitions()[Schema::APPLICATIONS]['unique']
        );

        foreach (Schema::indexDefinitions() as $groups) {
            foreach ($groups as $definitionsForType) {
                foreach ($definitionsForType as $definition) {
                    $this->assertTrue(strlen($definition['name']) <= 64, $definition['name']);
                }
            }
        }
    }
}
