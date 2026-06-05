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
use InvalidArgumentException;
use PeakRack\UpstreamApi\Contracts\OperationRepository;
use PeakRack\UpstreamApi\Contracts\PolicyRepository;
use PeakRack\UpstreamApi\Contracts\ProvisioningGateway;
use PeakRack\UpstreamApi\Contracts\ServiceRepository;
use PeakRack\UpstreamApi\Domain\ApiError;
use PeakRack\UpstreamApi\Domain\Operation;
use PeakRack\UpstreamApi\Domain\ProductPolicy;
use PeakRack\UpstreamApi\Domain\ServiceRecord;
use PeakRack\UpstreamApi\Domain\ValidationException;
use PeakRack\UpstreamApi\Http\Request;
use PeakRack\UpstreamApi\Http\Response;
use PeakRack\UpstreamApi\Http\Router;
use Throwable;

final class ApiKernel
{
    private readonly Closure $authenticate;
    private readonly Closure $admitOperation;

    public function __construct(
        callable $authenticate,
        private readonly Router $router,
        callable $admitOperation,
        private readonly ServiceRepository $services,
        private readonly OperationRepository $operations,
        private readonly PolicyRepository $policies,
        private readonly CatalogService $catalog,
        private readonly HealthService $health,
        private readonly ProvisioningGateway $provisioning,
        private readonly string $paymentMethod
    ) {
        $this->authenticate = Closure::fromCallable($authenticate);
        $this->admitOperation = Closure::fromCallable($admitOperation);
    }

    public function handle(Request $request): Response
    {
        try {
            if (!$request->isSecure()) {
                throw new ValidationException(
                    ApiError::AUTHENTICATION_FAILED,
                    'HTTPS is required.',
                    403
                );
            }

            $apiKey = ($this->authenticate)($request);
            if (!is_array($apiKey)) {
                throw new ValidationException(ApiError::AUTHENTICATION_FAILED, 'Authentication failed.', 401);
            }

            $route = $this->router->match($request);
            if ($route === null) {
                throw new ValidationException(ApiError::SERVICE_NOT_FOUND, 'The requested resource was not found.', 404);
            }

            return $this->dispatch($request, $route, $apiKey);
        } catch (ValidationException $exception) {
            return Response::error(
                $exception->apiErrorCode(),
                $exception->getMessage(),
                $exception->httpStatus()
            );
        } catch (InvalidArgumentException) {
            return Response::error(
                ApiError::INVALID_PRODUCT_MAPPING,
                'The request contains invalid or unsupported input.',
                400
            );
        } catch (Throwable) {
            return Response::error(ApiError::INTERNAL_ERROR, 'An internal error occurred.', 500);
        }
    }

    private function dispatch(Request $request, array $route, array $apiKey): Response
    {
        $name = (string) $route['name'];
        $parameters = (array) $route['parameters'];
        $apiKeyId = $this->positiveInt($apiKey, 'id');

        if ($name === 'health') {
            return Response::success($this->health->forApiKey($apiKey));
        }
        if ($name === 'catalog') {
            return Response::success($this->catalog->forApiKey($apiKeyId));
        }
        if ($name === 'service.create') {
            return $this->create($request, $apiKey);
        }
        if ($name === 'operation.show') {
            return $this->operation((string) $parameters['operation_id'], $apiKeyId);
        }

        $service = $this->ownedService($apiKeyId, (int) $parameters['local_service_id']);
        if ($name === 'service.show') {
            return Response::success($service->toApiArray());
        }
        if ($name === 'service.sso') {
            return $this->sso($request, $apiKeyId, $service);
        }

        return $this->serviceWrite($request, $apiKey, $service, $name);
    }

