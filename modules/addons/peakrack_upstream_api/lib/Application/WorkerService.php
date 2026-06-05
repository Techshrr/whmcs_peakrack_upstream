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

use PeakRack\UpstreamApi\Contracts\Clock;
use PeakRack\UpstreamApi\Contracts\LockRepository;
use PeakRack\UpstreamApi\Contracts\NonceRepository;
use PeakRack\UpstreamApi\Contracts\OperationExecutor;
use PeakRack\UpstreamApi\Contracts\OperationRepository;
use PeakRack\UpstreamApi\Domain\ApiError;
use PeakRack\UpstreamApi\Domain\Operation;
use PeakRack\UpstreamApi\Security\Redactor;
use Throwable;

final class WorkerService
{
    private const CLAIM_SECONDS = 60;
    private const LOCK_SECONDS = 60;
    private const MAX_ATTEMPTS = 12;
    private const MAX_BACKOFF_SECONDS = 3600;

    public function __construct(
        private readonly OperationRepository $operations,
        private readonly NonceRepository $nonces,
        private readonly LockRepository $locks,
        private readonly OperationExecutor $executor,
        private readonly Clock $clock
    ) {
    }

    public function run(string $owner, int $limit): array
    {
        $now = $this->clock->now();
        $this->nonces->purgeExpired($now);
        $this->locks->purgeExpired($now);
        $due = $this->operations->claimDue($limit, $now, $owner, $now + self::CLAIM_SECONDS);
        $summary = [
            'claimed' => count($due),
            'completed' => 0,
            'processing' => 0,
            'retried' => 0,
            'manual_review' => 0,
        ];

        foreach ($due as $operation) {
            $result = $this->process($operation);
            $this->operations->save($result);
            $this->operations->appendEvent(
                $result->id(),
                (string) $result->stage(),
                (array) Redactor::redact(['error_code' => $result->errorCode()])
            );

            if ($result->status() === Operation::COMPLETED) {
                $summary['completed']++;
            } elseif ($result->status() === Operation::MANUAL_REVIEW) {
                $summary['manual_review']++;
            } elseif ($result->status() === Operation::QUEUED) {
                $summary['retried']++;
            } else {
                $summary['processing']++;
            }
        }

        return $summary;
    }

    private function process(Operation $operation): Operation
    {
        if ($this->isCreditConsuming($operation) && $this->billingClientId($operation) === null) {
            $processing = $operation->status() === Operation::QUEUED
                ? $operation->start()
                : $operation->resumeVerification();

            return $processing->manualReview(
                ApiError::INTERNAL_ERROR,
                'The operation is missing its reseller billing scope.'
            );
        }

        $resources = $this->acquireResources($operation);
        if ($resources === null) {
            if ($operation->status() === Operation::PROCESSING) {
                return $operation->resumeVerification()->awaitVerification(
                    ApiError::OPERATION_PROCESSING,
                    'Another operation currently owns the required lock.',
                    $this->clock->now() + $this->backoff($operation->attemptCount())
                );
            }

            return $operation->retry(
                ApiError::OPERATION_PROCESSING,
                'Another operation currently owns the required lock.',
                $this->clock->now() + $this->backoff($operation->attemptCount())
            );
        }

        try {
            $mustVerify = $operation->status() === Operation::PROCESSING || $operation->requiresVerification();
            $processing = $operation->status() === Operation::QUEUED
                ? $operation->start()
                : $operation->resumeVerification();
            $this->operations->save($processing);

            try {
                return $mustVerify
                    ? $this->executor->verify($processing)
                    : $this->executor->execute($processing);
            } catch (OperationExecutionException $exception) {
                return $this->handleExecutionException($processing, $exception);
            } catch (Throwable) {
                return $processing->manualReview(
                    ApiError::INTERNAL_ERROR,
                    'The operation requires administrator review.'
                );
            }
        } finally {
            $this->releaseResources($resources, $operation->id());
        }
    }

    private function handleExecutionException(
        Operation $operation,
        OperationExecutionException $exception
    ): Operation {
        if ($operation->attemptCount() >= self::MAX_ATTEMPTS) {
            return $operation->manualReview(
                ApiError::MANUAL_REVIEW_REQUIRED,
                'The operation exhausted its automatic retry limit.'
            );
        }

        $nextAttemptAt = $this->clock->now() + $this->backoff($operation->attemptCount());
        if ($exception->isUnknownOutcome() || $operation->requiresVerification()) {
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

    private function backoff(int $attemptCount): int
    {
        $exponent = max(0, min(20, $attemptCount - 1));
        return min(self::MAX_BACKOFF_SECONDS, 30 * (2 ** $exponent));
    }

    private function acquireResources(Operation $operation): ?array
    {
        $resources = [];
        if ($operation->localServiceId() !== null) {
            $resources[] = 'service:' . $operation->apiKeyId() . ':' . $operation->localServiceId();
        }
        if ($this->isCreditConsuming($operation)) {
            $resources[] = 'billing:' . $this->billingClientId($operation);
        }

        $acquired = [];
        foreach ($resources as $resource) {
            if (!$this->locks->acquire(
                $resource,
                $operation->id(),
                $this->clock->now() + self::LOCK_SECONDS
            )) {
                $this->releaseResources(array_reverse($acquired), $operation->id());
                return null;
            }
            $acquired[] = $resource;
        }

        return array_reverse($acquired);
    }

    private function isCreditConsuming(Operation $operation): bool
    {
        return in_array($operation->action(), ['create', 'renew', 'change_package'], true);
    }

    private function billingClientId(Operation $operation): ?int
    {
        $value = $operation->sanitizedPayload()['_billing_client_id'] ?? null;
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            return null;
        }

        $clientId = (int) $value;
        return $clientId > 0 ? $clientId : null;
    }

    private function releaseResources(array $resources, string $owner): void
    {
        foreach ($resources as $resource) {
            $this->locks->release($resource, $owner);
        }
    }
}
