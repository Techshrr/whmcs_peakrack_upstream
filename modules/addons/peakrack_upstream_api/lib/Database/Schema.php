<?php
// SPDX-License-Identifier: Apache-2.0

/**
 * PeakRack Upstream WHMCS Integration
 *
 * Official repository:
 * https://github.com/Techshrr/whmcs_peakrack_upstream
 *
 * Copyright 2026 PeakRack.
 * Licensed under the Apache License, Version 2.0.
 */

namespace PeakRack\UpstreamApi\Database;

use Illuminate\Database\Schema\Blueprint;
use WHMCS\Database\Capsule;

final class Schema
{
    public const API_KEYS = 'mod_peakrack_upstream_api_keys';
    public const POLICIES = 'mod_peakrack_upstream_product_policies';
    public const SERVICES = 'mod_peakrack_upstream_services';
    public const OPERATIONS = 'mod_peakrack_upstream_operations';
    public const EVENTS = 'mod_peakrack_upstream_operation_events';
    public const NONCES = 'mod_peakrack_upstream_nonces';
    public const LOCKS = 'mod_peakrack_upstream_worker_locks';
    public const IDEMPOTENCY_KEY_LENGTH = 160;
    public const NONCE_LENGTH = 128;

    public static function definitions(): array
    {
        return [
            self::API_KEYS => [
                'columns' => [
                    'id', 'public_key', 'encrypted_secret', 'client_id', 'instance_id',
                    'enabled', 'ip_allowlist_json', 'rate_limit_per_minute',
                    'rate_window_started_at', 'rate_window_count', 'last_used_at',
                    'last_used_ip', 'created_at', 'updated_at',
                ],
                'unique' => [['public_key'], ['instance_id']],
            ],
            self::POLICIES => [
                'columns' => [
                    'id', 'api_key_id', 'product_id', 'enabled', 'billing_cycles_json',
                    'actions_json', 'locations_json', 'os_templates_json',
                    'delivery_mappings_json', 'sso_allowed', 'sso_hosts_json',
                    'destroy_allowed', 'created_at', 'updated_at',
                ],
                'unique' => [['api_key_id', 'product_id']],
            ],
            self::SERVICES => [
                'columns' => [
                    'id', 'api_key_id', 'local_service_id', 'upstream_service_id',
                    'upstream_order_id', 'upstream_invoice_id', 'product_id',
                    'billing_cycle', 'service_status', 'operation_status',
                    'cached_primary_ip', 'cached_panel_url', 'current_operation_id',
                    'last_sync_at', 'created_at', 'updated_at',
                ],
                'unique' => [['api_key_id', 'local_service_id'], ['upstream_service_id']],
            ],
            self::OPERATIONS => [
                'columns' => [
                    'id', 'operation_id', 'api_key_id', 'local_service_id', 'action',
                    'idempotency_key', 'request_hash', 'sanitized_payload_json',
                    'status', 'stage', 'attempt_count', 'next_attempt_at',
                    'locked_by', 'locked_until', 'applied_credit_amount',
                    'currency_code', 'result_json', 'last_error_code',
                    'last_error_message', 'created_at', 'updated_at',
                ],
                'unique' => [['operation_id'], ['api_key_id', 'idempotency_key']],
            ],
            self::EVENTS => [
                'columns' => [
                    'id', 'operation_id', 'stage', 'sanitized_context_json', 'created_at',
                ],
                'unique' => [],
            ],
            self::NONCES => [
                'columns' => ['id', 'api_key_id', 'nonce', 'expires_at', 'created_at'],
                'unique' => [['api_key_id', 'nonce']],
            ],
            self::LOCKS => [
                'columns' => ['id', 'lock_name', 'owner', 'expires_at', 'created_at', 'updated_at'],
                'unique' => [['lock_name']],
            ],
        ];
    }

