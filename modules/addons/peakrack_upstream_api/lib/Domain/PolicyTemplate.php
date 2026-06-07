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

namespace PeakRack\UpstreamApi\Domain;

final class PolicyTemplate
{
    private function __construct(
        private readonly int $id,
        private readonly bool $enabled,
        private readonly array $items
    ) {
    }

    public static function restore(array $row): self
    {
        return new self(
            (int) ($row['id'] ?? 0),
            self::boolValue($row['enabled'] ?? false),
            array_map(
                static fn (array $item): array => self::normalizeItem($item),
                array_values((array) ($row['items'] ?? []))
            )
        );
    }

    public function id(): int
    {
        return $this->id;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function items(): array
    {
        return $this->items;
    }

    public function policyRowsForApiKey(int $apiKeyId): array
    {
        return array_map(
            static function (array $item) use ($apiKeyId): array {
                return [
                    'api_key_id' => $apiKeyId,
                    'product_id' => $item['product_id'],
                    'enabled' => true,
                    'billing_cycles' => $item['billing_cycles'],
                    'actions' => $item['actions'],
                    'locations' => $item['locations'],
                    'os_templates' => $item['os_templates'],
                    'delivery_mappings' => $item['delivery_mappings'],
                    'sso_allowed' => $item['sso_allowed'],
                    'sso_hosts' => $item['sso_hosts'],
                    'destroy_allowed' => $item['destroy_allowed'],
                ];
            },
            $this->items
        );
    }

    private static function normalizeItem(array $item): array
    {
        return [
            'product_id' => (int) ($item['product_id'] ?? 0),
            'billing_cycles' => self::arrayValue($item, 'billing_cycles'),
            'actions' => self::arrayValue($item, 'actions'),
            'locations' => self::arrayValue($item, 'locations'),
            'os_templates' => self::arrayValue($item, 'os_templates'),
            'delivery_mappings' => self::arrayValue($item, 'delivery_mappings'),
            'sso_allowed' => self::boolValue($item['sso_allowed'] ?? false),
            'sso_hosts' => self::arrayValue($item, 'sso_hosts'),
            'destroy_allowed' => self::boolValue($item['destroy_allowed'] ?? false),
        ];
    }

    private static function arrayValue(array $row, string $key): array
    {
        if (array_key_exists($key, $row)) {
            return is_array($row[$key]) ? $row[$key] : [];
        }

        $jsonKey = $key . '_json';
        if (!isset($row[$jsonKey]) || $row[$jsonKey] === '') {
            return [];
        }

        $decoded = json_decode((string) $row[$jsonKey], true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function boolValue(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }
}
