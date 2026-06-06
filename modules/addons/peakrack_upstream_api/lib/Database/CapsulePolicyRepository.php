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

use PeakRack\UpstreamApi\Contracts\PolicyRepository;
use PeakRack\UpstreamApi\Domain\ProductPolicy;
use WHMCS\Database\Capsule;

final class CapsulePolicyRepository implements PolicyRepository
{
    public function findForProduct(int $apiKeyId, int $productId): ?ProductPolicy
    {
        $row = Capsule::table(Schema::POLICIES)
            ->where('api_key_id', $apiKeyId)
            ->where('product_id', $productId)
            ->where('enabled', 1)
            ->first();

        return $row === null ? null : $this->hydrate((array) $row);
    }

    public function catalogForApiKey(int $apiKeyId): array
    {
        return array_map(
            function (object $row): array {
                $attributes = (array) $row;
                return [
                    'product_id' => (int) $attributes['product_id'],
                    'billing_cycles' => $this->decode($attributes['billing_cycles_json']),
                    'actions' => $this->decode($attributes['actions_json']),
                    'locations' => array_keys($this->decode($attributes['locations_json'])),
                    'os_templates' => array_keys($this->decode($attributes['os_templates_json'])),
                    'sso_allowed' => (bool) $attributes['sso_allowed'],
                    'destroy_allowed' => (bool) $attributes['destroy_allowed'],
                ];
            },
            Capsule::table(Schema::POLICIES)
                ->where('api_key_id', $apiKeyId)
                ->where('enabled', 1)
                ->orderBy('product_id')
                ->get()
                ->all()
        );
    }

    public function save(array $attributes): int
    {
        $now = time();
        $id = isset($attributes['id']) ? (int) $attributes['id'] : 0;
        unset($attributes['id']);

        foreach ([
            'billing_cycles' => 'billing_cycles_json',
            'actions' => 'actions_json',
            'locations' => 'locations_json',
            'os_templates' => 'os_templates_json',
            'delivery_mappings' => 'delivery_mappings_json',
            'sso_hosts' => 'sso_hosts_json',
        ] as $input => $column) {
            if (array_key_exists($input, $attributes)) {
                $attributes[$column] = $this->encode((array) $attributes[$input]);
                unset($attributes[$input]);
            }
        }

        $attributes['updated_at'] = $now;

        if ($id > 0) {
            Capsule::table(Schema::POLICIES)->where('id', $id)->update($attributes);
            return $id;
        }

        $attributes['created_at'] = $now;
        return (int) Capsule::table(Schema::POLICIES)->insertGetId($attributes);
    }

    public function delete(int $id): void
    {
        Capsule::table(Schema::POLICIES)->where('id', $id)->delete();
    }

    private function hydrate(array $row): ProductPolicy
    {
        return new ProductPolicy(
            (int) $row['product_id'],
            $this->decode($row['billing_cycles_json']),
            $this->decode($row['actions_json']),
            $this->decode($row['locations_json']),
            $this->decode($row['os_templates_json']),
            (bool) $row['destroy_allowed'],
            $this->decode($row['delivery_mappings_json']),
            (bool) $row['sso_allowed'],
            $this->decode($row['sso_hosts_json'])
        );
    }

    private function decode(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function encode(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