    public static function indexDefinitions(): array
    {
        return [
            self::API_KEYS => [
                'unique' => [
                    ['columns' => ['public_key'], 'name' => 'pru_keys_public_uq'],
                    ['columns' => ['instance_id'], 'name' => 'pru_keys_instance_uq'],
                ],
                'index' => [
                    ['columns' => ['client_id'], 'name' => 'pru_keys_client_idx'],
                    ['columns' => ['enabled'], 'name' => 'pru_keys_enabled_idx'],
                ],
            ],
            self::POLICIES => [
                'unique' => [
                    ['columns' => ['api_key_id', 'product_id'], 'name' => 'pru_policy_key_product_uq'],
                ],
                'index' => [
                    ['columns' => ['api_key_id'], 'name' => 'pru_policy_key_idx'],
                    ['columns' => ['product_id'], 'name' => 'pru_policy_product_idx'],
                    ['columns' => ['enabled'], 'name' => 'pru_policy_enabled_idx'],
                ],
            ],
            self::SERVICES => [
                'unique' => [
                    ['columns' => ['api_key_id', 'local_service_id'], 'name' => 'pru_service_key_local_uq'],
                    ['columns' => ['upstream_service_id'], 'name' => 'pru_service_upstream_uq'],
                ],
                'index' => [
                    ['columns' => ['api_key_id'], 'name' => 'pru_service_key_idx'],
                    ['columns' => ['upstream_order_id'], 'name' => 'pru_service_order_idx'],
                    ['columns' => ['upstream_invoice_id'], 'name' => 'pru_service_invoice_idx'],
                    ['columns' => ['product_id'], 'name' => 'pru_service_product_idx'],
                    ['columns' => ['service_status'], 'name' => 'pru_service_status_idx'],
                    ['columns' => ['operation_status'], 'name' => 'pru_service_op_status_idx'],
                    ['columns' => ['current_operation_id'], 'name' => 'pru_service_current_op_idx'],
                ],
            ],
            self::OPERATIONS => [
                'unique' => [
                    ['columns' => ['operation_id'], 'name' => 'pru_op_id_uq'],
                    ['columns' => ['api_key_id', 'idempotency_key'], 'name' => 'pru_op_key_idem_uq'],
                ],
                'index' => [
                    ['columns' => ['api_key_id'], 'name' => 'pru_op_key_idx'],
                    ['columns' => ['local_service_id'], 'name' => 'pru_op_local_idx'],
                    ['columns' => ['action'], 'name' => 'pru_op_action_idx'],
                    ['columns' => ['status'], 'name' => 'pru_op_status_idx'],
                    ['columns' => ['stage'], 'name' => 'pru_op_stage_idx'],
                    ['columns' => ['next_attempt_at'], 'name' => 'pru_op_next_idx'],
                    ['columns' => ['locked_by'], 'name' => 'pru_op_locked_by_idx'],
                    ['columns' => ['locked_until'], 'name' => 'pru_op_locked_until_idx'],
                ],
            ],
            self::EVENTS => [
                'unique' => [],
                'index' => [
                    ['columns' => ['operation_id'], 'name' => 'pru_event_op_idx'],
                    ['columns' => ['stage'], 'name' => 'pru_event_stage_idx'],
                    ['columns' => ['created_at'], 'name' => 'pru_event_created_idx'],
                ],
            ],
            self::NONCES => [
                'unique' => [
                    ['columns' => ['api_key_id', 'nonce'], 'name' => 'pru_nonce_key_nonce_uq'],
                ],
                'index' => [
                    ['columns' => ['api_key_id'], 'name' => 'pru_nonce_key_idx'],
                    ['columns' => ['expires_at'], 'name' => 'pru_nonce_expires_idx'],
                ],
            ],
            self::LOCKS => [
                'unique' => [
                    ['columns' => ['lock_name'], 'name' => 'pru_lock_name_uq'],
                ],
                'index' => [
                    ['columns' => ['owner'], 'name' => 'pru_lock_owner_idx'],
                    ['columns' => ['expires_at'], 'name' => 'pru_lock_expires_idx'],
                ],
            ],
        ];
    }

