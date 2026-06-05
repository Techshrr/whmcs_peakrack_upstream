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

    public static function install(): void
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable(self::API_KEYS)) {
            $schema->create(self::API_KEYS, static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('public_key', 128)->unique();
                $table->text('encrypted_secret');
                $table->unsignedInteger('client_id')->index();
                $table->char('instance_id', 36)->unique();
                $table->boolean('enabled')->default(true)->index();
                $table->text('ip_allowlist_json')->nullable();
                $table->unsignedInteger('rate_limit_per_minute')->default(120);
                $table->unsignedBigInteger('rate_window_started_at')->nullable();
                $table->unsignedInteger('rate_window_count')->default(0);
                $table->unsignedBigInteger('last_used_at')->nullable();
                $table->string('last_used_ip', 45)->nullable();
                self::timestamps($table);
            });
        }

        if (!$schema->hasTable(self::POLICIES)) {
            $schema->create(self::POLICIES, static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('api_key_id')->index();
                $table->unsignedInteger('product_id')->index();
                $table->boolean('enabled')->default(true)->index();
                $table->text('billing_cycles_json');
                $table->text('actions_json');
                $table->text('locations_json')->nullable();
                $table->text('os_templates_json')->nullable();
                $table->text('delivery_mappings_json')->nullable();
                $table->boolean('sso_allowed')->default(false);
                $table->text('sso_hosts_json')->nullable();
                $table->boolean('destroy_allowed')->default(false);
                self::timestamps($table);
                $table->unique(['api_key_id', 'product_id']);
            });
        }

        if (!$schema->hasTable(self::SERVICES)) {
            $schema->create(self::SERVICES, static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('api_key_id')->index();
                $table->unsignedInteger('local_service_id');
                $table->unsignedInteger('upstream_service_id')->nullable()->unique();
                $table->unsignedInteger('upstream_order_id')->nullable()->index();
                $table->unsignedInteger('upstream_invoice_id')->nullable()->index();
                $table->unsignedInteger('product_id')->index();
                $table->string('billing_cycle', 32);
                $table->string('service_status', 32)->index();
                $table->string('operation_status', 32)->nullable()->index();
                $table->string('cached_primary_ip', 45)->nullable();
                $table->text('cached_panel_url')->nullable();
                $table->char('current_operation_id', 36)->nullable()->index();
                $table->unsignedBigInteger('last_sync_at')->nullable();
                self::timestamps($table);
                $table->unique(['api_key_id', 'local_service_id']);
            });
        }

        if (!$schema->hasTable(self::OPERATIONS)) {
            $schema->create(self::OPERATIONS, static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->char('operation_id', 36)->unique();
                $table->unsignedBigInteger('api_key_id')->index();
                $table->unsignedInteger('local_service_id')->nullable()->index();
                $table->string('action', 64)->index();
                $table->string('idempotency_key', 191);
                $table->char('request_hash', 64);
                $table->text('sanitized_payload_json')->nullable();
                $table->string('status', 32)->index();
                $table->string('stage', 64)->nullable()->index();
                $table->unsignedInteger('attempt_count')->default(0);
                $table->unsignedBigInteger('next_attempt_at')->nullable()->index();
                $table->string('locked_by', 128)->nullable()->index();
                $table->unsignedBigInteger('locked_until')->nullable()->index();
                $table->decimal('applied_credit_amount', 18, 8)->nullable();
                $table->char('currency_code', 3)->nullable();
                $table->text('result_json')->nullable();
                $table->string('last_error_code', 64)->nullable();
                $table->text('last_error_message')->nullable();
                self::timestamps($table);
                $table->unique(['api_key_id', 'idempotency_key']);
            });
        }

        if (!$schema->hasTable(self::EVENTS)) {
            $schema->create(self::EVENTS, static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->char('operation_id', 36)->index();
                $table->string('stage', 64)->index();
                $table->text('sanitized_context_json')->nullable();
                $table->unsignedBigInteger('created_at')->index();
            });
        }

        if (!$schema->hasTable(self::NONCES)) {
            $schema->create(self::NONCES, static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('api_key_id')->index();
                $table->string('nonce', 191);
                $table->unsignedBigInteger('expires_at')->index();
                $table->unsignedBigInteger('created_at');
                $table->unique(['api_key_id', 'nonce']);
            });
        }

        if (!$schema->hasTable(self::LOCKS)) {
            $schema->create(self::LOCKS, static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('lock_name', 191)->unique();
                $table->string('owner', 128)->index();
                $table->unsignedBigInteger('expires_at')->index();
                self::timestamps($table);
            });
        }
    }

    private static function timestamps(Blueprint $table): void
    {
        $table->unsignedBigInteger('created_at');
        $table->unsignedBigInteger('updated_at');
    }
}
