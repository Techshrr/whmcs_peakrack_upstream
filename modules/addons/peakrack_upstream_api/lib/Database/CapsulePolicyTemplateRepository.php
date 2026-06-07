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

use PeakRack\UpstreamApi\Contracts\PolicyTemplateRepository;
use PeakRack\UpstreamApi\Domain\PolicyTemplate;
use WHMCS\Database\Capsule;

final class CapsulePolicyTemplateRepository implements PolicyTemplateRepository
{
    public function saveTemplate(array $template, array $items): int
    {
        return (int) Capsule::connection()->transaction(function () use ($template, $items): int {
            $now = time();
            $id = isset($template['id']) ? (int) $template['id'] : 0;
            unset($template['id']);
            $template['updated_at'] = $now;

            if ($id > 0) {
                Capsule::table(Schema::POLICY_TEMPLATES)->where('id', $id)->update($template);
                Capsule::table(Schema::POLICY_TEMPLATE_ITEMS)->where('template_id', $id)->delete();
            } else {
                $template['created_at'] = $now;
                $id = (int) Capsule::table(Schema::POLICY_TEMPLATES)->insertGetId($template);
            }

            foreach ($items as $index => $item) {
                Capsule::table(Schema::POLICY_TEMPLATE_ITEMS)->insert($this->itemToRow($id, $item, $index, $now));
            }

            return $id;
        });
    }

    public function find(int $id): ?PolicyTemplate
    {
        $template = Capsule::table(Schema::POLICY_TEMPLATES)->where('id', $id)->first();
        if ($template === null) {
            return null;
        }

        return PolicyTemplate::restore(array_merge((array) $template, [
            'items' => $this->itemsForTemplate($id),
        ]));
    }

    public function listTemplates(bool $enabledOnly = false): array
    {
        $query = Capsule::table(Schema::POLICY_TEMPLATES)->orderByDesc('id')->limit(100);
        if ($enabledOnly) {
            $query->where('enabled', 1);
        }

        return array_map(static fn (object $row): array => (array) $row, $query->get()->all());
    }

    public function deleteTemplate(int $id): void
    {
        Capsule::connection()->transaction(static function () use ($id): void {
            Capsule::table(Schema::POLICY_TEMPLATE_ITEMS)->where('template_id', $id)->delete();
            Capsule::table(Schema::POLICY_TEMPLATES)->where('id', $id)->delete();
        });
    }

    private function itemsForTemplate(int $templateId): array
    {
        return array_map(
            function (object $row): array {
                $attributes = (array) $row;
                return [
                    'product_id' => (int) $attributes['product_id'],
                    'billing_cycles' => $this->decode($attributes['billing_cycles_json'] ?? null),
                    'actions' => $this->decode($attributes['actions_json'] ?? null),
                    'locations' => $this->decode($attributes['locations_json'] ?? null),
                    'os_templates' => $this->decode($attributes['os_templates_json'] ?? null),
                    'delivery_mappings' => $this->decode($attributes['delivery_mappings_json'] ?? null),
                    'sso_allowed' => (bool) $attributes['sso_allowed'],
                    'sso_hosts' => $this->decode($attributes['sso_hosts_json'] ?? null),
                    'destroy_allowed' => (bool) $attributes['destroy_allowed'],
                    'sort_order' => (int) $attributes['sort_order'],
                ];
            },
            Capsule::table(Schema::POLICY_TEMPLATE_ITEMS)
                ->where('template_id', $templateId)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->all()
        );
    }

    private function itemToRow(int $templateId, array $item, int $index, int $now): array
    {
        return [
            'template_id' => $templateId,
            'product_id' => (int) $item['product_id'],
            'billing_cycles_json' => $this->encode((array) $item['billing_cycles']),
            'actions_json' => $this->encode((array) $item['actions']),
            'locations_json' => $this->encode((array) ($item['locations'] ?? [])),
            'os_templates_json' => $this->encode((array) ($item['os_templates'] ?? [])),
            'delivery_mappings_json' => $this->encode((array) ($item['delivery_mappings'] ?? [])),
            'sso_allowed' => !empty($item['sso_allowed']) ? 1 : 0,
            'sso_hosts_json' => $this->encode((array) ($item['sso_hosts'] ?? [])),
            'destroy_allowed' => !empty($item['destroy_allowed']) ? 1 : 0,
            'sort_order' => (int) ($item['sort_order'] ?? (($index + 1) * 10)),
            'created_at' => $now,
            'updated_at' => $now,
        ];
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
