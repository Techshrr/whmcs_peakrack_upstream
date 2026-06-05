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

use PeakRack\UpstreamApi\Contracts\BillingGateway;
use PeakRack\UpstreamApi\Contracts\OperationExecutor;
use PeakRack\UpstreamApi\Contracts\OperationRepository;
use PeakRack\UpstreamApi\Contracts\ProvisioningGateway;
use PeakRack\UpstreamApi\Contracts\ServiceRepository;
use PeakRack\UpstreamApi\Domain\ApiError;
use PeakRack\UpstreamApi\Domain\Operation;
use PeakRack\UpstreamApi\Domain\ServiceRecord;
use PeakRack\UpstreamApi\Domain\ValidationException;
use Throwable;

final class CreateExecutor implements OperationExecutor
{
    public function __construct(
        private readonly BillingGateway $billing,
        private readonly ProvisioningGateway $provisioning,
        private readonly ServiceRepository $services,
        private readonly OperationRepository $operations
    ) {
    }

    public function execute(Operation $operation): Operation
    {
        $payload = $operation->executionPayload();
        $existing = $this->services->findByLocalId(
            $operation->apiKeyId(),
            (int) $operation->localServiceId()
        );
        if ($existing !== null) {
            if (
                $existing->productId() !== $this->positiveInt($payload, 'product_id')
                || $existing->billingCycle() !== (string) ($payload['billing_cycle'] ?? '')
            ) {
                return $operation->fail(
                    ApiError::SERVICE_STATE_CONFLICT,
                    'The local service ID is already bound to a different upstream service.'
                );
            }

            if (in_array($existing->status(), ['active', 'suspended', 'cancellation_pending'], true)) {
                return $operation->complete($existing->toApiArray());
            }

            return $operation->fail(
                ApiError::SERVICE_STATE_CONFLICT,
                'The local service ID is already bound but is not in a delivered state.'
            );
        }

        try {
            $billing = $this->billing->create(
                $this->positiveInt($payload, '_billing_client_id'),
                $this->positiveInt($payload, 'product_id'),
                (string) ($payload['billing_cycle'] ?? ''),
                (string) ($payload['payment_method'] ?? ''),
                [
                    'hostname' => $payload['hostname'] ?? null,
                    'password' => $payload['password'] ?? null,
                    'configoptions' => $payload['configoptions'] ?? [],
                    'customfields' => $payload['customfields'] ?? [],
                ],
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

        $operation = $operation->advance('provisioning_started', $billing);
        $this->operations->save($operation);
        $this->services->save($this->serviceRecord($operation, 'provisioning'));

        return $this->confirm($operation);
    }

    public function verify(Operation $operation): Operation
    {
        if (in_array($operation->stage(), ['compensation_pending', 'credit_restored'], true)) {
            return $operation->manualReview(
                ApiError::MANUAL_REVIEW_REQUIRED,
                'The compensation outcome requires administrator review.'
            );
        }

        return $this->confirm($operation);
    }

    private function confirm(Operation $operation): Operation
    {
        $serviceId = $this->positiveInt($operation->result(), 'service_id');
        try {
            $delivery = $this->provisioning->readDelivery(
                $serviceId,
                (array) ($operation->sanitizedPayload()['delivery_mappings'] ?? [])
            );
        } catch (Throwable) {
            throw OperationExecutionException::unknownOutcome(ApiError::OPERATION_PROCESSING);
        }
        $status = (string) ($delivery['service_status'] ?? 'unknown');
        $this->services->save($this->serviceRecord(
            $operation,
            $status,
            isset($delivery['primary_ip']) ? (string) $delivery['primary_ip'] : null,
            isset($delivery['panel_url']) ? (string) $delivery['panel_url'] : null
        ));

        if ($status === 'active') {
            return $operation->complete([
                'service_status' => $status,
                'primary_ip' => $delivery['primary_ip'] ?? null,
                'panel_url' => $delivery['panel_url'] ?? null,
            ]);
        }

        if ($status === 'failed') {
            return $this->compensate($operation);
        }

        throw OperationExecutionException::unknownOutcome(ApiError::OPERATION_PROCESSING);
    }

    private function compensate(Operation $operation): Operation
    {
        if ($operation->stage() === 'credit_restored') {
            return $operation->fail(ApiError::PROVISIONING_FAILED, 'Provisioning failed before delivery.');
        }
        if ($operation->stage() === 'compensation_pending') {
            return $operation->manualReview(
                ApiError::MANUAL_REVIEW_REQUIRED,
                'Compensation outcome is unknown and requires administrator review.'
            );
        }

        $pending = $operation->advance('compensation_pending');
        $this->operations->save($pending);

        try {
            $this->billing->compensateCreate(
                $this->positiveInt($pending->sanitizedPayload(), '_billing_client_id'),
                (string) ($pending->result()['applied_credit_amount'] ?? ''),
                $this->positiveInt($pending->result(), 'order_id'),
                $this->positiveInt($pending->result(), 'invoice_id'),
                $pending->id()
            );
        } catch (Throwable) {
            return $pending->manualReview(
                ApiError::MANUAL_REVIEW_REQUIRED,
                'Confirmed provisioning failure compensation requires administrator review.'
            );
        }

        $restored = $pending->advance('credit_restored');
        $this->operations->save($restored);
        return $restored->fail(ApiError::PROVISIONING_FAILED, 'Provisioning failed before delivery.');
    }

    private function serviceRecord(
        Operation $operation,
        string $status,
        ?string $primaryIp = null,
        ?string $panelUrl = null
    ): ServiceRecord {
        $payload = $operation->sanitizedPayload();
        $result = $operation->result();

        return new ServiceRecord(
            $operation->apiKeyId(),
            (int) $operation->localServiceId(),
            $this->positiveInt($result, 'service_id'),
            $this->positiveInt($result, 'order_id'),
            $this->positiveInt($result, 'invoice_id'),
            $this->positiveInt($payload, 'product_id'),
            (string) ($payload['billing_cycle'] ?? ''),
            $status,
            $primaryIp,
            $panelUrl
        );
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
}
