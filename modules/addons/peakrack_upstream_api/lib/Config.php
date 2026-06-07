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

namespace PeakRack\UpstreamApi;

final class Config
{
    public const VERSION = '1.1.0';
    public const API_VERSION = 'v1';
    public const MINIMUM_PHP_VERSION = '8.2.0';
    public const SUPPORTED_WHMCS_SERIES = '9.0';
    public const SIGNATURE_TOLERANCE_SECONDS = 300;
    public const DEFAULT_RATE_LIMIT = 120;
    public const MAX_REQUEST_BODY_BYTES = 65536;
}
