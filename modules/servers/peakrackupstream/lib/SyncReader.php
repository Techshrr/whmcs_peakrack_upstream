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

use Closure;
use PeakRack\Upstream\Api\ApiClientInterface;
use PeakRack\Upstream\Api\ApiException;

final class SyncReader
{
    private readonly Closure $clock;

    public function __construct(
        private readonly ApiClientInterface $api,
        private readonly ServiceProperties $properties,
        private readonly Idempotency $idempotency,
        ?callable $clock = null
    ) {
        $this->clock = $clock === null ? static fn (): int => time() : Closure::fromCallable($clock);
    }

    public function read(int $localServiceId): array
    {
        $localServiceId = Validator::positiveInt($localServiceId, 'local service ID');
        $operationId = $this->properties->get(ServiceProperties::UPSTREAM_OPERATION_ID);
        $idempotencyKey = $this->idempotency->current();
        if ($operationId === null || $idempotencyKey === null) {
            return $this->api->getService($localServiceId);
        }

        try {
            $operation = $this->api->getOperation(Validator::operationId($operationId));
        } catch (ApiException $exception) {
            if (in_array($exception->operationStatus(), ['failed', 'manual_review'], true)) {
                $this->properties->applyResponse([
                    'operation_id' => $exception->operationId() ?? $operationId,
                    'data' => ['service_status' => $exception->operationStatus()],
                ]);
                $this->idempotency->clearIfMatches($idempotencyKey);
                $this->properties->setLastSync((int) ($this->clock)());
                $this->properties->recordError(
                    'Upstream API error ' . $exception->errorCode() . ': ' . $exception->getMessage()
                );
            }

            throw $exception;
        }

        if (($operation['status'] ?? '') === 'completed') {
            $service = $this->api->getService($localServiceId);
            $this->idempotency->clearIfMatches($idempotencyKey);
            return $service;
        }

        return $operation;
    }
}