    private function create(Request $request, array $apiKey): Response
    {
        $body = $request->json(
            ['local_service_id', 'product_id', 'billing_cycle', 'hostname', 'password', 'location', 'os_template'],
            ['local_service_id', 'product_id', 'billing_cycle', 'hostname', 'password']
        );
        $apiKeyId = $this->positiveInt($apiKey, 'id');
        $productId = $this->positiveInt($body, 'product_id');
        $localServiceId = $this->positiveInt($body, 'local_service_id');
        $cycle = $this->nonEmptyString($body, 'billing_cycle');
        $hostname = $this->hostname($this->nonEmptyString($body, 'hostname'));
        $password = $this->nonEmptyString($body, 'password');
        $location = isset($body['location']) ? $this->nonEmptyString($body, 'location') : null;
        $osTemplate = isset($body['os_template']) ? $this->nonEmptyString($body, 'os_template') : null;
        $policy = $this->policy($apiKeyId, $productId);
        $policy->assertAllows('create', $productId, $cycle, $location, $osTemplate, false);
        [$configOptions, $customFields] = $this->mappedFields($policy, $location, $osTemplate);

        $operation = ($this->admitOperation)(
            $apiKeyId,
            $localServiceId,
            'create',
            (string) $request->header('idempotency-key'),
            [
                'local_service_id' => $localServiceId,
                'product_id' => $productId,
                'billing_cycle' => $cycle,
                'hostname' => $hostname,
                'password' => $password,
                'configoptions' => $configOptions,
                'customfields' => $customFields,
                'delivery_mappings' => $policy->deliveryMappings(),
                'payment_method' => $this->paymentMethod,
            ],
            true,
            $this->positiveInt($apiKey, 'client_id')
        );

        return $this->operationResponse($operation);
    }

    private function serviceWrite(
        Request $request,
        array $apiKey,
        ServiceRecord $service,
        string $routeName
    ): Response {
        $action = match ($routeName) {
            'service.suspend' => 'suspend',
            'service.unsuspend' => 'unsuspend',
            'service.terminate' => 'terminate',
            'service.renew' => 'renew',
            'service.change_package' => 'change_package',
            default => throw new ValidationException(ApiError::SERVICE_NOT_FOUND, 'The requested resource was not found.', 404),
        };
        $allowed = match ($action) {
            'suspend' => ['reason'],
            'terminate' => ['mode'],
            'renew' => ['renewal_boundary'],
            'change_package' => ['product_id', 'billing_cycle', 'location', 'os_template'],
            default => [],
        };
        $required = match ($action) {
            'renew' => ['renewal_boundary'],
            'change_package' => ['product_id', 'billing_cycle'],
            default => [],
        };
        $body = $request->json($allowed, $required);
        $targetProductId = $action === 'change_package'
            ? $this->positiveInt($body, 'product_id')
            : $service->productId();
        $targetCycle = $action === 'change_package'
            ? $this->nonEmptyString($body, 'billing_cycle')
            : $service->billingCycle();
        $location = isset($body['location']) ? $this->nonEmptyString($body, 'location') : null;
        $osTemplate = isset($body['os_template']) ? $this->nonEmptyString($body, 'os_template') : null;
        $destroy = $action === 'terminate' && ($body['mode'] ?? 'cancel_only') === 'destroy';
        if ($action === 'terminate' && !in_array($body['mode'] ?? 'cancel_only', ['cancel_only', 'destroy'], true)) {
            throw new InvalidArgumentException('Invalid termination mode.');
        }
        if ($action === 'renew' && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $body['renewal_boundary']) !== 1) {
            throw new InvalidArgumentException('Invalid renewal boundary.');
        }

        $apiKeyId = $this->positiveInt($apiKey, 'id');
        $policy = $this->policy($apiKeyId, $targetProductId);
        $policy->assertAllows($action, $targetProductId, $targetCycle, $location, $osTemplate, $destroy);
        if ($service->upstreamServiceId() === null) {
            throw new ValidationException(
                ApiError::SERVICE_STATE_CONFLICT,
                'The service does not yet have an upstream service ID.',
                409
            );
        }
        [$configOptions] = $this->mappedFields($policy, $location, $osTemplate);
        $payload = [
            'local_service_id' => $service->localServiceId(),
            'upstream_service_id' => $service->upstreamServiceId(),
            'product_id' => $targetProductId,
            'billing_cycle' => $targetCycle,
            'payment_method' => $this->paymentMethod,
            'delivery_mappings' => $policy->deliveryMappings(),
            'configoptions' => $configOptions,
        ] + $body;
        $creditConsuming = in_array($action, ['renew', 'change_package'], true);

        $operation = ($this->admitOperation)(
            $apiKeyId,
            $service->localServiceId(),
            $action,
            (string) $request->header('idempotency-key'),
            $payload,
            $creditConsuming,
            $creditConsuming ? $this->positiveInt($apiKey, 'client_id') : null
        );

