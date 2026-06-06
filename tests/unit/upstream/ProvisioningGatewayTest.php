<?php

namespace PeakRack\Tests\Unit\Upstream;

use PeakRack\Tests\TestCase;
use PeakRack\UpstreamApi\Domain\ValidationException;
use PeakRack\UpstreamApi\Whmcs\LocalApiClient;
use PeakRack\UpstreamApi\Whmcs\OperationContext;
use PeakRack\UpstreamApi\Whmcs\ProvisioningGateway;
use RuntimeException;

final class ProvisioningGatewayTest extends TestCase
{
    public function testModuleActionsUseExpectedServiceIdInsideAuthorizedContext(): void
    {
        $api = new ProvisioningLocalApiFake();
        $gateway = new ProvisioningGateway(
            new LocalApiClient($api),
            static fn (): array => [],
            static fn (): array => []
        );

        $gateway->create(22, 'op-create');
        $gateway->suspend(22, 'billing', 'op-suspend');
        $gateway->unsuspend(22, 'op-unsuspend');
        $gateway->terminate(22, 'op-terminate');

        $this->assertSame(
            ['ModuleCreate', 'ModuleSuspend', 'ModuleUnsuspend', 'ModuleTerminate'],
            $api->commands()
        );
        foreach ($api->calls as [, $parameters]) {
            $this->assertSame(22, $parameters['serviceid']);
        }
        $this->assertSame('billing', $api->parameters('ModuleSuspend')['suspendreason']);
        $this->assertTrue(!in_array(false, $api->authorized, true));
    }

    public function testDeliveryMappingsExcludeUnsafeCustomFields(): void
    {
        $gateway = new ProvisioningGateway(
            new LocalApiClient(new ProvisioningLocalApiFake()),
            static fn (int $serviceId): array => [
                'id' => $serviceId,
                'status' => 'Active',
                'dedicated_ip' => '192.0.2.20',
                'custom_fields' => [
                    12 => ['name' => 'Panel URL', 'value' => 'https://panel.example.test/'],
                    13 => ['name' => 'API Token', 'value' => 'do-not-leak'],
                ],
            ],
            static fn (): array => []
        );

        $delivery = $gateway->readDelivery(22, [
            'primary_ip' => ['source' => 'dedicated_ip'],
            'panel_url' => ['source' => 'custom_field', 'id' => 12],
            'secret' => ['source' => 'custom_field', 'id' => 13],
        ]);

        $this->assertSame([
            'service_status' => 'active',
            'primary_ip' => '192.0.2.20',
            'panel_url' => 'https://panel.example.test/',
        ], $delivery);
        $this->assertStringNotContains('do-not-leak', json_encode($delivery));
    }

    public function testSsoRequiresHttpsAndExactAllowedHost(): void
    {
        $url = 'https://panel.example.test/session/one-time';
        $gateway = new ProvisioningGateway(
            new LocalApiClient(new ProvisioningLocalApiFake()),
            static fn (): array => [],
            static fn () => ['success' => true, 'redirectTo' => $url]
        );
        $this->assertSame($url, $gateway->sso(22, ['panel.example.test'], 'op-sso'));

        $httpGateway = new ProvisioningGateway(
            new LocalApiClient(new ProvisioningLocalApiFake()),
            static fn (): array => [],
            static fn () => ['success' => true, 'redirectTo' => 'http://panel.example.test/session']
        );
        $wrongHostGateway = new ProvisioningGateway(
            new LocalApiClient(new ProvisioningLocalApiFake()),
            static fn (): array => [],
            static fn () => ['success' => true, 'redirectTo' => 'https://evil.example.test/session']
        );

        $this->assertThrows(
            static fn () => $httpGateway->sso(22, ['panel.example.test'], 'op-sso-http'),
            ValidationException::class
        );
        $this->assertThrows(
            static fn () => $wrongHostGateway->sso(22, ['panel.example.test'], 'op-sso-host'),
            ValidationException::class
        );
    }
}

final class ProvisioningLocalApiFake
{
    public array $calls = [];
    public array $authorized = [];

    public function __invoke(string $command, array $parameters): array
    {
        $this->calls[] = [$command, $parameters];
        $this->authorized[] = OperationContext::isActive();
        return ['result' => 'success'];
    }

    public function commands(): array
    {
        return array_map(static fn (array $call): string => $call[0], $this->calls);
    }

    public function parameters(string $command): array
    {
        foreach ($this->calls as [$called, $parameters]) {
            if ($called === $command) {
                return $parameters;
            }
        }

        throw new RuntimeException("Command {$command} was not called.");
    }
}
