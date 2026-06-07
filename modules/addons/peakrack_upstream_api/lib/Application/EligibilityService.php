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

use PeakRack\UpstreamApi\Contracts\ClientAccountGateway;

final class EligibilityService
{
    public function __construct(
        private readonly ClientAccountGateway $gateway,
        private readonly array $allowedClientGroupIds
    ) {
    }

    public function check(int $clientId, bool $hasActiveApplication): array
    {
        $client = $this->gateway->clientSummary($clientId);
        $reasons = [];

        if (($client['status'] ?? '') !== 'Active') {
            $reasons[] = 'Your account status must be Active.';
        }
        if (($client['email_verified'] ?? false) !== true) {
            $reasons[] = 'Your email address must be verified.';
        }
        if (!in_array((int) ($client['group_id'] ?? 0), $this->allowedClientGroupIds, true)) {
            $reasons[] = 'Your account is not in an allowed reseller group.';
        }
        if ((int) ($client['overdue_invoices'] ?? 0) > 0) {
            $reasons[] = 'Your account has overdue invoices.';
        }
        if ($hasActiveApplication) {
            $reasons[] = 'Your account already has a pending or approved integration application.';
        }

        return ['eligible' => $reasons === [], 'reasons' => $reasons];
    }
}
