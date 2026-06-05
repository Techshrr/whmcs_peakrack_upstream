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

namespace PeakRack\UpstreamApi\Contracts;

interface BillingGateway
{
    public function create(
        int $clientId,
        int $productId,
        string $billingCycle,
        string $paymentMethod,
        array $orderFields,
        string $operationId
    ): array;

    public function renew(
        int $clientId,
        int $serviceId,
        string $paymentMethod,
        ?int $existingInvoiceId,
        string $operationId
    ): array;

    public function changePackage(
        int $clientId,
        int $serviceId,
        int $productId,
        string $billingCycle,
        string $paymentMethod,
        array $configOptions,
        string $operationId
    ): array;

    public function compensateCreate(
        int $clientId,
        string $amount,
        int $orderId,
        int $invoiceId,
        string $operationId
    ): void;
}
