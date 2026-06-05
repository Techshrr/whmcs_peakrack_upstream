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

use PeakRack\UpstreamApi\Application\ChangePackageExecutor;
use PeakRack\UpstreamApi\Application\CreateExecutor;
use PeakRack\UpstreamApi\Application\LifecycleExecutor;
use PeakRack\UpstreamApi\Application\OperationExecutor;
use PeakRack\UpstreamApi\Application\RenewExecutor;
use PeakRack\UpstreamApi\Application\WorkerService;
use PeakRack\UpstreamApi\Database\CapsuleLockRepository;
use PeakRack\UpstreamApi\Database\CapsuleNonceRepository;
use PeakRack\UpstreamApi\Database\CapsuleOperationRepository;
use PeakRack\UpstreamApi\Database\CapsuleServiceRepository;
use PeakRack\UpstreamApi\Support\SystemClock;
use RuntimeException;
use WHMCS\Database\Capsule;

final class WorkerFactory
{
    public static function create(): WorkerService
    {
        $operations = new CapsuleOperationRepository();
        $services = new CapsuleServiceRepository();
        $localApi = new LocalApiClient();
        $billing = new BillingGateway($localApi);

        $serviceReader = static function (int $serviceId): array {
            $service = Capsule::table('tblhosting')->where('id', $serviceId)->first();
            if ($service === null) {
                throw new RuntimeException('Service not found.');
            }

            $customFields = [];
            $rows = Capsule::table('tblcustomfieldsvalues')
                ->join('tblcustomfields', 'tblcustomfields.id', '=', 'tblcustomfieldsvalues.fieldid')
                ->where('tblcustomfieldsvalues.relid', $serviceId)
                ->where('tblcustomfields.type', 'product')
                ->get(['tblcustomfields.id', 'tblcustomfields.fieldname', 'tblcustomfieldsvalues.value'])
                ->all();
            foreach ($rows as $row) {
                $customFields[(int) $row->id] = [
                    'name' => (string) $row->fieldname,
                    'value' => (string) $row->value,
                ];
            }

            return [
                'status' => (string) $service->domainstatus,
                'dedicated_ip' => (string) $service->dedicatedip,
                'product_id' => (int) $service->packageid,
                'billing_cycle' => (string) $service->billingcycle,
                'next_due_date' => (string) $service->nextduedate,
                'custom_fields' => $customFields,
            ];
        };
        $provisioning = new ProvisioningGateway(
            $localApi,
            $serviceReader,
            static fn (): array => ['success' => false]
        );
        $cancelRequest = static function (int $serviceId, string $operationId) use ($localApi, $serviceReader): array {
            OperationContext::run($operationId, static fn (): array => $localApi->call('AddCancelRequest', [
                'serviceid' => $serviceId,
                'type' => 'End of Billing Period',
                'reason' => 'Authorized PeakRack reseller cancellation',
            ]));
            $state = $serviceReader($serviceId);
            $dueAt = strtotime((string) $state['next_due_date'] . ' 00:00:00 UTC');
            return ['due_at' => $dueAt === false ? 0 : $dueAt];
        };
        $renewalInvoiceFinder = static function (int $clientId, int $serviceId, string $boundary): ?int {
            $invoiceId = Capsule::table('tblinvoiceitems')
                ->join('tblinvoices', 'tblinvoices.id', '=', 'tblinvoiceitems.invoiceid')
                ->where('tblinvoiceitems.userid', $clientId)
                ->where('tblinvoiceitems.type', 'Hosting')
                ->where('tblinvoiceitems.relid', $serviceId)
                ->where('tblinvoices.userid', $clientId)
                ->where('tblinvoices.status', 'Unpaid')
                ->where('tblinvoices.duedate', $boundary)
                ->orderBy('tblinvoices.id')
                ->value('tblinvoices.id');
            return $invoiceId === null ? null : (int) $invoiceId;
        };
        $executor = new OperationExecutor([
            'create' => new CreateExecutor($billing, $provisioning, $services, $operations),
            'renew' => new RenewExecutor($billing, $operations, $serviceReader, $renewalInvoiceFinder),
            'change_package' => new ChangePackageExecutor($billing, $operations, $services, $serviceReader),
            'suspend' => new LifecycleExecutor($provisioning, $services, $operations, $cancelRequest),
            'unsuspend' => new LifecycleExecutor($provisioning, $services, $operations, $cancelRequest),
            'terminate' => new LifecycleExecutor($provisioning, $services, $operations, $cancelRequest),
            'terminate_due' => new LifecycleExecutor($provisioning, $services, $operations, $cancelRequest),
        ]);

        return new WorkerService(
            $operations,
            new CapsuleNonceRepository(),
            new CapsuleLockRepository(),
            $executor,
            new SystemClock()
        );
    }
}
