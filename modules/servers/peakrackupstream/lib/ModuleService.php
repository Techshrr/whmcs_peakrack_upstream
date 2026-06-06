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
use RuntimeException;
use Throwable;

final class ModuleService
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

    public function create(array $params): string
    {
        if ($this->properties->hasUpstreamBinding()) {
            return 'An upstream service is already bound to this WHMCS service.';
        }

        $payload = Mapper::createPayload($params);
        $key = $this->idempotency->create((int) $payload['local_service_id']);

        return $this->run(
            'create',
            $key,
            fn (): array => $this->api->createService($payload, $key),
            true
        );
    }

    public function suspend(array $params): string
    {
        return $this->lifecycle(
            $params,
            'suspend',
            fn (int $serviceId, string $key): array => $this->api->suspendService($serviceId, $key)
        );
    }

    public function unsuspend(array $params): string
    {
        return $this->lifecycle(
            $params,
            'unsuspend',
            fn (int $serviceId, string $key): array => $this->api->unsuspendService($serviceId, $key)
        );
    }

    public function terminate(array $params): string
    {
        $mode = Mapper::terminateMode($params);
        return $this->lifecycle(
            $params,
            'terminate.' . $mode,
            fn (int $serviceId, string $key): array => $this->api->terminateService($serviceId, $mode, $key)
        );
    }

    public function renew(array $params): string
    {
        if (!$this->properties->hasUpstreamBinding()) {
            return 'No upstream service is bound to this WHMCS service.';
        }

        $serviceId = Validator::positiveInt($params['serviceid'] ?? null, 'local service ID');
        $boundary = Validator::date((string) ($params['nextduedate'] ?? ''), 'renewal boundary');
        $key = $this->idempotency->renewal($serviceId, $boundary);

        return $this->run(
            'renew',
            $key,
            fn (): array => $this->api->renewService($serviceId, $boundary, $key)
        );
    }

    public function changePackage(array $params): string
    {
        if (!$this->properties->hasUpstreamBinding()) {
            return 'No upstream service is bound to this WHMCS service.';
        }

        $serviceId = Validator::positiveInt($params['serviceid'] ?? null, 'local service ID');
        $payload = Mapper::changePackagePayload($params);
        $key = $this->idempotency->changePackage($serviceId, $payload);

        return $this->run(
            'change_package',
            $key,
            fn (): array => $this->api->changePackage($serviceId, $payload, $key)
        );
    }

    public function customerData(string $localStatus, bool $ssoAvailable): array
    {
        return $this->properties->customerData($localStatus, $ssoAvailable);
    }

    private function lifecycle(array $params, string $keyAction, callable $call): string
    {
        if (!$this->properties->hasUpstreamBinding()) {
            return 'No upstream service is bound to this WHMCS service.';
        }

        $serviceId = Validator::positiveInt($params['serviceid'] ?? null, 'local service ID');
        $key = $this->idempotency->lifecycle($keyAction, $serviceId);

        return $this->run($keyAction, $key, fn (): array => $call($serviceId, $key));
    }

    private function run(string $action, string $key, callable $call, bool $requireCreateBinding = false): string
    {
        try {
            $response = $call();
            if (!is_array($response)) {
                throw new RuntimeException('The API client returned an invalid response.');
            }
            $this->properties->applyResponse($response);
            $this->properties->setLastSync((int) ($this->clock)());
            $this->properties->clearError();
            $status = (string) ($response['status'] ?? '');

            if ($status === 'completed') {
                if ($requireCreateBinding && !$this->properties->hasUpstreamBinding()) {
                    $this->properties->recordError('The completed create response did not contain an upstream service ID.');
                    return 'The upstream create response is incomplete and requires administrator review.';
                }

                $this->idempotency->clearIfMatches($key);
                return 'success';
            }
            if (in_array($status, ['queued', 'processing'], true)) {
                return 'The upstream ' . $action . ' operation is processing. WHMCS will synchronize it later.';
            }

            $this->properties->recordError('The upstream operation returned an invalid status.');
            return 'The upstream operation returned an invalid status.';
        } catch (ApiException $exception) {
            $this->recordApiException($exception);
            if (in_array($exception->operationStatus(), ['failed', 'manual_review'], true)) {
                $this->idempotency->clearIfMatches($key);
            }

            return 'Upstream API error ' . $exception->errorCode() . ': ' . $exception->getMessage();
        } catch (Throwable) {
            $this->properties->setLastSync((int) ($this->clock)());
            $this->properties->recordError('The upstream operation could not be completed.');
            return 'The upstream operation could not be completed.';
        }
    }

    private function recordApiException(ApiException $exception): void
    {
        $values = [];
        if ($exception->operationId() !== null) {
            $values[ServiceProperties::UPSTREAM_OPERATION_ID] = $exception->operationId();
        }
        if ($exception->operationStatus() !== null) {
            $values[ServiceProperties::UPSTREAM_STATUS] = $exception->operationStatus();
        }
        $this->properties->save($values);
        $this->properties->setLastSync((int) ($this->clock)());
        $this->properties->recordError(
            'Upstream API error ' . $exception->errorCode() . ': ' . $exception->getMessage()
        );
    }
}
