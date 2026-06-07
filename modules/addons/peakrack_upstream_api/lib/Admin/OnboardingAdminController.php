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

namespace PeakRack\UpstreamApi\Admin;

use Closure;
use InvalidArgumentException;
use JsonException;
use PeakRack\UpstreamApi\Contracts\ApplicationRepository;
use PeakRack\UpstreamApi\Contracts\AuditRepository;
use PeakRack\UpstreamApi\Contracts\PolicyTemplateRepository;
use RuntimeException;

final class OnboardingAdminController
{
    private const PAGES = [
        'policy_templates',
        'onboarding_applications',
    ];

    private readonly Closure $policyValidator;
    private readonly Closure $clock;

    public function __construct(
        private readonly ApplicationRepository $applications,
        private readonly PolicyTemplateRepository $templates,
        private readonly AuditRepository $audits,
        private readonly Csrf $csrf,
        callable $policyValidator,
        callable $clock
    ) {
        $this->policyValidator = Closure::fromCallable($policyValidator);
        $this->clock = Closure::fromCallable($clock);
    }

    public function dispatch(array $request, ?int $adminId, array &$session, string $moduleLink): array
    {
        if ($adminId === null || $adminId < 1) {
            throw new RuntimeException('An authenticated administrator is required.');
        }

        $page = (string) ($request['page'] ?? 'policy_templates');
        if (!in_array($page, self::PAGES, true)) {
            $page = 'policy_templates';
        }

        $notice = null;
        $action = (string) ($request['action'] ?? '');
        if ($action !== '') {
            $this->csrf->assertValid($session, $request['csrf_token'] ?? null);
            $notice = $this->runAction($action, $request, $adminId);
        }

        return [
            'page' => $page,
            'modulelink' => $moduleLink,
            'csrf_token' => $this->csrf->token($session),
            'notice' => $notice,
            'secret_once' => null,
            'data' => $this->pageData($page, $request),
        ];
    }

    private function runAction(string $action, array $request, int $adminId): string
    {
        return match ($action) {
            'save_template' => $this->saveTemplate($request, $adminId),
            'disable_template' => $this->disableTemplate($request, $adminId),
            default => throw new InvalidArgumentException('The requested onboarding administrator action is invalid.'),
        };
    }

    private function saveTemplate(array $request, int $adminId): string
    {
        $template = [
            'name' => $this->boundedString($request, 'name', 2, 120),
            'description' => trim((string) ($request['description'] ?? '')),
            'enabled' => $this->boolean($request['enabled'] ?? '1') ? 1 : 0,
        ];
        if (isset($request['template_id']) && (string) $request['template_id'] !== '') {
            $template['id'] = $this->positiveInt($request, 'template_id');
        }

        $items = $this->templateItems($request['items_json'] ?? null);
        if ($items === []) {
            throw new InvalidArgumentException('A policy template requires at least one item.');
        }

        foreach ($items as $item) {
            ($this->policyValidator)([
                'product_id' => $item['product_id'],
                'billing_cycles' => $item['billing_cycles'],
                'actions' => $item['actions'],
                'locations' => $item['locations'],
                'os_templates' => $item['os_templates'],
                'delivery_mappings' => $item['delivery_mappings'],
                'sso_hosts' => $item['sso_hosts'],
                'sso_allowed' => $item['sso_allowed'],
                'destroy_allowed' => $item['destroy_allowed'],
            ]);
        }

        $templateId = $this->templates->saveTemplate($template, $items);
        $this->audits->append([
            'event_type' => 'policy_template_saved',
            'actor_type' => 'admin',
            'actor_id' => $adminId,
            'created_at' => ($this->clock)(),
            'context' => ['template_id' => $templateId, 'item_count' => count($items)],
        ]);

        return 'Policy template saved.';
    }

    private function disableTemplate(array $request, int $adminId): string
    {
        $template = $this->templates->find($this->positiveInt($request, 'template_id'));
        if ($template === null) {
            throw new InvalidArgumentException('Policy template not found.');
        }

        $this->templates->saveTemplate([
            'id' => $template->id(),
            'name' => $template->name(),
            'description' => $template->description(),
            'enabled' => 0,
        ], $template->items());
        $this->audits->append([
            'event_type' => 'policy_template_disabled',
            'actor_type' => 'admin',
            'actor_id' => $adminId,
            'created_at' => ($this->clock)(),
            'context' => ['template_id' => $template->id()],
        ]);

        return 'Policy template disabled.';
    }

    private function pageData(string $page, array $request): array
    {
        if ($page === 'onboarding_applications') {
            $status = isset($request['status']) ? (string) $request['status'] : null;
            return $this->applications->listByStatus($status, 100);
        }

        return $this->templates->listTemplates(false);
    }

