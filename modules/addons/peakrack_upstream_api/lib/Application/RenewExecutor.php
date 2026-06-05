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
use PeakRack\UpstreamApi\Domain\ApiError;
use PeakRack\UpstreamApi\Domain\Operation;
use PeakRack\UpstreamApi\Domain\ValidationException;
use Throwable;

final class RenewExecutor implements OperationExecutor
{
    private readonly Closure $stateReader;
    private readonly ?Closure $invoiceFinder;

    public function __construct(
        private readonly BillingGateway $billing,
        private readonly OperationRepository $operations,
        callable $stateReader,
        ?callable $invoiceFinder = null
    ) {
        $this->stateReader = Closure::fromCallable($stateReader);
        $this->invoiceFinder = $invoiceFinder === null ? null : Closure::fromCallable($invoiceFinder);
    }

    public function execute(Operation $operation): Operation
    {
        $payload = $operation->sanitizedPayload();
        $boundary = (string) ($payload['renewal_boundary'] ?? '');
        $currentDueDate = $this->nextDueDate($operation);
        if ($currentDueDate > $boundary) {
            return $operation->complete(['next_due_date' => $currentDueDate]);
        }
        if ($currentDueDate !== $boundary) {
            return $operation->fail(
                ApiError::SERVICE_STATE_CONFLICT,
                'The renewal boundary does not match the current upstream due date.'
            );
        }

        $existingInvoiceId = $this->nullablePositiveInt($payload['existing_invoice_id'] ?? null);

        if ($existingInvoiceId === null && $this->invoiceFinder !== null) {
            try {
                $existingInvoiceId = $this->nullablePositiveInt(($this->invoiceFinder)(
                    $this->positiveInt($payload, '_billing_client_id'),
                    $this->positiveInt($payload, 'upstream_service_id'),
                    (string) ($payload['renewal_boundary'] ?? ''),
                    $operation->id()
                ));
            } catch (Throwable) {
                throw OperationExecutionException::unknownOutcome(ApiError::INTERNAL_ERROR);
            }
        }

        try {
            $billing = $this->billing->renew(
                $this->positiveInt($payload, '_billing_client_id'),
                $this->positiveInt($payload, 'upstream_service_id'),
                (string) ($payload['payment_method'] ?? ''),
                $existingInvoiceId,
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

        $operation = $operation->advance('credit_applied', $billing);
        $this->operations->save($operation);
        return $this->verify($operation);
    }

    public function verify(Operation $operation): Operation
    {
        $boundary = (string) ($operation->sanitizedPayload()['renewal_boundary'] ?? '');
        $nextDueDate = $this->nextDueDate($operation);
        if ($nextDueDate > $boundary) {
            return $operation->complete(['next_due_date' => $nextDueDate]);
        }

        throw OperationExecutionException::unknownOutcome(ApiError::OPERATION_PROCESSING);
    }

    private function nextDueDate(Operation $operation): string
    {
        try {
            $state = ($this->stateReader)($this->positiveInt(
                $operation->sanitizedPayload(),
                'upstream_service_id'
            ));
        } catch (Throwable) {
            throw OperationExecutionException::unknownOutcome(ApiError::OPERATION_PROCESSING);
        }

        $nextDueDate = is_array($state) ? (string) ($state['next_due_date'] ?? '') : '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $nextDueDate) !== 1) {
            throw OperationExecutionException::unknownOutcome(ApiError::OPERATION_PROCESSING);
        }

        return $nextDueDate;
    }

    private function positiveInt(array $source, string $key): int
    {
        $value = $this->nullablePositiveInt($source[$key] ?? null);
        if ($value === null) {
            throw new \RuntimeException("Missing positive integer {$key}.");
        }

        return $value;
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            return null;
        }

        $integer = (int) $value;
        return $integer > 0 ? $integer : null;
    }
}