        return $this->operationResponse($operation);
    }

    private function sso(Request $request, int $apiKeyId, ServiceRecord $service): Response
    {
        $request->json([], []);
        $policy = $this->policy($apiKeyId, $service->productId());
        $policy->assertAllows('sso', $service->productId(), $service->billingCycle(), null, null, false);
        if (!$policy->ssoAllowed() || $service->upstreamServiceId() === null) {
            throw new ValidationException(ApiError::PRODUCT_NOT_ALLOWED, 'SSO is not allowed.', 403);
        }

        $url = $this->provisioning->sso(
            $service->upstreamServiceId(),
            $policy->ssoHosts(),
            (string) $request->header('idempotency-key')
        );

        return Response::success(['sso_url' => $url]);
    }

    private function operation(string $operationId, int $apiKeyId): Response
    {
        $operation = $this->operations->findById($operationId);
        if ($operation === null || $operation->apiKeyId() !== $apiKeyId) {
            throw new ValidationException(ApiError::SERVICE_NOT_FOUND, 'The operation was not found.', 404);
        }

        return Response::success([
            'action' => $operation->action(),
            'operation_status' => $operation->status(),
            'result' => $operation->result(),
            'error_code' => $operation->errorCode(),
            'error_message' => $operation->errorMessage(),
        ], $operation->status(), $operation->id());
    }

    private function operationResponse(Operation $operation): Response
    {
        if ($operation->status() === Operation::COMPLETED) {
            return Response::success($operation->result(), $operation->status(), $operation->id(), 200);
        }
        if (in_array($operation->status(), [Operation::QUEUED, Operation::PROCESSING], true)) {
            return Response::success($operation->result(), $operation->status(), $operation->id(), 202);
        }

        return Response::error(
            $operation->errorCode() ?? ApiError::INTERNAL_ERROR,
            $operation->errorMessage() ?? 'The operation failed.',
            422,
            $operation->id(),
            $operation->status()
        );
    }

    private function ownedService(int $apiKeyId, int $localServiceId): ServiceRecord
    {
        $service = $this->services->findByLocalId($apiKeyId, $localServiceId);
        if ($service === null) {
            throw new ValidationException(ApiError::SERVICE_NOT_FOUND, 'The service was not found.', 404);
        }

        return $service;
    }

    private function policy(int $apiKeyId, int $productId): ProductPolicy
    {
        $policy = $this->policies->findForProduct($apiKeyId, $productId);
        if ($policy === null) {
            throw new ValidationException(ApiError::PRODUCT_NOT_ALLOWED, 'Product is not allowed.', 403);
        }

        return $policy;
    }

    private function mappedFields(ProductPolicy $policy, ?string $location, ?string $osTemplate): array
    {
        $configOptions = [];
        $customFields = [];

        foreach ([$policy->locationMapping($location), $policy->osTemplateMapping($osTemplate)] as $mapping) {
            if ($mapping === null) {
                continue;
            }
            if (!is_array($mapping) || !isset($mapping['type'], $mapping['id'], $mapping['value'])) {
                throw new ValidationException(ApiError::INVALID_PRODUCT_MAPPING, 'Product mapping is invalid.', 422);
            }

            $id = (int) $mapping['id'];
            if ($id < 1 || !in_array($mapping['type'], ['configoption', 'customfield'], true)) {
                throw new ValidationException(ApiError::INVALID_PRODUCT_MAPPING, 'Product mapping is invalid.', 422);
            }

            if ($mapping['type'] === 'configoption') {
                $configOptions[$id] = $mapping['value'];
            } else {
                $customFields[$id] = $mapping['value'];
            }
        }

        return [$configOptions, $customFields];
    }

    private function positiveInt(array $source, string $key): int
    {
        $value = $source[$key] ?? null;
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new InvalidArgumentException("Invalid positive integer {$key}.");
        }

        $integer = (int) $value;
        if ($integer < 1) {
            throw new InvalidArgumentException("Invalid positive integer {$key}.");
        }

        return $integer;
    }

    private function nonEmptyString(array $source, string $key): string
    {
        $value = $source[$key] ?? null;
        if (!is_string($value) || $value === '' || str_contains($value, "\0")) {
            throw new InvalidArgumentException("Invalid string {$key}.");
        }

        return $value;
    }

    private function hostname(string $hostname): string
    {
        if (
            strlen($hostname) > 253
            || filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
        ) {
            throw new InvalidArgumentException('Invalid hostname.');
        }

        return strtolower($hostname);
    }
}
