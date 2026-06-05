<?php

namespace PeakRack\Tests\Unit\Upstream;

use PeakRack\Tests\TestCase;
use PeakRack\UpstreamApi\Admin\AdminController;
use PeakRack\UpstreamApi\Admin\Csrf;
use PeakRack\UpstreamApi\Admin\TemplateRenderer;
use PeakRack\UpstreamApi\Contracts\ApiKeyRepository;
use PeakRack\UpstreamApi\Contracts\OperationRepository;
use PeakRack\UpstreamApi\Contracts\PolicyRepository;
use PeakRack\UpstreamApi\Domain\Operation;
use PeakRack\UpstreamApi\Domain\ProductPolicy;
use RuntimeException;

final class AdminControllerTest extends TestCase
{
    public function testAdminActionsRequireAuthenticatedAdminAndValidCsrf(): void
    {
        [$controller, , , , $session] = $this->controller();

        $this->assertThrows(
            fn () => $controller->dispatch(['page' => 'dashboard'], null, $session, 'addonmodules.php?module=test'),
            RuntimeException::class
        );
        $this->assertThrows(
            fn () => $controller->dispatch([
                'page' => 'api_keys',
                'action' => 'create_key',
                'csrf_token' => 'wrong',
                'client_id' => 44,
            ], 1, $session, 'addonmodules.php?module=test'),
            RuntimeException::class
        );
    }

    public function testApiSecretIsReturnedOnceAndNeverListed(): void
    {
        [$controller, $keys, , , $session] = $this->controller();
        $created = $controller->dispatch([
            'page' => 'api_keys',
            'action' => 'create_key',
            'csrf_token' => $session['peakrack_upstream_api_csrf'],
            'client_id' => 44,
            'ip_allowlist' => "192.0.2.10\n2001:db8::/32",
            'rate_limit_per_minute' => 120,
        ], 1, $session, 'addonmodules.php?module=test');

        $this->assertSame('secret-one', $created['secret_once']);
        $this->assertSame('encrypted:secret-one', $keys->rows[1]['encrypted_secret']);

        $listed = $controller->dispatch(
            ['page' => 'api_keys'],
            1,
            $session,
            'addonmodules.php?module=test'
        );
        $this->assertFalse(array_key_exists('encrypted_secret', $listed['data'][0]));
        $this->assertFalse(array_key_exists('secret_once', $listed['data'][0]));

        $rotated = $controller->dispatch([
            'page' => 'api_keys',
            'action' => 'rotate_key',
            'csrf_token' => $session['peakrack_upstream_api_csrf'],
            'key_id' => 1,
        ], 1, $session, 'addonmodules.php?module=test');
        $this->assertSame('secret-two', $rotated['secret_once']);
    }

    public function testEmptyIpAllowlistRequiresAcknowledgementAndKeySettingsCanBeUpdated(): void
    {
        [$controller, , , $state, $session] = $this->controller();

        $this->assertThrows(
            fn () => $controller->dispatch([
                'page' => 'api_keys',
                'action' => 'create_key',
                'csrf_token' => $session['peakrack_upstream_api_csrf'],
                'client_id' => 44,
                'ip_allowlist' => '',
                'rate_limit_per_minute' => 120,
            ], 1, $session, 'addonmodules.php?module=test'),
            \InvalidArgumentException::class
        );

        $controller->dispatch([
            'page' => 'api_keys',
            'action' => 'create_key',
            'csrf_token' => $session['peakrack_upstream_api_csrf'],
            'client_id' => 44,
            'ip_allowlist' => '',
            'acknowledge_empty_ip_allowlist' => '1',
            'rate_limit_per_minute' => 120,
        ], 1, $session, 'addonmodules.php?module=test');

        $controller->dispatch([
            'page' => 'api_keys',
            'action' => 'update_key_settings',
            'csrf_token' => $session['peakrack_upstream_api_csrf'],
            'key_id' => 1,
            'ip_allowlist' => '198.51.100.0/24',
            'rate_limit_per_minute' => 60,
        ], 1, $session, 'addonmodules.php?module=test');

        $this->assertSame([
            'id' => 1,
            'attributes' => [
                'ip_allowlist_json' => '["198.51.100.0/24"]',
                'rate_limit_per_minute' => 60,
            ],
        ], $state->keyUpdates[0]);
    }

