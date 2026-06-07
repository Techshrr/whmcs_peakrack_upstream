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

use PeakRack\UpstreamApi\Security\Redactor;
use RuntimeException;

final class TemplateRenderer
{
    private const LABELS = [
        'dashboard' => 'Dashboard',
        'api_keys' => 'API Keys',
        'product_policies' => 'Product Policies',
        'policy_templates' => 'Policy Templates',
        'onboarding_applications' => 'Onboarding Applications',
        'operations' => 'Operations',
        'services' => 'Services',
        'system_health' => 'System Health',
    ];

    public function __construct(private readonly string $templateDirectory)
    {
    }

    public function render(array $view): string
    {
        $page = (string) ($view['page'] ?? 'dashboard');
        if (!isset(self::LABELS[$page])) {
            $page = 'dashboard';
        }

        $path = rtrim($this->templateDirectory, '/\\') . '/' . str_replace('_', '-', $page) . '.tpl';
        $template = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($template)) {
            throw new RuntimeException('The administrator template could not be loaded.');
        }

        $moduleLink = (string) ($view['modulelink'] ?? '');
        $csrf = (string) ($view['csrf_token'] ?? '');
        $notice = (string) ($view['notice'] ?? '');
        $secret = (string) ($view['secret_once'] ?? '');
        $data = is_array($view['data'] ?? null) ? $view['data'] : [];

