<?php

namespace PeakRack\Tests\Unit\Upstream;

use PeakRack\Tests\TestCase;
use PeakRack\UpstreamApi\Domain\ApiError;
use PeakRack\UpstreamApi\Domain\ValidationException;
use PeakRack\UpstreamApi\Whmcs\BillingGateway;
use PeakRack\UpstreamApi\Whmcs\LocalApiClient;
use PeakRack\UpstreamApi\Whmcs\OperationContext;
use RuntimeException;

final class BillingGatewayTest extends TestCase
{
    public function testCreateUsesFinalInvoiceAppliesCreditAndAcceptsWithoutEmail(): void
    {
        [$gateway, $api] = $this->gateway([
            'AddOrder' => [[
                'result' => 'success',
                'orderid' => 9,
                'invoiceid' => 11,
                'serviceids' => '22',
            ]],
            'GetInvoice' => [[
                'result' => 'success',
                'invoiceid' => 11,
                'userid' => 44,
                'balance' => '12.34000000',
            ]],
            'GetClientsDetails' => [[
                'result' => 'success',
                'client' => ['credit' => '20.00', 'currency_code' => 'USD'],
            ]],
            'ApplyCredit' => [[
                'result' => 'success',
                'amount' => '12.34000000',
                'invoicepaid' => 'true',
            ]],
            'AcceptOrder' => [['result' => 'success']],
        ]);

        $result = $gateway->create(
            44,
            10,
            'monthly',
            'mailin',
            [
                'hostname' => 'server.example.test',
                'password' => 'temporary-secret',
                'configoptions' => [1 => 4],
                'customfields' => [8 => 'hk-1'],
            ],
            'operation-create'
        );

        $this->assertSame([
            'order_id' => 9,
            'invoice_id' => 11,
            'service_id' => 22,
            'applied_credit_amount' => '12.34000000',
            'currency_code' => 'USD',
        ], $result);
        $this->assertSame(
            ['AddOrder', 'GetInvoice', 'GetClientsDetails', 'ApplyCredit', 'AcceptOrder'],
            $api->commands()
        );

        $addOrder = $api->parameters('AddOrder');
        $this->assertSame(true, $addOrder['noemail']);
        $this->assertSame(true, $addOrder['noinvoiceemail']);
        $this->assertSame([10], $addOrder['pid']);
        $this->assertSame(['monthly'], $addOrder['billingcycle']);
        $this->assertSame(['temporary-secret'], $addOrder['rootpw']);
        $this->assertSame(true, $api->parameters('ApplyCredit')['noemail']);
        $this->assertSame(false, $api->parameters('AcceptOrder')['sendemail']);
        $this->assertSame(true, $api->parameters('AcceptOrder')['autosetup']);
        $this->assertTrue(!in_array(false, $api->authorized, true));
    }

    public function testInsufficientFinalBalanceCancelsBeforeProvisioning(): void
    {
        [$gateway, $api] = $this->gateway([
            'AddOrder' => [[
                'result' => 'success',
                'orderid' => 9,
                'invoiceid' => 11,
                'serviceids' => '22',
            ]],
            'GetInvoice' => [[
                'result' => 'success',
                'invoiceid' => 11,
                'userid' => 44,
                'balance' => '12.35',
            ]],
            'GetClientsDetails' => [[
                'result' => 'success',
                'client' => ['credit' => '12.34', 'currency_code' => 'USD'],
            ]],
            'CancelOrder' => [['result' => 'success']],
            'UpdateInvoice' => [['result' => 'success']],
        ]);

        try {
            $gateway->create(44, 10, 'monthly', 'mailin', [], 'operation-insufficient');
        } catch (ValidationException $exception) {
            $this->assertSame(ApiError::INSUFFICIENT_CREDIT, $exception->apiErrorCode());
            $this->assertSame(
                ['AddOrder', 'GetInvoice', 'GetClientsDetails', 'CancelOrder', 'UpdateInvoice'],
                $api->commands()
            );
            $this->assertSame(true, $api->parameters('CancelOrder')['noemail']);
            $this->assertSame('Cancelled', $api->parameters('UpdateInvoice')['status']);
            $this->assertFalse(in_array('ApplyCredit', $api->commands(), true));
            $this->assertFalse(in_array('AcceptOrder', $api->commands(), true));
            return;
        }

        throw new RuntimeException('Expected insufficient Credit rejection.');
    }

