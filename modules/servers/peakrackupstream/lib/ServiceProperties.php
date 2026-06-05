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

namespace PeakRack\Upstream;

use InvalidArgumentException;
use RuntimeException;

final class ServiceProperties
{
    public const UPSTREAM_SERVICE_ID = 'Upstream Service ID';
    public const UPSTREAM_ORDER_ID = 'Upstream Order ID';
    public const UPSTREAM_INVOICE_ID = 'Upstream Invoice ID';
    public const UPSTREAM_OPERATION_ID = 'Upstream Operation ID';
    public const UPSTREAM_STATUS = 'Upstream Status';
    public const UPSTREAM_PANEL_URL = 'Upstream Panel URL';
    public const PROVISIONING_ERROR = 'Provisioning Error';
    public const LAST_SYNC_TIME = 'Last Sync Time';
    public const CURRENT_IDEMPOTENCY_KEY = 'Current Idempotency Key';
    public const DEDICATED_IP = 'Dedicated IP';

    private const ADMIN_NAMES = [
        self::UPSTREAM_SERVICE_ID,
        self::UPSTREAM_ORDER_ID,
        self::UPSTREAM_INVOICE_ID,
        self::UPSTREAM_OPERATION_ID,
        self::UPSTREAM_STATUS,
        self::UPSTREAM_PANEL_URL,
        self::PROVISIONING_ERROR,
        self::LAST_SYNC_TIME,
        self::CURRENT_IDEMPOTENCY_KEY,
    ];

    private const SERVICE_STATUSES = [
        'pending',
        'provisioning',
        'active',
        'suspended',
        'cancellation_pending',
        'terminated',
        'failed',
        'manual_review',
        'unknown',
    ];

    public function __construct(private readonly object $store)
    {
        if (!method_exists($store, 'get') || !method_exists($store, 'save')) {
            throw new InvalidArgumentException('The WHMCS service property store is invalid.');
        }
    }

    public static function adminPropertyNames(): array
    {
        return self::ADMIN_NAMES;
    }

    public function get(string $name): ?string
    {
        $this->assertKnown($name);
        $value = $this->store->get($name);
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_scalar($value)) {
            throw new RuntimeException("The {$name} service property is invalid.");
        }

        return (string) $value;
    }

    public function save(array $values): void
    {
        $safe = [];
        foreach ($values as $name => $value) {
            $this->assertKnown((string) $name);
            if (!is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException("The {$name} service property value is invalid.");
            }
            $safe[(string) $name] = $value === null ? '' : (string) $value;
        }
        if ($safe !== []) {
            $this->store->save($safe);
        }
    }

    public function applyResponse(array $response): void
    {
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $values = [];
        foreach ([
            'upstream_service_id' => self::UPSTREAM_SERVICE_ID,
            'upstream_order_id' => self::UPSTREAM_ORDER_ID,
            'upstream_invoice_id' => self::UPSTREAM_INVOICE_ID,
        ] as $source => $property) {
            if (array_key_exists($source, $data)) {
                $id = $this->positiveId($data[$source]);
                if ($id !== null) {
                    $values[$property] = (string) $id;
                }
            }
        }

        $operationId = $response['operation_id'] ?? null;
        if (is_string($operationId) && $this->isOperationId($operationId)) {
            $values[self::UPSTREAM_OPERATION_ID] = strtolower($operationId);
        }
        $status = $data['service_status'] ?? null;
        if (is_string($status) && in_array($status, self::SERVICE_STATUSES, true)) {
            $values[self::UPSTREAM_STATUS] = $status;
        }
        $primaryIp = $data['primary_ip'] ?? null;
        if (is_string($primaryIp) && filter_var($primaryIp, FILTER_VALIDATE_IP) !== false) {
            $values[self::DEDICATED_IP] = $primaryIp;
        }
        $panelUrl = $data['panel_url'] ?? null;
        if (is_string($panelUrl) && $this->isHttpsUrl($panelUrl)) {
            $values[self::UPSTREAM_PANEL_URL] = $panelUrl;
        }

        $this->save($values);
    }

    public function setLastSync(int $timestamp): void
    {
        if ($timestamp < 1) {
            throw new InvalidArgumentException('The synchronization timestamp is invalid.');
        }

        $this->save([self::LAST_SYNC_TIME => gmdate('Y-m-d\TH:i:s\Z', $timestamp)]);
    }

    public function recordError(string $message): void
    {
        $message = trim((string) preg_replace('/[\x00-\x1f\x7f]/', ' ', $message));
        $this->save([
            self::PROVISIONING_ERROR => $message === ''
                ? 'The upstream operation failed.'
                : substr($message, 0, 512),
        ]);
    }

    public function clearError(): void
    {
        $this->save([self::PROVISIONING_ERROR => '']);
    }

    public function hasUpstreamBinding(): bool
    {
        return $this->get(self::UPSTREAM_SERVICE_ID) !== null;
    }

    public function customerData(string $localStatus, bool $ssoAvailable): array
    {
        $primaryIp = $this->get(self::DEDICATED_IP);
        $panelUrl = $this->get(self::UPSTREAM_PANEL_URL);

        return [
            'status' => $localStatus,
            'primary_ip' => $primaryIp !== null && filter_var($primaryIp, FILTER_VALIDATE_IP) !== false
                ? $primaryIp
                : null,
            'panel_url' => $panelUrl !== null && $this->isHttpsUrl($panelUrl) ? $panelUrl : null,
            'last_sync_time' => $this->get(self::LAST_SYNC_TIME),
            'sso_available' => $ssoAvailable,
        ];
    }

    private function assertKnown(string $name): void
    {
        if (!in_array($name, [...self::ADMIN_NAMES, self::DEDICATED_IP], true)) {
            throw new InvalidArgumentException('The requested service property is not allowed.');
        }
    }

    private function positiveId(mixed $value): ?int
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            return null;
        }

        $id = (int) $value;
        return $id > 0 ? $id : null;
    }

    private function isOperationId(string $operationId): bool
    {
        return preg_match(
            '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89aAbB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/',
            $operationId
        ) === 1;
    }

    private function isHttpsUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https'
            && (string) parse_url($url, PHP_URL_HOST) !== '';
    }
}
