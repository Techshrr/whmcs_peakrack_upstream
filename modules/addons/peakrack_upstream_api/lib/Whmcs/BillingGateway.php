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

namespace PeakRack\UpstreamApi\Whmcs;

use InvalidArgumentException;
use PeakRack\UpstreamApi\Contracts\BillingGateway as BillingGatewayContract;
use PeakRack\UpstreamApi\Domain\ApiError;
use PeakRack\UpstreamApi\Domain\ValidationException;

final class BillingGateway implements BillingGatewayContract
{
    public function __construct(private readonly LocalApiClient $api)
    {
    }

    public function create(
        int $clientId,
        int $productId,
        string $billingCycle,
        string $paymentMethod,
        array $orderFields,
        string $operationId
    ): array {
        $parameters = [
            'clientid' => $clientId,
            'paymentmethod' => $paymentMethod,
            'pid' => [$productId],
            'billingcycle' => [$billingCycle],
            'noemail' => true,
            'noinvoiceemail' => true,
        ];

        if (isset($orderFields['hostname'])) {
            $parameters['hostname'] = [(string) $orderFields['hostname']];
        }
        if (isset($orderFields['password'])) {
            $parameters['rootpw'] = [(string) $orderFields['password']];
        }
        if (isset($orderFields['configoptions']) && is_array($orderFields['configoptions'])) {
            $parameters['configoptions'] = [$this->encodeOrderFields($orderFields['configoptions'])];
        }
        if (isset($orderFields['customfields']) && is_array($orderFields['customfields'])) {
            $parameters['customfields'] = [$this->encodeOrderFields($orderFields['customfields'])];
        }

        $order = $this->call($operationId, 'AddOrder', $parameters);
        $orderId = $this->positiveId($order, 'orderid');
        $invoiceId = $this->positiveId($order, 'invoiceid');
        $serviceId = $this->firstServiceId($order['serviceids'] ?? null);
        $settled = $this->settleInvoice($clientId, $invoiceId, $operationId, $orderId);

        $this->call($operationId, 'AcceptOrder', [
            'orderid' => $orderId,
            'autosetup' => true,
            'sendemail' => false,
        ]);

        return [
            'order_id' => $orderId,
            'invoice_id' => $invoiceId,
            'service_id' => $serviceId,
            'applied_credit_amount' => $settled['amount'],
            'currency_code' => $settled['currency_code'],
        ];
    }

    public function renew(
        int $clientId,
        int $serviceId,
        string $paymentMethod,
        ?int $existingInvoiceId,
        string $operationId
    ): array {
        $orderId = null;
        $invoiceId = $existingInvoiceId;

        if ($invoiceId === null) {
            $order = $this->call($operationId, 'AddOrder', [
                'clientid' => $clientId,
                'paymentmethod' => $paymentMethod,
                'servicerenewals' => [$serviceId],
                'noemail' => true,
                'noinvoiceemail' => true,
            ]);
            $orderId = $this->positiveId($order, 'orderid');
            $invoiceId = $this->positiveId($order, 'invoiceid');
        }

        $settled = $this->settleInvoice($clientId, $invoiceId, $operationId, $orderId);
        if ($orderId !== null) {
            $this->call($operationId, 'AcceptOrder', [
                'orderid' => $orderId,
                'autosetup' => true,
                'sendemail' => false,
            ]);
        }

        return [
            'order_id' => $orderId,
            'invoice_id' => $invoiceId,
            'service_id' => $serviceId,
            'applied_credit_amount' => $settled['amount'],
            'currency_code' => $settled['currency_code'],
        ];
    }

    public function changePackage(
        int $clientId,
        int $serviceId,
        int $productId,
        string $billingCycle,
        string $paymentMethod,
        array $configOptions,
        string $operationId
    ): array {
        $parameters = [
            'serviceid' => $serviceId,
            'paymentmethod' => $paymentMethod,
            'type' => 'product',
            'newproductid' => $productId,
            'newproductbillingcycle' => $billingCycle,
        ];
        if ($configOptions !== []) {
            $parameters['configoptions'] = $configOptions;
        }

        $upgrade = $this->call($operationId, 'UpgradeProduct', $parameters);
        $orderId = $this->nullablePositiveId($upgrade['orderid'] ?? null);
        $invoiceId = $this->nullablePositiveId($upgrade['invoiceid'] ?? null);
        $settled = $invoiceId === null
            ? ['amount' => '0.00000000', 'currency_code' => null]
            : $this->settleInvoice($clientId, $invoiceId, $operationId, $orderId);

        return [
            'order_id' => $orderId,
            'invoice_id' => $invoiceId,
            'applied_credit_amount' => $settled['amount'],
            'currency_code' => $settled['currency_code'],
            'is_downgrade' => str_contains((string) ($upgrade['price'] ?? ''), '-'),
        ];
    }

    public function compensateCreate(
        int $clientId,
        string $amount,
        int $orderId,
        int $invoiceId,
        string $operationId
    ): void {
        $amount = $this->decimal($amount);
        if ($this->compareDecimals($amount, '0') <= 0) {
            throw new InvalidArgumentException('The compensation amount must be positive.');
        }

        $this->call($operationId, 'AddCredit', [
            'clientid' => $clientId,
            'description' => "PeakRack operation {$operationId} confirmed create failure compensation",
            'amount' => $amount,
            'type' => 'add',
        ]);
        $this->cancelOrderAndInvoice($orderId, $invoiceId, $operationId);
    }

