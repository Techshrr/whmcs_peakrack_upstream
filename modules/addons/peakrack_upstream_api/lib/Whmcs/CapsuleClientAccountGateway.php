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

use PeakRack\UpstreamApi\Contracts\ClientAccountGateway;
use WHMCS\Database\Capsule;

final class CapsuleClientAccountGateway implements ClientAccountGateway
{
    public function clientSummary(int $clientId): array
    {
        $client = Capsule::table('tblclients')->where('id', $clientId)->first();
        $attributes = $client === null ? [] : (array) $client;

        return [
            'status' => (string) ($attributes['status'] ?? ''),
            'email_verified' => $this->emailVerified($attributes),
            'group_id' => (int) ($attributes['groupid'] ?? 0),
            'overdue_invoices' => $this->overdueInvoiceCount($clientId),
        ];
    }

    private function emailVerified(array $client): bool
    {
        $value = $client['email_verified'] ?? null;

        return $value === true || $value === 1 || $value === '1' || $value === 'on';
    }

    private function overdueInvoiceCount(int $clientId): int
    {
        return (int) Capsule::table('tblinvoices')
            ->where('userid', $clientId)
            ->where('status', 'Unpaid')
            ->where('duedate', '<', date('Y-m-d'))
            ->count();
    }
}
