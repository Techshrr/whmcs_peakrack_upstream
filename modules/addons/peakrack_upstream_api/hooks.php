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

use PeakRack\UpstreamApi\Bootstrap;
use PeakRack\UpstreamApi\Database\Schema;
use PeakRack\UpstreamApi\Whmcs\OperationContext;
use WHMCS\Database\Capsule;

require_once __DIR__ . '/lib/Bootstrap.php';
Bootstrap::register();

if (!function_exists('peakrack_upstream_api_guard_module_action')) {
    function peakrack_upstream_api_guard_module_action(array $variables): array
    {
        $serviceId = (int) ($variables['params']['serviceid'] ?? 0);
        if ($serviceId < 1 || OperationContext::isActive()) {
            return [];
        }

        try {
            $managed = Capsule::table(Schema::SERVICES)
                ->where('upstream_service_id', $serviceId)
                ->exists();
        } catch (Throwable) {
            return [];
        }

        return OperationContext::guardManagedService($managed);
    }
}

if (function_exists('add_hook')) {
    foreach ([
        'PreModuleSuspend',
        'PreModuleUnsuspend',
        'PreModuleTerminate',
        'PreModuleRenew',
        'PreModuleChangePackage',
    ] as $hookName) {
        add_hook($hookName, 1, 'peakrack_upstream_api_guard_module_action');
    }
}
