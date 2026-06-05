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

final class Config
{
    public const VERSION = '1.0.0';
    public const API_VERSION = 'v1';
    public const DEFAULT_TIMEOUT = 30;
    public const MINIMUM_TIMEOUT = 5;
    public const MAXIMUM_TIMEOUT = 120;
}
