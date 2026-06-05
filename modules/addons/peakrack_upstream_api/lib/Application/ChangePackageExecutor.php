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

use Closure;
use PeakRack\UpstreamApi\Contracts\BillingGateway;
use PeakRack\UpstreamApi\Contracts\OperationExecutor;
use PeakRack\UpstreamApi\Contracts\OperationRepository;
use PeakRack\UpstreamApi\Contracts\ServiceRepository;
use PeakRack\UpstreamApi\Domain\ApiError;
use PeakRack\UpstreamApi\Domain\Operation;
use PeakRack\UpstreamApi\Domain\ValidationException;
use Throwable;

final class ChangePackageExecutor implements OperationExecutor
{
    private readonly Closure $stateReader;

    public function __construct(
        private readonly BillingGateway $billing,
        private readonly OperationRepository $operations,
        private readonly ServiceRepository $services,
        callable $stateReader
    ) {
        $this->stateReader = Closure::fromCallable($stateReader);
    }

    public function execute(Operation $operation): Operation
    {
        $payload = $operation->sanitizedPayload();
        $state = $this->readState($operation);
        if ($this->targetReached($operation, $state)) {
            return $this->completeFromState($operation, $state);
        }

        try {
            $billing = $this->billing->changePackage(
                $this->positiveInt($payload, '_billing_client_id'),
                $this->positiveInt($payload, 'upstream_service_id'),
                $this->positiveInt($payload, 'product_id'),
                (string) ($payload['billing_cycle'] ?? ''),
                (string) ($payload['payment_method'] ?? ''),
                (array) ($payload['configoptions'] ?? []),
                $operation->id()
            );
        } catch (ValidationException $exception) {
            if ($exception->apiErrorCode() === ApiError::INSUFFICIENT_CREDIT) {
                return $operation->fail($exception->apiErrorCode(), $exception->getMessage());
            }

            throw OperationExecutionException::unknownOutcome($exception->apiErrorCode());
        } catch (Throwable) {
            throw OperationExecutionException::unknownOutcome(ApiError::INTERNAL_ERROR);
        }

        if (($billing['is_downgrade'] ?? false) === true && !$this->isZero((string) ($billing['applied_credit_amount'] ?? ''))) {
            return $operation->manualReview(
                ApiError::MANUAL_REVIEW_REQUIRED,
                'A downgrade produced an unexpected Credit effect.'
            );
        }

        $operation = $operation->advance('package_change_submitted', $billing);
        $this->operations->save($operation);
        return $this->verify($operation);
    }

    public function verify(Operation $operation): Operation
    {
        $state = $this->readState($operation);
        if ($this->targetReached($operation, $state)) {
            return $this->completeFromState($operation, $state);
        }

        throw OperationExecutionException::unknownOutcome(ApiError::OPERATION_PROCESSING);
    }

    private function readState(Operation $operation): array
    {
        try {
            $state = ($this->stateReader)($this->positiveInt(
                $operation->sanitizedPayload(),
                'upstream_service_id'
            ));
        } catch (Throwable) {
            throw OperationExecutionException::unknownOutcome(ApiError::OPERATION_PROCESSING);
        }

        if (!is_array($state)) {
            throw OperationExecutionException::unknownOutcome(ApiError::OPERATION_PROCESSING);
        }

        return $state;
    }

    private function targetReached(Operation $operation, array $state): bool
    {
        $payload = $operation->sanitizedPayload();
        return (int) ($state['product_id'] ?? 0) === $this->positiveInt($payload, 'product_id')
            && (string) ($state['billing_cycle'] ?? '') === (string) ($payload['billing_cycle'] ?? '');
    }

    private function completeFromState(Operation $operation, array $state): Operation
    {
        $payload = $operation->sanitizedPayload();
        $service = $this->services->findByLocalId(
            $operation->apiKeyId(),
            $this->positiveInt($payload, 'local_service_id')
        );
        if ($service === null) {
            return $operation->manualReview(
                ApiError::MANUAL_REVIEW_REQUIRED,
                'The managed service record is missing after package change.'
            );
        }
        $this->services->save($service->withPackage(
            (int) $state['product_id'],
            (string) $state['billing_cycle']
        ));

        return $operation->complete([
            'product_id' => (int) $state['product_id'],
            'billing_cycle' => (string) $state['billing_cycle'],
        ]);
    }

    private function positiveInt(array $source, string $key): int
    {
        $value = $source[$key] ?? null;
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new \RuntimeException("Missing positive integer {$key}.");
        }

        $integer = (int) $value;
        if ($integer < 1) {
            throw new \RuntimeException("Missing positive integer {$key}.");
        }

        return $integer;
    }

    private function isZero(string $amount): bool
    {
        return preg_match('/^0+(?:\.0+)?$/', $amount) === 1;
    }
}
