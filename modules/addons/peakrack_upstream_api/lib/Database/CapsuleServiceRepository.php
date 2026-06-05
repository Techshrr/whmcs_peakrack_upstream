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

use PeakRack\UpstreamApi\Contracts\ServiceRepository;
use PeakRack\UpstreamApi\Domain\ServiceRecord;
use WHMCS\Database\Capsule;

final class CapsuleServiceRepository implements ServiceRepository
{
    public function findByLocalId(int $apiKeyId, int $localServiceId): ?ServiceRecord
    {
        $row = Capsule::table(Schema::SERVICES)
            ->where('api_key_id', $apiKeyId)
            ->where('local_service_id', $localServiceId)
            ->first();

        return $row === null ? null : $this->hydrate((array) $row);
    }

    public function findByUpstreamServiceId(int $upstreamServiceId): ?ServiceRecord
    {
        $row = Capsule::table(Schema::SERVICES)
            ->where('upstream_service_id', $upstreamServiceId)
            ->first();

        return $row === null ? null : $this->hydrate((array) $row);
    }

    public function save(ServiceRecord $service): void
    {
        $now = time();
        $attributes = [
            'upstream_service_id' => $service->upstreamServiceId(),
            'upstream_order_id' => $service->upstreamOrderId(),
            'upstream_invoice_id' => $service->upstreamInvoiceId(),
            'product_id' => $service->productId(),
            'billing_cycle' => $service->billingCycle(),
            'service_status' => $service->status(),
            'cached_primary_ip' => $service->primaryIp(),
            'cached_panel_url' => $service->panelUrl(),
            'updated_at' => $now,
        ];

        $query = Capsule::table(Schema::SERVICES)
            ->where('api_key_id', $service->apiKeyId())
            ->where('local_service_id', $service->localServiceId());

        if ($query->exists()) {
            $query->update($attributes);
            return;
        }

        $attributes['api_key_id'] = $service->apiKeyId();
        $attributes['local_service_id'] = $service->localServiceId();
        $attributes['created_at'] = $now;
        Capsule::table(Schema::SERVICES)->insert($attributes);
    }

    public function isManagedUpstreamService(int $upstreamServiceId): bool
    {
        return Capsule::table(Schema::SERVICES)
            ->where('upstream_service_id', $upstreamServiceId)
            ->exists();
    }

    private function hydrate(array $row): ServiceRecord
    {
        return new ServiceRecord(
            (int) $row['api_key_id'],
            (int) $row['local_service_id'],
            $row['upstream_service_id'] === null ? null : (int) $row['upstream_service_id'],
            $row['upstream_order_id'] === null ? null : (int) $row['upstream_order_id'],
            $row['upstream_invoice_id'] === null ? null : (int) $row['upstream_invoice_id'],
            (int) $row['product_id'],
            (string) $row['billing_cycle'],
            (string) $row['service_status'],
            $row['cached_primary_ip'] === null ? null : (string) $row['cached_primary_ip'],
            $row['cached_panel_url'] === null ? null : (string) $row['cached_panel_url']
        );
    }
}