    private function settleInvoice(
        int $clientId,
        int $invoiceId,
        string $operationId,
        ?int $orderId
    ): array {
        $invoice = $this->call($operationId, 'GetInvoice', ['invoiceid' => $invoiceId]);
        if ((int) ($invoice['userid'] ?? 0) !== $clientId) {
            throw new ValidationException(ApiError::INTERNAL_ERROR, 'The generated invoice owner is invalid.', 500);
        }

        $balance = $this->decimal($invoice['balance'] ?? null);
        $client = $this->call($operationId, 'GetClientsDetails', [
            'clientid' => $clientId,
            'stats' => false,
        ]);
        $clientDetails = is_array($client['client'] ?? null) ? $client['client'] : $client;
        $credit = $this->decimal($clientDetails['credit'] ?? null);
        $currencyCode = strtoupper((string) ($clientDetails['currency_code'] ?? ''));
        if (preg_match('/^[A-Z]{3}$/', $currencyCode) !== 1) {
            throw new ValidationException(ApiError::INTERNAL_ERROR, 'WHMCS returned an invalid client currency.', 500);
        }

        if ($this->compareDecimals($credit, $balance) < 0) {
            if ($orderId !== null) {
                $this->cancelOrderAndInvoice($orderId, $invoiceId, $operationId);
            }

            throw new ValidationException(
                ApiError::INSUFFICIENT_CREDIT,
                'The reseller client does not have enough Credit for the final invoice balance.',
                422
            );
        }

        if ($this->compareDecimals($balance, '0') === 0) {
            return ['amount' => '0.00000000', 'currency_code' => $currencyCode];
        }

        $applied = $this->call($operationId, 'ApplyCredit', [
            'invoiceid' => $invoiceId,
            'amount' => $balance,
            'noemail' => true,
        ]);
        $appliedAmount = $this->decimal($applied['amount'] ?? null);
        if (
            $this->compareDecimals($appliedAmount, $balance) !== 0
            || !in_array($applied['invoicepaid'] ?? null, ['true', true], true)
        ) {
            throw new ValidationException(ApiError::INTERNAL_ERROR, 'The final invoice was not paid exactly.', 500);
        }

        return ['amount' => $appliedAmount, 'currency_code' => $currencyCode];
    }

    private function cancelOrderAndInvoice(int $orderId, int $invoiceId, string $operationId): void
    {
        $this->call($operationId, 'CancelOrder', [
            'orderid' => $orderId,
            'cancelsub' => false,
            'noemail' => true,
        ]);
        $this->call($operationId, 'UpdateInvoice', [
            'invoiceid' => $invoiceId,
            'status' => 'Cancelled',
            'publish' => false,
        ]);
    }

    private function call(string $operationId, string $command, array $parameters): array
    {
        return OperationContext::run(
            $operationId,
            fn (): array => $this->api->call($command, $parameters)
        );
    }

    private function positiveId(array $response, string $key): int
    {
        $id = $this->nullablePositiveId($response[$key] ?? null);
        if ($id === null) {
            throw new ValidationException(ApiError::INTERNAL_ERROR, "WHMCS did not return a valid {$key}.", 500);
        }

        return $id;
    }

    private function nullablePositiveId(mixed $value): ?int
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            return null;
        }

        $id = (int) $value;
        return $id > 0 ? $id : null;
    }

    private function firstServiceId(mixed $value): int
    {
        $first = is_string($value) ? explode(',', $value)[0] : null;
        $serviceId = $this->nullablePositiveId($first);
        if ($serviceId === null) {
            throw new ValidationException(ApiError::INTERNAL_ERROR, 'WHMCS did not return a valid service ID.', 500);
        }

        return $serviceId;
    }

    private function encodeOrderFields(array $fields): string
    {
        return base64_encode(serialize($fields));
    }

    private function decimal(mixed $value): string
    {
        $decimal = is_int($value) || is_string($value) ? (string) $value : '';
        if (preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,8})?$/', $decimal) !== 1) {
            throw new ValidationException(ApiError::INTERNAL_ERROR, 'WHMCS returned an invalid monetary amount.', 500);
        }

        return $decimal;
    }

    private function compareDecimals(string $left, string $right): int
    {
        [$leftWhole, $leftFraction] = array_pad(explode('.', $this->decimal($left), 2), 2, '');
        [$rightWhole, $rightFraction] = array_pad(explode('.', $this->decimal($right), 2), 2, '');
        $leftWhole = ltrim($leftWhole, '0') ?: '0';
        $rightWhole = ltrim($rightWhole, '0') ?: '0';

        if (strlen($leftWhole) !== strlen($rightWhole)) {
            return strlen($leftWhole) <=> strlen($rightWhole);
        }

        $wholeComparison = strcmp($leftWhole, $rightWhole);
        if ($wholeComparison !== 0) {
            return $wholeComparison <=> 0;
        }

        return strcmp(str_pad($leftFraction, 8, '0'), str_pad($rightFraction, 8, '0')) <=> 0;
    }
}
