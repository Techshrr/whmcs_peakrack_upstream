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

final class ServiceRecord
{
    public function __construct(
        private readonly int $apiKeyId,
        private readonly int $localServiceId,
        private readonly ?int $upstreamServiceId,
        private readonly ?int $upstreamOrderId,
        private readonly ?int $upstreamInvoiceId,
        private readonly int $productId,
        private readonly string $billingCycle,
        private readonly string $status,
        private readonly ?string $primaryIp = null,
        private readonly ?string $panelUrl = null
    ) {
    }

    public function apiKeyId(): int
    {
        return $this->apiKeyId;
    }

    public function localServiceId(): int
    {
        return $this->localServiceId;
    }

    public function upstreamServiceId(): ?int
    {
        return $this->upstreamServiceId;
    }

    public function upstreamOrderId(): ?int
    {
        return $this->upstreamOrderId;
    }

    public function upstreamInvoiceId(): ?int
    {
        return $this->upstreamInvoiceId;
    }

    public function productId(): int
    {
        return $this->productId;
    }

    public function billingCycle(): string
    {
        return $this->billingCycle;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function toApiArray(): array
    {
        return [
            'local_service_id' => $this->localServiceId,
            'upstream_service_id' => $this->upstreamServiceId,
            'upstream_order_id' => $this->upstreamOrderId,
            'upstream_invoice_id' => $this->upstreamInvoiceId,
            'service_status' => $this->status,
            'primary_ip' => $this->primaryIp,
            'panel_url' => $this->panelUrl,
        ];
    }
}
