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

final class SetupGuideService
{
    public function build(array $input): array
    {
        $apiBaseUrl = rtrim((string) ($input['api_base_url'] ?? ''), '/');

        return [
            'download_url' => (string) ($input['download_url'] ?? ''),
            'module_name' => 'peakrackupstream',
            'api_base_url' => $apiBaseUrl,
            'health_url' => $apiBaseUrl . '/health',
            'public_key' => (string) ($input['public_key'] ?? ''),
            'secret' => (string) ($input['secret'] ?? ''),
            'cron_example' => '*/5 * * * * /path/to/php -q /path/to/whmcs/modules/servers/peakrackupstream/cron/sync.php >/dev/null 2>&1',
            'server_module_label' => 'PeakRack Upstream',
            'downstream_domain' => (string) ($input['downstream_domain'] ?? ''),
        ];
    }
}
