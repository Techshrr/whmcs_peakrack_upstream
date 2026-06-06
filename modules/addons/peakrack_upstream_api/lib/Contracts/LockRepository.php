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

interface LockRepository
{
    public function acquire(string $resource, string $owner, int $expiresAt): bool;

    public function release(string $resource, string $owner): void;

    public function purgeExpired(int $now): int;
}
