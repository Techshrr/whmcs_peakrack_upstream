<?php

namespace PeakRack\Tests\Unit\Downstream;

use PeakRack\Tests\TestCase;
use PeakRack\Upstream\Api\ApiClientInterface;
use PeakRack\Upstream\SsoService;

final class ClientAreaTest extends TestCase
{
    public function testClientAreaReturnsOnlyApprovedCachedVariables(): void
    {
        require_once PEAKRACK_UPSTREAM_ROOT . '/modules/servers/peakrackupstream/peakrackupstream.php';
        $store = new ClientAreaFakeStore([
            'Dedicated IP' => '192.0.2.10',
            'Upstream Panel URL' => 'https://panel.example.test/',
            'Last Sync Time' => '2026-06-05T12:00:00Z',
            'Upstream Service ID' => '456',
            'Upstream Operation ID' => 'internal-operation',
            'Provisioning Error' => 'internal-error',
        ]);
        $model = new \stdClass();
        $model->serviceProperties = $store;

        $result = peakrackupstream_ClientArea([
            'model' => $model,
            'status' => 'Active',
        ]);

        $this->assertSame('clientarea', $result['templatefile']);
        $this->assertSame([
            'service_status',
            'primary_ip',
            'panel_url',
            'last_sync_time',
            'sso_available',
        ], array_keys($result['vars']));
        $serialized = serialize($result['vars']);
        $this->assertStringNotContains('internal-operation', $serialized);
        $this->assertStringNotContains('internal-error', $serialized);
        $this->assertStringNotContains('456', $serialized);
    }

    public function testSsoReturnsOnlyAllowedHttpsRedirect(): void
    {
        $api = new ClientAreaFakeApi();
        $service = new SsoService($api, static fn (): string => 'random-one');
        $api->ssoUrl = 'https://panel.example.test/sso';
        $success = $service->request(123);
        $this->assertTrue($success['success']);
        $this->assertSame('https://panel.example.test/sso', $success['redirectTo']);

        $api->ssoUrl = 'http://panel.example.test/sso';
        $failure = $service->request(123);
        $this->assertFalse($failure['success']);
        $this->assertFalse(array_key_exists('redirectTo', $failure));
    }

    public function testClientAreaTemplateIsThemeNeutralAndHasNoLifecycleButtons(): void
    {
        $template = PEAKRACK_UPSTREAM_ROOT . '/modules/servers/peakrackupstream/templates/clientarea.tpl';
        $this->assertFileExists($template);
        $contents = (string) file_get_contents($template);

        $this->assertStringContains('dosinglesignon=1', $contents);
        $this->assertStringContains('|escape', $contents);
        foreach (['Suspend', 'Unsuspend', 'Terminate', 'Destroy'] as $button) {
            $this->assertStringNotContains($button, $contents);
        }
        $this->assertStringNotContains('lagom', strtolower($contents));
    }
}

final class ClientAreaFakeStore
{
    public function __construct(public array $values = [])
    {
    }

    public function get(string $name): mixed
    {
        return $this->values[$name] ?? null;
    }

    public function save(array $values): void
    {
        $this->values = array_replace($this->values, $values);
    }
}

final class ClientAreaFakeApi implements ApiClientInterface
{
    public string $ssoUrl = '';

    public function health(): array
    {
        return [];
    }

    public function catalog(): array
    {
        return [];
    }

    public function createService(array $payload, string $idempotencyKey): array
    {
        return [];
    }

    public function getService(int $localServiceId): array
    {
        return [];
    }

    public function suspendService(int $localServiceId, string $idempotencyKey): array
    {
        return [];
    }

    public function unsuspendService(int $localServiceId, string $idempotencyKey): array
    {
        return [];
    }

    public function terminateService(int $localServiceId, string $mode, string $idempotencyKey): array
    {
        return [];
    }

    public function renewService(int $localServiceId, string $renewalBoundary, string $idempotencyKey): array
    {
        return [];
    }

    public function changePackage(int $localServiceId, array $payload, string $idempotencyKey): array
    {
        return [];
    }

    public function getSsoUrl(int $localServiceId, string $idempotencyKey): array
    {
        return [
            'success' => true,
            'status' => 'completed',
            'data' => ['sso_url' => $this->ssoUrl],
            'operation_id' => null,
            'error' => null,
        ];
    }
}
