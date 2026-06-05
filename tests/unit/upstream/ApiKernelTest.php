<?php

namespace PeakRack\Tests\Unit\Upstream;

use PeakRack\Tests\TestCase;
use PeakRack\UpstreamApi\Application\ApiKernel;
use PeakRack\UpstreamApi\Application\CatalogService;
use PeakRack\UpstreamApi\Application\HealthService;
use PeakRack\UpstreamApi\Contracts\OperationRepository;
use PeakRack\UpstreamApi\Contracts\PolicyRepository;
use PeakRack\UpstreamApi\Contracts\ProvisioningGateway;
use PeakRack\UpstreamApi\Contracts\ServiceRepository;
use PeakRack\UpstreamApi\Domain\Operation;
use PeakRack\UpstreamApi\Domain\ProductPolicy;
use PeakRack\UpstreamApi\Domain\ServiceRecord;
use PeakRack\UpstreamApi\Http\Request;
use PeakRack\UpstreamApi\Http\Router;
use RuntimeException;

final class ApiKernelTest extends TestCase
{
    public function testAuthenticatesBeforeBusinessLookupAndScopesOwnership(): void
    {
        [$kernel, $state] = $this->kernel();

        $response = $kernel->handle($this->request('GET', '/services/123'));

        $this->assertSame(200, $response->statusCode());
        $this->assertSame(['authenticate', 'service:7:123'], array_slice($state->trace, 0, 2));

        $state->apiKeyId = 8;
        $notOwned = $kernel->handle($this->request('GET', '/services/123'));
        $this->assertSame(404, $notOwned->statusCode());
    }

    public function testCatalogAndHealthAreAuthenticatedAndKeyScoped(): void
    {
        [$kernel, $state] = $this->kernel();

        $catalog = $kernel->handle($this->request('GET', '/catalog'))->payload();
        $health = $kernel->handle($this->request('GET', '/health'))->payload();

        $this->assertSame(7, $catalog['data']['api_key_id']);
        $this->assertSame('v1', $health['data']['protocol']);
        $this->assertContains('automatic_credit_use_enabled', $health['data']['blocking_errors']);
        $this->assertSame(2, count(array_filter(
            $state->trace,
            static fn (string $item): bool => $item === 'authenticate'
        )));
    }

    public function testExercisesApprovedRoutesAndNeverPersistsSsoUrl(): void
    {
        [$kernel, $state] = $this->kernel();
        $operationId = '123e4567-e89b-42d3-a456-426614174000';
        $routes = [
            $this->request('GET', '/health'),
            $this->request('GET', '/catalog'),
            $this->request('POST', '/services', [
                'local_service_id' => 123,
                'product_id' => 10,
                'billing_cycle' => 'monthly',
                'hostname' => 'server.example.test',
                'password' => 'temporary-secret',
            ]),
            $this->request('GET', '/services/123'),
            $this->request('POST', '/services/123/suspend', ['reason' => 'billing']),
            $this->request('POST', '/services/123/unsuspend', []),
            $this->request('POST', '/services/123/terminate', ['mode' => 'cancel_only']),
            $this->request('POST', '/services/123/renew', ['renewal_boundary' => '2026-07-01']),
            $this->request('POST', '/services/123/change-package', [
                'product_id' => 10,
                'billing_cycle' => 'monthly',
            ]),
            $this->request('POST', '/services/123/sso', []),
            $this->request('GET', '/operations/' . $operationId),
        ];

        foreach ($routes as $request) {
            $response = $kernel->handle($request);
            $this->assertTrue($response->statusCode() < 500, $request->path());
        }

        foreach (['create', 'suspend', 'unsuspend', 'terminate', 'renew', 'change_package'] as $action) {
            $this->assertContains($action, $state->admittedActions);
        }
        $this->assertStringNotContains(
            'https://panel.example.test/sso/secret',
            json_encode($state->operationEvents)
        );
    }

    public function testInvalidInputReturnsStableSanitizedError(): void
    {
        [$kernel] = $this->kernel();
        $response = $kernel->handle($this->request('POST', '/services', [
            'local_service_id' => 123,
            'product_id' => 10,
            'billing_cycle' => 'monthly',
            'hostname' => 'server.example.test',
            'password' => 'temporary-secret',
            'client_id' => 999,
        ]));

        $this->assertSame(400, $response->statusCode());
        $this->assertSame('INVALID_PRODUCT_MAPPING', $response->payload()['error']['code']);
        $this->assertStringNotContains('999', $response->body());
    }

    public function testRejectsInsecureRequestsBeforeAuthentication(): void
    {
        [$kernel, $state] = $this->kernel();
        $request = new Request('GET', '/health', [], [], '', '192.0.2.10', false);

        $response = $kernel->handle($request);

        $this->assertSame(403, $response->statusCode());
        $this->assertSame('AUTHENTICATION_FAILED', $response->payload()['error']['code']);
        $this->assertSame([], $state->trace);
    }

