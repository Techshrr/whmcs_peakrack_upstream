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

namespace PeakRack\UpstreamApi\Application;

use InvalidArgumentException;
use PeakRack\UpstreamApi\Contracts\Clock;
use PeakRack\UpstreamApi\Contracts\IdGenerator;
use PeakRack\UpstreamApi\Contracts\LockRepository;
use PeakRack\UpstreamApi\Contracts\OperationExecutor;
use PeakRack\UpstreamApi\Contracts\OperationRepository;
use PeakRack\UpstreamApi\Domain\ApiError;
use PeakRack\UpstreamApi\Domain\Operation;
use PeakRack\UpstreamApi\Domain\ValidationException;
use PeakRack\UpstreamApi\Security\Redactor;
use Throwable;

final class OperationService
{
    private const LOCK_SECONDS = 30;

    public function __construct(
        private readonly OperationRepository $operations,
        private readonly LockRepository $locks,
        private readonly OperationExecutor $executor,
        private readonly IdGenerator $ids,
        private readonly Clock $clock
    ) {
    }

    public function admit(
        int $apiKeyId,
        ?int $localServiceId,
        string $action,
        string $idempotencyKey,
        array $businessPayload,
        bool $creditConsuming,
        ?int $billingClientId = null
    ): Operation {
        if ($creditConsuming && ($billingClientId === null || $billingClientId < 1)) {
            throw new InvalidArgumentException('A valid reseller client ID is required for a Credit-consuming operation.');
        }

        $normalized = $this->normalize($businessPayload);
        $sanitized = Redactor::redact($normalized);
        $executionPayload = $normalized;
        if ($creditConsuming && is_array($sanitized)) {
            $sanitized['_billing_client_id'] = $billingClientId;
            $executionPayload['_billing_client_id'] = $billingClientId;
        }
        $requested = Operation::admit(
            $this->ids->uuid(),
            $apiKeyId,
            $action,
            $idempotencyKey,
            hash('sha256', $this->encode($normalized)),
            $localServiceId,
            is_array($sanitized) ? $sanitized : [],
            $executionPayload
        );
        $resources = null;

        if ($action === 'create') {
            $existing = $this->operations->findByIdempotency($apiKeyId, $idempotencyKey);
            if ($existing !== null) {
                return $this->operations->admit($requested, $requested->sanitizedPayload());
            }

            $resources = $this->acquireResources($requested, $creditConsuming, $billingClientId);
            if ($resources === null) {
                throw new ValidationException(
                    ApiError::OPERATION_PROCESSING,
                    'Another create operation currently owns the required lock.',
                    409
                );
            }
        }

        try {
            $admitted = $this->operations->admit($requested, $requested->sanitizedPayload());
        } catch (Throwable $exception) {
            if ($resources !== null) {
                $this->releaseResources($resources, $requested->id());
            }
            throw $exception;
        }

        if ($admitted->id() !== $requested->id()) {
            if ($resources !== null) {
                $this->releaseResources($resources, $requested->id());
            }
            return $admitted;
        }

        $resources ??= $this->acquireResources($admitted, $creditConsuming, $billingClientId);
        if ($resources === null) {
            return $admitted;
        }

        try {
            $this->operations->appendEvent($admitted->id(), 'accepted', []);
            $processing = $admitted->start();
            $this->operations->save($processing);
            $this->operations->appendEvent($processing->id(), 'processing', []);

            try {
                $result = $this->executor->execute($processing);
            } catch (OperationExecutionException $exception) {
                $result = $this->scheduleExecutionException($processing, $exception);
            } catch (Throwable) {
                $result = $processing->manualReview(
                    ApiError::INTERNAL_ERROR,
                    'The operation requires administrator review.'
                );
            }

            $this->operations->save($result);
            $this->operations->appendEvent($result->id(), (string) $result->stage(), [
                'error_code' => $result->errorCode(),
            ]);

            return $result;
        } finally {
            $this->releaseResources($resources, $admitted->id());
        }
    }

    private function acquireResources(
        Operation $operation,
        bool $creditConsuming,
        ?int $billingClientId
    ): ?array
    {
        $resources = [];
        if ($operation->localServiceId() !== null) {
            $resources[] = 'service:' . $operation->apiKeyId() . ':' . $operation->localServiceId();
        }
        if ($creditConsuming) {
            $resources[] = 'billing:' . $billingClientId;
        }

        $acquired = [];
        foreach ($resources as $resource) {
            if (!$this->locks->acquire($resource, $operation->id(), $this->clock->now() + self::LOCK_SECONDS)) {
                $this->releaseResources(array_reverse($acquired), $operation->id());
                return null;
            }
            $acquired[] = $resource;
        }

        return array_reverse($acquired);
    }

    private function releaseResources(array $resources, string $owner): void
    {
        foreach ($resources as $resource) {
            $this->locks->release($resource, $owner);
        }
    }

    private function scheduleExecutionException(
        Operation $operation,
        OperationExecutionException $exception
    ): Operation {
        $nextAttemptAt = $this->clock->now() + 30;
        if ($exception->isUnknownOutcome()) {
            return $operation->awaitVerification(
                $exception->apiErrorCode(),
                'The operation outcome must be verified.',
                $nextAttemptAt
            );
        }

        return $operation->retry(
            $exception->apiErrorCode(),
            'The operation will be retried.',
            $nextAttemptAt
        );
    }

    private function normalize(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed => is_array($item) ? $this->normalize($item) : $item,
                $value
            );
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->normalize($item);
            }
        }

        return $value;
    }

    private function encode(array $value): string
    {
        return (string) json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }
}
