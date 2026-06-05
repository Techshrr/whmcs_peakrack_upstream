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

final class ProductPolicy
{
    public function __construct(
        private readonly int $productId,
        private readonly array $billingCycles,
        private readonly array $actions,
        private readonly array $locations = [],
        private readonly array $osTemplates = [],
        private readonly bool $destroyAllowed = false
    ) {
    }

    public function assertAllows(
        string $action,
        int $productId,
        string $cycle,
        ?string $location,
        ?string $osTemplate,
        bool $destroy
    ): void {
        if ($productId !== $this->productId) {
            throw new ValidationException(ApiError::PRODUCT_NOT_ALLOWED, 'Product is not allowed.');
        }

        if (!in_array($action, $this->actions, true)) {
            throw new ValidationException(ApiError::PRODUCT_NOT_ALLOWED, 'Action is not allowed.');
        }

        if (!in_array($cycle, $this->billingCycles, true)) {
            throw new ValidationException(ApiError::PRODUCT_NOT_ALLOWED, 'Billing cycle is not allowed.');
        }

        if ($location !== null && !array_key_exists($location, $this->locations)) {
            throw new ValidationException(ApiError::INVALID_PRODUCT_MAPPING, 'Location is not allowed.');
        }

        if ($osTemplate !== null && !array_key_exists($osTemplate, $this->osTemplates)) {
            throw new ValidationException(ApiError::INVALID_PRODUCT_MAPPING, 'OS template is not allowed.');
        }

        if ($destroy && !$this->destroyAllowed) {
            throw new ValidationException(ApiError::PRODUCT_NOT_ALLOWED, 'Destroy is not allowed.');
        }
    }

    public function productId(): int
    {
        return $this->productId;
    }

    public function billingCycles(): array
    {
        return $this->billingCycles;
    }

    public function actions(): array
    {
        return $this->actions;
    }

    public function locationMapping(?string $location): ?array
    {
        return $location === null ? null : ($this->locations[$location] ?? null);
    }

    public function osTemplateMapping(?string $osTemplate): ?array
    {
        return $osTemplate === null ? null : ($this->osTemplates[$osTemplate] ?? null);
    }

    public function destroyAllowed(): bool
    {
        return $this->destroyAllowed;
    }
}
