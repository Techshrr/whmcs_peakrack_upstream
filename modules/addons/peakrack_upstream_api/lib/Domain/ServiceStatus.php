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

final class ServiceStatus
{
    public const PENDING = 'pending';
    public const PROVISIONING = 'provisioning';
    public const ACTIVE = 'active';
    public const SUSPENDED = 'suspended';
    public const CANCELLATION_PENDING = 'cancellation_pending';
    public const TERMINATED = 'terminated';
    public const FAILED = 'failed';
    public const MANUAL_REVIEW = 'manual_review';
    public const UNKNOWN = 'unknown';

    public static function normalize(string $status): string
    {
        return match (strtolower(trim($status))) {
            'pending' => self::PENDING,
            'provisioning' => self::PROVISIONING,
            'active' => self::ACTIVE,
            'suspended' => self::SUSPENDED,
            'pending cancellation', 'cancellation_pending', 'cancellation pending' => self::CANCELLATION_PENDING,
            'terminated', 'cancelled', 'canceled' => self::TERMINATED,
            'failed' => self::FAILED,
            'manual_review', 'manual review' => self::MANUAL_REVIEW,
            default => self::UNKNOWN,
        };
    }
}