    private function kernel(): array
    {
        $state = new ApiKernelState();
        $policies = new ApiKernelPolicies($state);
        $services = new ApiKernelServices($state);
        $operations = new ApiKernelOperations($state);
        $provisioning = new ApiKernelProvisioning();
        $authenticate = static function () use ($state): array {
            $state->trace[] = 'authenticate';
            return [
                'id' => $state->apiKeyId,
                'client_id' => 44,
                'instance_id' => '123e4567-e89b-42d3-a456-426614174000',
                'enabled' => 1,
            ];
        };
        $admit = static function (
            int $apiKeyId,
            ?int $localServiceId,
            string $action,
            string $idempotencyKey,
            array $payload,
            bool $creditConsuming,
            ?int $billingClientId
        ) use ($state): Operation {
            $state->admittedActions[] = $action;
            return Operation::admit(
                'operation-' . $action,
                $apiKeyId,
                $action,
                $idempotencyKey,
                hash('sha256', json_encode($payload)),
                $localServiceId,
                $payload
            )->start()->complete(['action' => $action]);
        };

        $kernel = new ApiKernel(
            $authenticate,
            new Router(),
            $admit,
            $services,
            $operations,
            $policies,
            new CatalogService($policies),
            new HealthService(static fn (): array => [
                'automatic_credit_use' => true,
                'credit_on_downgrade' => false,
                'worker_last_run' => 1780617600,
            ]),
            $provisioning,
            'mailin'
        );

        return [$kernel, $state];
    }

    private function request(string $method, string $path, ?array $body = null): Request
    {
        $headers = [];
        $rawBody = '';
        if ($body !== null) {
            $headers = [
                'Content-Type' => 'application/json',
                'Idempotency-Key' => 'test:' . hash('sha256', $method . $path),
            ];
            $rawBody = (string) json_encode((object) $body);
        }

        return new Request($method, $path, [], $headers, $rawBody, '192.0.2.10');
    }
}

final class ApiKernelState
{
    public int $apiKeyId = 7;
    public array $trace = [];
    public array $admittedActions = [];
    public array $operationEvents = [];
}

final class ApiKernelPolicies implements PolicyRepository
{
    public function __construct(private readonly ApiKernelState $state)
    {
    }

    public function findForProduct(int $apiKeyId, int $productId): ?ProductPolicy
    {
        $this->state->trace[] = "policy:{$apiKeyId}:{$productId}";
        if ($apiKeyId !== 7 || $productId !== 10) {
            return null;
        }

        return new ProductPolicy(
            productId: 10,
            billingCycles: ['monthly'],
            actions: ['create', 'suspend', 'unsuspend', 'terminate', 'renew', 'change_package', 'sso'],
            destroyAllowed: true,
            deliveryMappings: [],
            ssoAllowed: true,
            ssoHosts: ['panel.example.test']
        );
    }

    public function catalogForApiKey(int $apiKeyId): array
    {
        return [['api_key_id' => $apiKeyId, 'product_id' => 10]];
    }

    public function save(array $attributes): int
    {
        return 1;
    }

    public function delete(int $id): void
    {
    }
}

final class ApiKernelServices implements ServiceRepository
{
    public function __construct(private readonly ApiKernelState $state)
    {
    }

    public function findByLocalId(int $apiKeyId, int $localServiceId): ?ServiceRecord
    {
        $this->state->trace[] = "service:{$apiKeyId}:{$localServiceId}";
        return $apiKeyId === 7 && $localServiceId === 123
            ? new ServiceRecord(7, 123, 22, 9, 11, 10, 'monthly', 'active')
            : null;
    }

    public function findByUpstreamServiceId(int $upstreamServiceId): ?ServiceRecord
    {
        return null;
    }

    public function save(ServiceRecord $service): void
    {
    }

    public function isManagedUpstreamService(int $upstreamServiceId): bool
    {
        return true;
    }
}

final class ApiKernelOperations implements OperationRepository
{
    public function __construct(private readonly ApiKernelState $state)
    {
    }

    public function admit(Operation $operation, array $sanitizedPayload): Operation
    {
        return $operation;
    }

    public function findById(string $operationId): ?Operation
    {
        return Operation::admit($operationId, 7, 'suspend', 'existing', hash('sha256', 'existing'), 123, [])
            ->start()->complete(['service_status' => 'active']);
    }

    public function findByIdempotency(int $apiKeyId, string $idempotencyKey): ?Operation
    {
        return null;
    }

    public function save(Operation $operation): void
    {
    }

    public function claimDue(int $limit, int $now, string $owner, int $lockUntil): array
    {
        return [];
    }

    public function appendEvent(string $operationId, string $stage, array $sanitizedContext): void
    {
        $this->state->operationEvents[] = [$operationId, $stage, $sanitizedContext];
    }
}

final class ApiKernelProvisioning implements ProvisioningGateway
{
    public function create(int $serviceId, string $operationId): array
    {
        return [];
    }

    public function suspend(int $serviceId, string $reason, string $operationId): array
    {
        return [];
    }

    public function unsuspend(int $serviceId, string $operationId): array
    {
        return [];
    }

    public function terminate(int $serviceId, string $operationId): array
    {
        return [];
    }

    public function readDelivery(int $serviceId, array $mappings): array
    {
        return ['service_status' => 'active'];
    }

    public function sso(int $serviceId, array $allowedHosts, string $operationId): string
    {
        return 'https://panel.example.test/sso/secret';
    }
}