    public function testBoundKeyCannotBeDeletedOrHaveIdentityChanged(): void
    {
        [$controller, $keys, , $state, $session] = $this->controller();
        $keys->rows[1] = [
            'id' => 1,
            'public_key' => 'public-key',
            'encrypted_secret' => 'encrypted:secret',
            'client_id' => 44,
            'instance_id' => '123e4567-e89b-42d3-a456-426614174000',
            'enabled' => 1,
        ];
        $keys->serviceKeys[1] = true;

        $controller->dispatch([
            'page' => 'api_keys',
            'action' => 'delete_key',
            'csrf_token' => $session['peakrack_upstream_api_csrf'],
            'key_id' => 1,
            'client_id' => 999,
            'instance_id' => 'changed',
        ], 1, $session, 'addonmodules.php?module=test');

        $this->assertSame([], $keys->deleted);
        $this->assertSame([['id' => 1, 'attributes' => ['enabled' => 0]]], $state->keyUpdates);
    }

    public function testPolicySaveRunsProductValidation(): void
    {
        [$controller, , $policies, $state, $session] = $this->controller();
        $controller->dispatch([
            'page' => 'product_policies',
            'action' => 'save_policy',
            'csrf_token' => $session['peakrack_upstream_api_csrf'],
            'api_key_id' => 1,
            'product_id' => 10,
            'billing_cycles' => '["monthly"]',
            'actions' => '["create","renew"]',
            'locations' => '{}',
            'os_templates' => '{}',
            'delivery_mappings' => '{}',
            'sso_hosts' => '["panel.example.test"]',
            'sso_allowed' => '1',
            'destroy_allowed' => '1',
        ], 1, $session, 'addonmodules.php?module=test');

        $this->assertSame([10], $state->validatedProducts);
        $this->assertSame(1, count($policies->saved));
        $this->assertSame(1, $policies->saved[0]['sso_allowed']);
        $this->assertSame(1, $policies->saved[0]['destroy_allowed']);
    }

    public function testManualReviewRetryIsExplicitAndAudited(): void
    {
        [$controller, , , $state, $session, $operations] = $this->controller();
        $operations->operations['operation-review'] = Operation::restore(
            id: 'operation-review',
            apiKeyId: 7,
            action: 'renew',
            idempotencyKey: 'renew:123',
            requestHash: hash('sha256', 'renew'),
            status: Operation::MANUAL_REVIEW,
            localServiceId: 123,
            sanitizedPayload: ['_billing_client_id' => 44],
            stage: 'compensation_pending',
            attemptCount: 12
        );

        $controller->dispatch([
            'page' => 'operations',
            'action' => 'retry_operation',
            'csrf_token' => $session['peakrack_upstream_api_csrf'],
            'operation_id' => 'operation-review',
        ], 9, $session, 'addonmodules.php?module=test');

        $this->assertSame(Operation::PROCESSING, $operations->operations['operation-review']->status());
        $this->assertSame('compensation_pending', $operations->operations['operation-review']->stage());
        $this->assertSame([
            'operation_id' => 'operation-review',
            'stage' => 'admin_retry',
            'context' => ['admin_id' => 9],
        ], $state->events[0]);
    }

    public function testAdminTemplateEscapesAllDynamicValues(): void
    {
        $renderer = new TemplateRenderer(
            PEAKRACK_UPSTREAM_ROOT . '/modules/addons/peakrack_upstream_api/templates'
        );

        $html = $renderer->render([
            'page' => 'dashboard',
            'modulelink' => 'addonmodules.php?module=test&x="<script>',
            'csrf_token' => 'csrf-token',
            'notice' => '<script>alert(1)</script>',
            'secret_once' => '<secret>',
            'data' => [['value' => '<img src=x onerror=alert(1)>']],
        ]);

        $this->assertStringNotContains('<script>', $html);
        $this->assertStringNotContains('<img src=x', $html);
        $this->assertStringContains('&lt;secret&gt;', $html);

        $policyHtml = $renderer->render([
            'page' => 'product_policies',
            'modulelink' => 'addonmodules.php?module=test',
            'csrf_token' => 'csrf-token',
            'data' => [],
        ]);
        $this->assertStringContains('name="sso_allowed"', $policyHtml);
        $this->assertStringContains('name="destroy_allowed"', $policyHtml);
    }

