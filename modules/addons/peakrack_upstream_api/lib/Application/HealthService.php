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
use PeakRack\UpstreamApi\Config;

final class HealthService
{
    private readonly Closure $settingsReader;

    public function __construct(callable $settingsReader)
    {
        $this->settingsReader = Closure::fromCallable($settingsReader);
    }

    public function forApiKey(array $apiKey): array
    {
        $settings = ($this->settingsReader)();
        $settings = is_array($settings) ? $settings : [];
        $blocking = [];

        if (($settings['automatic_credit_use'] ?? false) === true) {
            $blocking[] = 'automatic_credit_use_enabled';
        }
        if (($settings['credit_on_downgrade'] ?? false) === true) {
            $blocking[] = 'credit_on_downgrade_enabled';
        }

        return [
            'protocol' => Config::API_VERSION,
            'module_version' => Config::VERSION,
            'instance_id' => (string) ($apiKey['instance_id'] ?? ''),
            'key_enabled' => (bool) ($apiKey['enabled'] ?? false),
            'blocking_errors' => $blocking,
            'worker_last_run' => $settings['worker_last_run'] ?? null,
        ];
    }
}
