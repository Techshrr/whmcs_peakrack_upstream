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

use InvalidArgumentException;

final class OnboardingApplication
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const NEEDS_INFO = 'needs_info';

    private function __construct(
        private readonly int $id,
        private readonly int $clientId,
        private readonly string $status,
        private readonly string $brandName,
        private readonly string $downstreamDomain,
        private readonly string $outboundIpsJson,
        private readonly string $businessType,
        private readonly string $telegram,
        private readonly ?string $qq = null,
        private readonly ?string $phone = null,
        private readonly ?string $notes = null,
        private readonly ?int $termsAcceptedAt = null,
        private readonly ?string $termsAcceptedIp = null,
        private readonly ?string $adminMessage = null,
        private readonly ?int $adminId = null,
        private readonly ?int $reviewedAt = null,
        private readonly ?int $apiKeyId = null,
        private readonly ?int $templateId = null,
        private readonly bool $secretPendingDisplay = false,
        private readonly ?int $createdAt = null,
        private readonly ?int $updatedAt = null
    ) {
    }

    public static function restore(array $row): self
    {
        return new self(
            (int) ($row['id'] ?? 0),
            (int) ($row['client_id'] ?? 0),
            (string) ($row['status'] ?? self::PENDING),
            (string) ($row['brand_name'] ?? ''),
            (string) ($row['downstream_domain'] ?? ''),
            (string) ($row['outbound_ips_json'] ?? '[]'),
            (string) ($row['business_type'] ?? ''),
            (string) ($row['telegram'] ?? ''),
            self::nullableString($row, 'qq'),
            self::nullableString($row, 'phone'),
            self::nullableString($row, 'notes'),
            self::nullableInt($row, 'terms_accepted_at'),
            self::nullableString($row, 'terms_accepted_ip'),
            self::nullableString($row, 'admin_message'),
            self::nullableInt($row, 'admin_id'),
            self::nullableInt($row, 'reviewed_at'),
            self::nullableInt($row, 'api_key_id'),
            self::nullableInt($row, 'template_id'),
            self::boolValue($row['secret_pending_display'] ?? false),
            self::nullableInt($row, 'created_at'),
            self::nullableInt($row, 'updated_at')
        );
    }

    public function approve(int $apiKeyId, int $templateId, int $adminId, int $now): self
    {
        if ($this->status !== self::PENDING) {
            throw new InvalidArgumentException('Only pending applications can be approved.');
        }

        return $this->withReview(
            self::APPROVED,
            null,
            $adminId,
            $now,
            $apiKeyId,
            $templateId,
            true
        );
    }

    public function reject(string $message, int $adminId, int $now): self
    {
        if ($this->status !== self::PENDING) {
            throw new InvalidArgumentException('Only pending applications can be rejected.');
        }

        return $this->withReview(
            self::REJECTED,
            $message,
            $adminId,
            $now,
            $this->apiKeyId,
            $this->templateId,
            false
        );
    }

    public function markSecretDisplayed(int $now): self
    {
        return $this->withSecretPendingDisplay(false, $now);
    }

    public function markSecretPendingDisplay(int $now): self
    {
        return $this->withSecretPendingDisplay(true, $now);
    }

    public function toRow(): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->clientId,
            'status' => $this->status,
            'active_client_key' => $this->activeClientKey(),
            'brand_name' => $this->brandName,
            'downstream_domain' => $this->downstreamDomain,
            'outbound_ips_json' => $this->outboundIpsJson,
            'business_type' => $this->businessType,
            'telegram' => $this->telegram,
            'qq' => $this->qq,
            'phone' => $this->phone,
            'notes' => $this->notes,
            'terms_accepted_at' => $this->termsAcceptedAt,
            'terms_accepted_ip' => $this->termsAcceptedIp,
            'admin_message' => $this->adminMessage,
            'admin_id' => $this->adminId,
            'reviewed_at' => $this->reviewedAt,
            'api_key_id' => $this->apiKeyId,
            'template_id' => $this->templateId,
            'secret_pending_display' => $this->secretPendingDisplay,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    public function id(): int
    {
        return $this->id;
    }

    public function clientId(): int
    {
        return $this->clientId;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function apiKeyId(): ?int
    {
        return $this->apiKeyId;
    }

    public function templateId(): ?int
    {
        return $this->templateId;
    }

    public function secretPendingDisplay(): bool
    {
        return $this->secretPendingDisplay;
    }

    public function outboundIpsJson(): string
    {
        return $this->outboundIpsJson;
    }

    public function brandName(): string
    {
        return $this->brandName;
    }

    public function downstreamDomain(): string
    {
        return $this->downstreamDomain;
    }

    public function telegram(): string
    {
        return $this->telegram;
    }

    private function activeClientKey(): ?string
    {
        return in_array($this->status, [self::PENDING, self::APPROVED], true)
            ? 'client:' . $this->clientId
            : null;
    }

    private function withReview(
        string $status,
        ?string $adminMessage,
        int $adminId,
        int $reviewedAt,
        ?int $apiKeyId,
        ?int $templateId,
        bool $secretPendingDisplay
    ): self {
        return new self(
            $this->id,
            $this->clientId,
            $status,
            $this->brandName,
            $this->downstreamDomain,
            $this->outboundIpsJson,
            $this->businessType,
            $this->telegram,
            $this->qq,
            $this->phone,
            $this->notes,
            $this->termsAcceptedAt,
            $this->termsAcceptedIp,
            $adminMessage,
            $adminId,
            $reviewedAt,
            $apiKeyId,
            $templateId,
            $secretPendingDisplay,
            $this->createdAt,
            $reviewedAt
        );
    }

    private function withSecretPendingDisplay(bool $secretPendingDisplay, int $updatedAt): self
    {
        return new self(
            $this->id,
            $this->clientId,
            $this->status,
            $this->brandName,
            $this->downstreamDomain,
            $this->outboundIpsJson,
            $this->businessType,
            $this->telegram,
            $this->qq,
            $this->phone,
            $this->notes,
            $this->termsAcceptedAt,
            $this->termsAcceptedIp,
            $this->adminMessage,
            $this->adminId,
            $this->reviewedAt,
            $this->apiKeyId,
            $this->templateId,
            $secretPendingDisplay,
            $this->createdAt,
            $updatedAt
        );
    }

    private static function boolValue(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }

    private static function nullableInt(array $row, string $key): ?int
    {
        return isset($row[$key]) && $row[$key] !== '' ? (int) $row[$key] : null;
    }

    private static function nullableString(array $row, string $key): ?string
    {
        return isset($row[$key]) && $row[$key] !== '' ? (string) $row[$key] : null;
    }
}