        return strtr($template, [
            '{{navigation}}' => $this->navigation($moduleLink, $page),
            '{{notice}}' => $notice === '' ? '' : '<div class="alert alert-info">' . $this->escape($notice) . '</div>',
            '{{secret_once}}' => $secret === ''
                ? ''
                : '<div class="alert alert-warning"><strong>API Secret:</strong> <code>'
                    . $this->escape($secret)
                    . '</code><br>Store this value now. It will not be shown again.</div>',
            '{{forms}}' => $this->forms($page, $moduleLink, $csrf),
            '{{content}}' => $this->table($data),
        ]);
    }

    private function navigation(string $moduleLink, string $current): string
    {
        $links = [];
        foreach (self::LABELS as $page => $label) {
            $class = $page === $current ? ' class="active"' : '';
            $links[] = '<li' . $class . '><a href="' . $this->escape($moduleLink . '&page=' . $page) . '">'
                . $this->escape($label)
                . '</a></li>';
        }

        return '<ul class="nav nav-tabs" style="margin-bottom:20px">' . implode('', $links) . '</ul>';
    }

    private function forms(string $page, string $moduleLink, string $csrf): string
    {
        $open = '<form method="post" action="' . $this->escape($moduleLink) . '" class="form-inline" style="margin-bottom:15px">'
            . '<input type="hidden" name="page" value="' . $this->escape($page) . '">'
            . '<input type="hidden" name="csrf_token" value="' . $this->escape($csrf) . '">';
        $close = '</form>';

        if ($page === 'api_keys') {
            return $open
                . '<input type="hidden" name="action" value="create_key">'
                . $this->input('client_id', 'Reseller Client ID')
                . $this->input('ip_allowlist', 'IP allowlist')
                . $this->input('rate_limit_per_minute', 'Rate/min', '120')
                . $this->checkbox(
                    'acknowledge_empty_ip_allowlist',
                    'Allow requests from any source IP when the allowlist is empty'
                )
                . '<button type="submit" class="btn btn-primary">Create API Key</button>'
                . $close
                . $open
                . '<input type="hidden" name="action" value="update_key_settings">'
                . $this->input('key_id', 'Key ID')
                . $this->input('ip_allowlist', 'New IP allowlist')
                . $this->input('rate_limit_per_minute', 'New rate/min', '120')
                . $this->checkbox(
                    'acknowledge_empty_ip_allowlist',
                    'Allow requests from any source IP when the allowlist is empty'
                )
                . '<button type="submit" class="btn btn-primary">Update Key Settings</button>'
                . $close
                . $open
                . $this->input('key_id', 'Key ID')
                . '<button name="action" value="rotate_key" class="btn btn-default">Rotate Secret</button> '
                . '<button name="action" value="set_key_enabled" class="btn btn-default">Enable</button> '
                . '<input type="hidden" name="enabled" value="1">'
                . '<button name="action" value="delete_key" class="btn btn-danger">Delete or Disable</button>'
                . $close
                . $open
                . '<input type="hidden" name="action" value="set_key_enabled">'
                . '<input type="hidden" name="enabled" value="0">'
                . $this->input('key_id', 'Key ID to disable')
                . '<button type="submit" class="btn btn-warning">Disable</button>'
                . $close;
        }

        if ($page === 'product_policies') {
            return $open
                . '<input type="hidden" name="action" value="save_policy">'
                . $this->input('policy_id', 'Policy ID when updating')
                . $this->input('api_key_id', 'API Key ID')
                . $this->input('product_id', 'Product ID')
                . $this->input('billing_cycles', 'Billing cycles', 'monthly')
                . $this->input('actions', 'Actions', 'create,renew,suspend,unsuspend,terminate,change_package')
                . $this->input('locations', 'Locations JSON', '{}')
                . $this->input('os_templates', 'OS templates JSON', '{}')
                . $this->input('delivery_mappings', 'Delivery mappings JSON', '{}')
                . $this->input('sso_hosts', 'SSO hosts')
                . $this->checkbox('enabled', 'Policy enabled', true)
                . $this->checkbox('sso_allowed', 'Allow SSO')
                . $this->checkbox('destroy_allowed', 'Allow immediate destroy')
                . '<button type="submit" class="btn btn-primary">Save Policy</button>'
                . $close;
        }

        if ($page === 'policy_templates') {
            return $open
                . '<input type="hidden" name="action" value="save_template">'
                . $this->input('template_id', 'Template ID when updating')
                . $this->input('name', 'Template name')
                . $this->input('description', 'Description')
                . $this->checkbox('enabled', 'Template enabled', true)
                . $this->textarea(
                    'items_json',
                    'Items JSON: one JSON array item becomes one product policy row.',
                    '[{"product_id":1,"billing_cycles":"monthly","actions":"create,renew","locations":"","os_templates":"","delivery_mappings":"","sso_hosts":"","sso_allowed":"0","destroy_allowed":"0"}]'
                )
                . '<button type="submit" class="btn btn-primary">Save Template</button>'
                . $close
                . $open
                . '<input type="hidden" name="action" value="disable_template">'
                . $this->input('template_id', 'Template ID to disable')
                . '<button type="submit" class="btn btn-warning">Disable Template</button>'
                . $close;
        }

        if ($page === 'operations') {
            return $open
                . '<input type="hidden" name="action" value="retry_operation">'
                . $this->input('operation_id', 'Manual-review Operation ID')
                . '<button type="submit" class="btn btn-warning">Retry Operation</button>'
                . $close;
        }

        return '';
    }

    private function input(string $name, string $placeholder, string $value = ''): string
    {
        return '<input class="form-control" style="margin-right:5px;margin-bottom:5px" name="'
            . $this->escape($name)
            . '" placeholder="'
            . $this->escape($placeholder)
            . '" value="'
            . $this->escape($value)
            . '">';
    }

    private function textarea(string $name, string $label, string $value = ''): string
    {
        return '<div class="form-group" style="display:block;margin-bottom:10px">'
            . '<label style="display:block">' . $this->escape($label) . '</label>'
            . '<textarea class="form-control" style="width:100%;min-height:90px" name="'
            . $this->escape($name)
            . '">'
            . $this->escape($value)
            . '</textarea></div>';
    }

    private function checkbox(string $name, string $label, bool $checked = false): string
    {
        return '<label class="checkbox-inline" style="margin-right:10px;margin-bottom:5px">'
            . '<input type="hidden" name="' . $this->escape($name) . '" value="0">'
            . '<input type="checkbox" name="' . $this->escape($name) . '" value="1"'
            . ($checked ? ' checked' : '')
            . '> '
            . $this->escape($label)
            . '</label>';
    }

    private function table(array $data): string
    {
        $redacted = Redactor::redact($data);
        $redacted = is_array($redacted) ? $redacted : [];
        if ($redacted === []) {
            return '<p class="text-muted">No records found.</p>';
        }

        $rows = array_is_list($redacted)
            ? $redacted
            : array_map(
                static fn (mixed $value, string|int $key): array => ['name' => $key, 'value' => $value],
                array_values($redacted),
                array_keys($redacted)
            );
        $rows = array_slice(array_values(array_filter($rows, 'is_array')), 0, 100);
        $columns = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $column) {
                if (!in_array($column, $columns, true)) {
                    $columns[] = $column;
                }
            }
        }

        $header = implode('', array_map(
            fn (string|int $column): string => '<th>' . $this->escape((string) $column) . '</th>',
            $columns
        ));
        $body = '';
        foreach ($rows as $row) {
            $cells = '';
            foreach ($columns as $column) {
                $value = $row[$column] ?? null;
                if (is_array($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                } elseif (is_bool($value)) {
                    $value = $value ? 'true' : 'false';
                } elseif ($value === null) {
                    $value = '';
                }
                $cells .= '<td>' . $this->escape((string) $value) . '</td>';
            }
            $body .= '<tr>' . $cells . '</tr>';
        }

        return '<div class="table-responsive"><table class="table table-striped table-condensed"><thead><tr>'
            . $header
            . '</tr></thead><tbody>'
            . $body
            . '</tbody></table></div>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
