<?php

namespace PeakRack\Tests\Integration;

use PeakRack\Tests\TestCase;
use PeakRack\UpstreamApi\Database\Schema;
use WHMCS\Database\Capsule;

require_once __DIR__ . '/WhmcsTestSupport.php';

final class WhmcsSchemaTest extends TestCase
{
    public static function group(): string
    {
        return 'whmcs-safe';
    }

    public function testAddonSchemaInstallsRequiredTablesAndUniqueIndexes(): void
    {
        if (!WhmcsTestSupport::enabled()) {
            $this->skip('Set PEAKRACK_WHMCS_TEST=1 to run WHMCS integration checks.');
        }

        WhmcsTestSupport::boot();
        Schema::install();

        foreach (Schema::definitions() as $table => $definition) {
            $this->assertTrue(Capsule::schema()->hasTable($table), 'Missing Addon table: ' . $table);
            $uniqueIndexes = $this->uniqueIndexes($table);
            foreach ($definition['unique'] as $expected) {
                $this->assertContains(
                    implode(',', $expected),
                    $uniqueIndexes,
                    'Missing unique index on ' . $table
                );
            }
        }
    }

    public function testAddonDeactivationPreservesOwnedTables(): void
    {
        if (!WhmcsTestSupport::enabled()) {
            $this->skip('Set PEAKRACK_WHMCS_TEST=1 to run WHMCS integration checks.');
        }

        $root = WhmcsTestSupport::boot();
        Schema::install();
        require_once $root . '/modules/addons/peakrack_upstream_api/peakrack_upstream_api.php';

        $response = peakrack_upstream_api_deactivate();

        $this->assertSame('success', $response['status']);
        foreach (array_keys(Schema::definitions()) as $table) {
            $this->assertTrue(Capsule::schema()->hasTable($table), 'Deactivation removed Addon table: ' . $table);
        }
    }

    private function uniqueIndexes(string $table): array
    {
        $rows = Capsule::select('SHOW INDEX FROM `' . str_replace('`', '``', $table) . '`');
        $indexes = [];
        foreach ($rows as $row) {
            $values = (array) $row;
            if ((int) ($values['Non_unique'] ?? $values['non_unique'] ?? 1) !== 0) {
                continue;
            }
            $name = (string) ($values['Key_name'] ?? $values['key_name'] ?? '');
            $sequence = (int) ($values['Seq_in_index'] ?? $values['seq_in_index'] ?? 0);
            $column = (string) ($values['Column_name'] ?? $values['column_name'] ?? '');
            if ($name !== '' && $sequence > 0 && $column !== '') {
                $indexes[$name][$sequence] = $column;
            }
        }

        $normalized = [];
        foreach ($indexes as $columns) {
            ksort($columns, SORT_NUMERIC);
            $normalized[] = implode(',', $columns);
        }

        return $normalized;
    }
}