    public static function install(): void
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable(self::API_KEYS)) {
            $schema->create(self::API_KEYS, static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('public_key', 128);
                $table->text('encrypted_secret');
                $table->unsignedInteger('client_id');
                $table->char('instance_id', 36);
                $table->boolean('enabled')->default(true);
                $table->text('ip_allowlist_json')->nullable();
                $table->unsignedInteger('rate_limit_per_minute')->default(120);
                $table->unsignedBigInteger('rate_window_started_at')->nullable();
                $table->unsignedInteger('rate_window_count')->default(0);
                $table->unsignedBigInteger('last_used_at')->nullable();
                $table->string('last_used_ip', 45)->nullable();
                self::timestamps($table);
                self::applyIndexDefinitions($table, self::API_KEYS);
            });
        }

        if (!$schema->hasTable(self::POLICIES)) {
            $schema->create(self::POLICIES, static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('api_key_id');
                $table->unsignedInteger('product_id');
                $table->boolean('enabled')->default(true);
                $table->text('billing_cycles_json');
                $table->text('actions_json');
                $table->text('locations_json')->nullable();
                $table->text('os_templates_json')->nullable();
                $table->text('delivery_mappings_json')->nullable();
                $table->boolean('sso_allowed')->default(false);
                $table->text('sso_hosts_json')->nullable();
                $table->boolean('destroy_allowed')->default(false);
                self::timestamps($table);
                self::applyIndexDefinitions($table, self::POLICIES);
            });
        }

        if (!$schema->hasTable(self::SERVICES)) {
            $schema->create(self::SERVICES, static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('api_key_id');
                $table->unsignedInteger('local_service_id');
                $table->unsignedInteger('upstream_service_id')->nullable();
                $table->unsignedInteger('upstream_order_id')->nullable();
                $table->unsignedInteger('upstream_invoice_id')->nullable();
                $table->unsignedInteger('product_id');
                $table->string('billing_cycle', 32);
                $table->string('service_status', 32);
                $table->string('operation_status', 32)->nullable();
                $table->string('cached_primary_ip', 45)->nullable();
                $table->text('cached_panel_url')->nullable();
                $table->char('current_operation_id', 36)->nullable();
                $table->unsignedBigInteger('last_sync_at')->nullable();
                self::timestamps($table);
                self::applyIndexDefinitions($table, self::SERVICES);
            });
        }

        if (!$schema->hasTable(self::OPERATIONS)) {
            $schema->create(self::OPERATIONS, static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->char('operation_id', 36);
                $table->unsignedBigInteger('api_key_id');
                $table->unsignedInteger('local_service_id')->nullable();
                $table->string('action', 64);
                $table->string('idempotency_key', self::IDEMPOTENCY_KEY_LENGTH);
                $table->char('request_hash', 64);
                $table->text('sanitized_payload_json')->nullable();
                $table->string('status', 32);
                $table->string('stage', 64)->nullable();
                $table->unsignedInteger('attempt_count')->default(0);
                $table->unsignedBigInteger('next_attempt_at')->nullable();
                $table->string('locked_by', 128)->nullable();
                $table->unsignedBigInteger('locked_until')->nullable();
                $table->decimal('applied_credit_amount', 18, 8)->nullable();
                $table->char('currency_code', 3)->nullable();
                $table->text('result_json')->nullable();
                $table->string('last_error_code', 64)->nullable();
                $table->text('last_error_message')->nullable();
                self::timestamps($table);
                self::applyIndexDefinitions($table, self::OPERATIONS);
            });
        }

        if (!$schema->hasTable(self::EVENTS)) {
            $schema->create(self::EVENTS, static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->char('operation_id', 36);
                $table->string('stage', 64);
                $table->text('sanitized_context_json')->nullable();
                $table->unsignedBigInteger('created_at');
                self::applyIndexDefinitions($table, self::EVENTS);
            });
        }

        if (!$schema->hasTable(self::NONCES)) {
            $schema->create(self::NONCES, static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('api_key_id');
                $table->string('nonce', self::NONCE_LENGTH);
                $table->unsignedBigInteger('expires_at');
                $table->unsignedBigInteger('created_at');
                self::applyIndexDefinitions($table, self::NONCES);
            });
        }

        if (!$schema->hasTable(self::LOCKS)) {
            $schema->create(self::LOCKS, static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('lock_name', 191);
                $table->string('owner', 128);
                $table->unsignedBigInteger('expires_at');
                self::timestamps($table);
                self::applyIndexDefinitions($table, self::LOCKS);
            });
        }

        self::ensureUniqueIndexes();
    }

    private static function timestamps(Blueprint $table): void
    {
        $table->unsignedBigInteger('created_at');
        $table->unsignedBigInteger('updated_at');
    }

    private static function applyIndexDefinitions(Blueprint $table, string $tableName): void
    {
        $definitions = self::indexDefinitions()[$tableName] ?? ['unique' => [], 'index' => []];

        foreach ($definitions['unique'] ?? [] as $index) {
            $table->unique($index['columns'], $index['name']);
        }

        foreach ($definitions['index'] ?? [] as $index) {
            $table->index($index['columns'], $index['name']);
        }
    }

    private static function ensureUniqueIndexes(): void
    {
        $schema = Capsule::schema();

        foreach (self::indexDefinitions() as $tableName => $definitions) {
            if (!$schema->hasTable($tableName)) {
                continue;
            }

            foreach ($definitions['unique'] ?? [] as $index) {
                if (self::hasUniqueIndex($tableName, $index['columns'])) {
                    continue;
                }

                $schema->table($tableName, static function (Blueprint $table) use ($index): void {
                    $table->unique($index['columns'], $index['name']);
                });
            }
        }
    }

    private static function hasUniqueIndex(string $tableName, array $columns): bool
    {
        $indexes = Capsule::select('SHOW INDEX FROM `' . str_replace('`', '``', $tableName) . '`');
        $groups = [];

        foreach ($indexes as $index) {
            $values = (array) $index;
            $keyName = (string) ($values['Key_name'] ?? $values['key_name'] ?? '');
            if ($keyName === '') {
                continue;
            }

            $groups[$keyName][] = [
                'column' => (string) ($values['Column_name'] ?? $values['column_name'] ?? ''),
                'sequence' => (int) ($values['Seq_in_index'] ?? $values['seq_in_index'] ?? 0),
                'unique' => (int) ($values['Non_unique'] ?? $values['non_unique'] ?? 1) === 0,
            ];
        }

        foreach ($groups as $parts) {
            usort(
                $parts,
                static fn (array $left, array $right): int => $left['sequence'] <=> $right['sequence']
            );

            if (!$parts[0]['unique']) {
                continue;
            }

            $indexedColumns = array_map(static fn (array $part): string => $part['column'], $parts);
            if ($indexedColumns === $columns) {
                return true;
            }
        }

        return false;
    }
}