    public function testRenewReusesExistingInvoiceOrCreatesServiceRenewalOrder(): void
    {
        [$existingGateway, $existingApi] = $this->gateway($this->paidInvoiceScript(44, 51, '5.00'));
        $existing = $existingGateway->renew(44, 22, 'mailin', 51, 'operation-renew-existing');
        $this->assertSame(51, $existing['invoice_id']);
        $this->assertFalse(in_array('AddOrder', $existingApi->commands(), true));

        $script = $this->paidInvoiceScript(44, 52, '5.00');
        $script['AddOrder'] = [[
            'result' => 'success',
            'orderid' => 19,
            'invoiceid' => 52,
            'serviceids' => '22',
        ]];
        $script['AcceptOrder'] = [['result' => 'success']];
        [$newGateway, $newApi] = $this->gateway($script);
        $newGateway->renew(44, 22, 'mailin', null, 'operation-renew-new');

        $this->assertSame([22], $newApi->parameters('AddOrder')['servicerenewals']);
        $this->assertSame(true, $newApi->parameters('AddOrder')['noemail']);
        $this->assertSame(false, $newApi->parameters('AcceptOrder')['sendemail']);
    }

    public function testChangePackageUsesUpgradeProductAndNeverAddsDowngradeCredit(): void
    {
        [$gateway, $api] = $this->gateway([
            'UpgradeProduct' => [[
                'result' => 'success',
                'orderid' => 73,
                'invoiceid' => null,
                'price' => '$-8.67 USD',
            ]],
        ]);

        $result = $gateway->changePackage(
            44,
            22,
            11,
            'monthly',
            'mailin',
            [1 => 4],
            'operation-change'
        );

        $this->assertSame(['UpgradeProduct'], $api->commands());
        $this->assertSame('product', $api->parameters('UpgradeProduct')['type']);
        $this->assertSame(11, $api->parameters('UpgradeProduct')['newproductid']);
        $this->assertSame(true, $result['is_downgrade']);
        $this->assertFalse(in_array('AddCredit', $api->commands(), true));
    }

    public function testCompensationRestoresExactStoredAmountAndCancelsRecords(): void
    {
        [$gateway, $api] = $this->gateway([
            'AddCredit' => [['result' => 'success', 'newbalance' => '30.00']],
            'CancelOrder' => [['result' => 'success']],
            'UpdateInvoice' => [['result' => 'success']],
        ]);

        $gateway->compensateCreate(44, '12.34000000', 9, 11, 'operation-create');

        $credit = $api->parameters('AddCredit');
        $this->assertSame('12.34000000', $credit['amount']);
        $this->assertSame('add', $credit['type']);
        $this->assertStringContains('operation-create', $credit['description']);
        $this->assertSame(true, $api->parameters('CancelOrder')['noemail']);
    }

    private function gateway(array $script): array
    {
        $fake = new BillingLocalApiFake($script);
        return [new BillingGateway(new LocalApiClient($fake)), $fake];
    }

    private function paidInvoiceScript(int $clientId, int $invoiceId, string $amount): array
    {
        return [
            'GetInvoice' => [[
                'result' => 'success',
                'invoiceid' => $invoiceId,
                'userid' => $clientId,
                'balance' => $amount,
            ]],
            'GetClientsDetails' => [[
                'result' => 'success',
                'client' => ['credit' => '20.00', 'currency_code' => 'USD'],
            ]],
            'ApplyCredit' => [[
                'result' => 'success',
                'amount' => $amount,
                'invoicepaid' => 'true',
            ]],
        ];
    }
}

final class BillingLocalApiFake
{
    public array $calls = [];
    public array $authorized = [];

    public function __construct(private array $script)
    {
    }

    public function __invoke(string $command, array $parameters): array
    {
        $this->calls[] = [$command, $parameters];
        $this->authorized[] = OperationContext::isActive();
        $responses = $this->script[$command] ?? [];
        if ($responses === []) {
            throw new RuntimeException("Unexpected Local API command {$command}.");
        }

        $response = array_shift($responses);
        $this->script[$command] = $responses;
        return $response;
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
