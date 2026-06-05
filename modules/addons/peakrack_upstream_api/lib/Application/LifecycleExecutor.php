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
use PeakRack\UpstreamApi\Contracts\OperationExecutor;
use PeakRack\UpstreamApi\Contracts\OperationRepository;
use PeakRack\UpstreamApi\Contracts\ProvisioningGateway;
use PeakRack\UpstreamApi\Contracts\ServiceRepository;
use PeakRack\UpstreamApi\Domain\ApiError;
use PeakRack\UpstreamApi\Domain\Operation;
use Throwable;

final class LifecycleExecutor implements OperationExecutor
{
    private readonly Closure $cancelRequest;

    public function __construct(
        private readonly ProvisioningGateway $provisioning,
        private readonly ServiceRepository $services,
        private readonly OperationRepository $operations,
        callable $cancelRequest
    ) {
        $this->cancelRequest = Closure::fromCallable($cancelRequest);
    }

    public function execute(Operation $operation): Operation
    {
        $payload = $operation->sanitizedPayload();
        $serviceId = $this->positiveInt($payload, 'upstream_service_id');

        try {
            if ($operation->action() === 'suspend') {
                $completed = $this->completeIfTarget($operation, 'suspended');
                if ($completed !== null) {
                    return $completed;
                }
                $this->provisioning->suspend($serviceId, (string) ($payload['reason'] ?? ''), $operation->id());
                return $this->confirm($operation, 'suspended');
            }
            if ($operation->action() === 'unsuspend') {
                $completed = $this->completeIfTarget($operation, 'active');
                if ($completed !== null) {
                    return $completed;
                }
                $this->provisioning->unsuspend($serviceId, $operation->id());
                return $this->confirm($operation, 'active');
            }
            if ($operation->action() === 'terminate_due') {
                $completed = $this->completeIfTarget($operation, 'terminated');
                if ($completed !== null) {
                    return $completed;
                }
                $this->provisioning->terminate($serviceId, $operation->id());
                return $this->confirm($operation, 'terminated');
            }
            if ($operation->action() === 'terminate' && ($payload['mode'] ?? 'cancel_only') === 'destroy') {
                $completed = $this->completeIfTarget($operation, 'terminated');
                if ($completed !== null) {
                    return $completed;
                }
                $this->provisioning->terminate($serviceId, $operation->id());
                return $this->confirm($operation, 'terminated');
            }
            if ($operation->action() === 'terminate') {
                $operation = $operation->advance('cancellation_request_pending');
                $this->operations->save($operation);
                $response = ($this->cancelRequest)($serviceId, $operation->id());
                $dueAt = is_array($response) ? (int) ($response['due_at'] ?? 0) : 0;
                if ($dueAt < 1) {
                    throw new \RuntimeException('Cancellation did not return a due time.');
                }

                $this->saveServiceStatus($operation, 'cancellation_pending');
                return $operation->defer('cancellation_pending', $dueAt, [
                    'service_status' => 'cancellation_pending',
                ]);
            }
        } catch (Throwable) {
            throw OperationExecutionException::unknownOutcome(ApiError::PROVISIONING_FAILED);
        }

        throw new \RuntimeException('Unsupported lifecycle action.');
    }

    public function verify(Operation $operation): Operation
    {
        $payload = $operation->sanitizedPayload();
        if (
            $operation->action() === 'terminate'
            && ($payload['mode'] ?? 'cancel_only') === 'cancel_only'
        ) {
            if ($operation->stage() !== 'cancellation_pending') {
                return $operation->manualReview(
                    ApiError::MANUAL_REVIEW_REQUIRED,
                    'The cancellation request outcome is unknown and requires administrator review.'
                );
            }

            try {
                $this->provisioning->terminate(
                    $this->positiveInt($payload, 'upstream_service_id'),
                    $operation->id()
                );
            } catch (Throwable) {
                throw OperationExecutionException::unknownOutcome(ApiError::PROVISIONING_FAILED);
            }
        }

        $target = match ($operation->action()) {
            'suspend' => 'suspended',
            'unsuspend' => 'active',
            'terminate', 'terminate_due' => 'terminated',
            default => 'unknown',
        };

        return $this->confirm($operation, $target);
    }

    private function confirm(Operation $operation, string $target): Operation
    {
        $completed = $this->completeIfTarget($operation, $target);
        if ($completed !== null) {
            return $completed;
        }

        throw OperationExecutionException::unknownOutcome(ApiError::OPERATION_PROCESSING);
    }

    private function completeIfTarget(Operation $operation, string $target): ?Operation
    {
        $payload = $operation->sanitizedPayload();
        try {
            $delivery = $this->provisioning->readDelivery(
                $this->positiveInt($payload, 'upstream_service_id'),
                (array) ($payload['delivery_mappings'] ?? [])
            );
        } catch (Throwable) {
            throw OperationExecutionException::unknownOutcome(ApiError::OPERATION_PROCESSING);
        }
        $status = (string) ($delivery['service_status'] ?? 'unknown');
        $this->saveServiceStatus(
            $operation,
            $status,
            isset($delivery['primary_ip']) ? (string) $delivery['primary_ip'] : null,
            isset($delivery['panel_url']) ? (string) $delivery['panel_url'] : null
        );

        if ($status === $target) {
            return $operation->complete([
                'service_status' => $status,
                'primary_ip' => $delivery['primary_ip'] ?? null,
                'panel_url' => $delivery['panel_url'] ?? null,
            ]);
        }

        return null;
    }

    private function saveServiceStatus(
        Operation $operation,
        string $status,
        ?string $primaryIp = null,
        ?string $panelUrl = null
    ): void {
        $service = $this->services->findByLocalId($operation->apiKeyId(), (int) $operation->localServiceId());
        if ($service === null) {
            throw new \RuntimeException('Managed service not found.');
        }

        $this->services->save($service->withStatus($status, $primaryIp, $panelUrl));
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