    private function controller(): array
    {
        $keys = new AdminControllerFakeKeys();
        $policies = new AdminControllerFakePolicies();
        $state = new AdminControllerState();
        $operations = new AdminControllerFakeOperations($state);
        $csrf = new Csrf(static fn (): string => 'csrf-token');
        $session = [];
        $csrf->token($session);
        $secrets = ['secret-one', 'secret-two'];

        $controller = new AdminController(
            keys: $keys,
            policies: $policies,
            operations: $operations,
            csrf: $csrf,
            encryptSecret: static fn (string $secret): string => 'encrypted:' . $secret,
            policyValidator: static function (array $policy) use ($state): void {
                $state->validatedProducts[] = (int) $policy['product_id'];
            },
            keyUpdater: static function (int $id, array $attributes) use ($state): void {
                $state->keyUpdates[] = ['id' => $id, 'attributes' => $attributes];
            },
            publicKeyGenerator: static fn (): string => 'public-key',
            secretGenerator: static function () use (&$secrets): string {
                return (string) array_shift($secrets);
            },
            instanceIdGenerator: static fn (): string => '123e4567-e89b-42d3-a456-426614174000',
            pageReader: static fn (string $page): array => [['page' => $page]],
            clock: static fn (): int => 1780617600
        );

        return [$controller, $keys, $policies, $state, $session, $operations];
    }
}

final class AdminControllerState
{
    public array $keyUpdates = [];
    public array $validatedProducts = [];
    public array $events = [];
}

final class AdminControllerFakeKeys implements ApiKeyRepository
{
    public array $rows = [];
    public array $serviceKeys = [];
    public array $deleted = [];

    public function find(int $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    public function findEnabledByPublicKey(string $publicKey): ?array
    {
        return null;
    }

    public function all(): array
    {
        return array_values($this->rows);
    }

    public function create(array $attributes): int
    {
        $id = count($this->rows) + 1;
        $attributes['id'] = $id;
        $this->rows[$id] = $attributes;
        return $id;
    }

    public function updateEncryptedSecret(int $id, string $encryptedSecret): void
    {
        $this->rows[$id]['encrypted_secret'] = $encryptedSecret;
    }

    public function updateUsage(int $id, string $sourceIp, int $usedAt): void
    {
    }

    public function consumeRateLimit(int $id, int $now, int $windowSeconds): bool
    {
        return true;
    }

    public function hasServices(int $id): bool
    {
        return $this->serviceKeys[$id] ?? false;
    }

    public function delete(int $id): void
    {
        $this->deleted[] = $id;
        unset($this->rows[$id]);
    }
}

final class AdminControllerFakePolicies implements PolicyRepository
{
    public array $saved = [];

    public function findForProduct(int $apiKeyId, int $productId): ?ProductPolicy
    {
        return null;
    }

    public function catalogForApiKey(int $apiKeyId): array
    {
        return [];
    }

    public function save(array $attributes): int
    {
        $this->saved[] = $attributes;
        return count($this->saved);
    }

    public function delete(int $id): void
    {
    }
}

final class AdminControllerFakeOperations implements OperationRepository
{
    public array $operations = [];
    public array $events = [];

    public function __construct(private readonly AdminControllerState $state)
    {
    }

    public function admit(Operation $operation, array $sanitizedPayload): Operation
    {
        return $operation;
    }

    public function findById(string $operationId): ?Operation
    {
        return $this->operations[$operationId] ?? null;
    }

    public function findByIdempotency(int $apiKeyId, string $idempotencyKey): ?Operation
    {
        return null;
    }

    public function save(Operation $operation): void
    {
        $this->operations[$operation->id()] = $operation;
    }

    public function claimDue(int $limit, int $now, string $owner, int $lockUntil): array
    {
        return [];
    }

    public function appendEvent(string $operationId, string $stage, array $sanitizedContext): void
    {
        $this->state->events[] = [
            'operation_id' => $operationId,
            'stage' => $stage,
            'context' => $sanitizedContext,
        ];
    }
}
