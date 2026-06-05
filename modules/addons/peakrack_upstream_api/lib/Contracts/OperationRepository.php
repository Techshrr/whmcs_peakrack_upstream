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

use PeakRack\UpstreamApi\Domain\Operation;

interface OperationRepository
{
    public function admit(Operation $operation, array $sanitizedPayload): Operation;

    public function findById(string $operationId): ?Operation;

    public function findByIdempotency(int $apiKeyId, string $idempotencyKey): ?Operation;

    public function save(Operation $operation): void;

    public function claimDue(int $limit, int $now, string $owner, int $lockUntil): array;

    public function appendEvent(string $operationId, string $stage, array $sanitizedContext): void;
}
