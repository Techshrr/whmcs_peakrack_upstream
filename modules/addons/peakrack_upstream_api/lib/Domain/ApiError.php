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

final class ApiError
{
    public const AUTHENTICATION_FAILED = 'AUTHENTICATION_FAILED';
    public const SIGNATURE_EXPIRED = 'SIGNATURE_EXPIRED';
    public const NONCE_REUSED = 'NONCE_REUSED';
    public const RATE_LIMITED = 'RATE_LIMITED';
    public const PRODUCT_NOT_ALLOWED = 'PRODUCT_NOT_ALLOWED';
    public const INVALID_PRODUCT_MAPPING = 'INVALID_PRODUCT_MAPPING';
    public const INSUFFICIENT_CREDIT = 'INSUFFICIENT_CREDIT';
    public const SERVICE_NOT_FOUND = 'SERVICE_NOT_FOUND';
    public const SERVICE_STATE_CONFLICT = 'SERVICE_STATE_CONFLICT';
    public const PROVISIONING_FAILED = 'PROVISIONING_FAILED';
    public const OPERATION_PROCESSING = 'OPERATION_PROCESSING';
    public const MANUAL_REVIEW_REQUIRED = 'MANUAL_REVIEW_REQUIRED';
    public const INTERNAL_ERROR = 'INTERNAL_ERROR';
}