    private function templateItems(mixed $itemsJson): array
    {
        if (!is_string($itemsJson) || trim($itemsJson) === '') {
            throw new InvalidArgumentException('The template items value must contain valid JSON.');
        }

        try {
            $items = json_decode($this->normalizeSubmittedString($itemsJson), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $stripped = stripslashes($this->normalizeSubmittedString($itemsJson));
            try {
                $items = json_decode($stripped, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new InvalidArgumentException('The template items value must contain valid JSON.');
            }
        }

        if (!is_array($items) || !array_is_list($items)) {
            throw new InvalidArgumentException('The template items value must be a JSON array.');
        }

        return array_map(function (mixed $item): array {
            if (!is_array($item)) {
                throw new InvalidArgumentException('Each policy template item must be an object.');
            }

            return $this->normalizeItem($item);
        }, $items);
    }

    private function normalizeItem(array $raw): array
    {
        $item = [
            'product_id' => $this->positiveInt($raw, 'product_id'),
            'billing_cycles' => $this->stringList($raw, 'billing_cycles'),
            'actions' => $this->stringList($raw, 'actions'),
            'locations' => $this->jsonArray($raw, 'locations', true),
            'os_templates' => $this->jsonArray($raw, 'os_templates', true),
            'delivery_mappings' => $this->jsonArray($raw, 'delivery_mappings', true),
            'sso_hosts' => $this->stringList($raw, 'sso_hosts'),
            'sso_allowed' => $this->boolean($raw['sso_allowed'] ?? null) ? 1 : 0,
            'destroy_allowed' => $this->boolean($raw['destroy_allowed'] ?? null) ? 1 : 0,
        ];
        if ($item['billing_cycles'] === [] || $item['actions'] === []) {
            throw new InvalidArgumentException('A policy template item requires at least one billing cycle and action.');
        }
        if ($item['sso_allowed'] === 1 && $item['sso_hosts'] === []) {
            throw new InvalidArgumentException('An SSO-enabled template item requires at least one allowed host.');
        }

        return $item;
    }

    private function stringList(array $request, string $key): array
    {
        $value = $request[$key] ?? null;
        if (is_array($value)) {
            return $this->normalizeStringList($value, $key);
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException("The {$key} value must be a list.");
        }

        $value = trim($this->normalizeSubmittedString($value));
        if ($value === '') {
            return [];
        }

        $decoded = $this->decodeJsonArray($value, $key, false);
        if ($decoded !== null) {
            return $this->normalizeStringList($decoded, $key);
        }

        return $this->normalizeStringList(preg_split('/[\r\n,]+/', $value) ?: [], $key);
    }

    private function jsonArray(array $request, string $key, bool $allowEmptyString = false): array
    {
        $value = $request[$key] ?? null;
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException("The {$key} value must be JSON.");
        }

        $value = trim($this->normalizeSubmittedString($value));
        if ($value === '' && $allowEmptyString) {
            return [];
        }

        return $this->decodeJsonArray($value, $key, true) ?? [];
    }

    private function decodeJsonArray(string $value, string $key, bool $required): ?array
    {
        $candidates = [$value];
        $stripped = stripslashes($value);
        if ($stripped !== $value) {
            $candidates[] = $stripped;
        }

        foreach (array_values(array_unique($candidates)) as $candidate) {
            if (trim($candidate) === '') {
                continue;
            }

            try {
                $decoded = json_decode($candidate, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            if (!is_array($decoded)) {
                throw new InvalidArgumentException("The {$key} value must decode to an array or object.");
            }

            return $decoded;
        }

        if ($required || preg_match('/^\s*[\[{]/', $value) === 1) {
            throw new InvalidArgumentException("The {$key} value must contain valid JSON.");
        }

        return null;
    }

    private function normalizeSubmittedString(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function normalizeStringList(array $values, string $key): array
    {
        $normalized = [];
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                throw new InvalidArgumentException("The {$key} value must contain only scalar list entries.");
            }

            $entry = trim((string) $value);
            if ($entry !== '') {
                $normalized[] = $entry;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function positiveInt(array $source, string $key): int
    {
        $value = $source[$key] ?? null;
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new InvalidArgumentException("The {$key} value must be a positive integer.");
        }

        $integer = (int) $value;
        if ($integer < 1) {
            throw new InvalidArgumentException("The {$key} value must be a positive integer.");
        }

        return $integer;
    }

    private function boolean(mixed $value): bool
    {
        return in_array($value, [1, '1', true, 'true', 'on', 'yes'], true);
    }

    private function boundedString(array $request, string $key, int $min, int $max): string
    {
        $value = trim((string) ($request[$key] ?? ''));
        if (strlen($value) < $min || strlen($value) > $max) {
            throw new InvalidArgumentException("The {$key} value is invalid.");
        }

        return $value;
    }
}
