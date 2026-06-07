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

namespace PeakRack\UpstreamApi\Database;

use PeakRack\UpstreamApi\Contracts\AuditRepository;
use PeakRack\UpstreamApi\Security\Redactor;
use WHMCS\Database\Capsule;

final class CapsuleAuditRepository implements AuditRepository
{
    public function append(array $event): void
    {
        $context = $event['sanitized_context'] ?? $event['context'] ?? [];

        Capsule::table(Schema::AUDIT_EVENTS)->insert([
            'event_type' => (string) ($event['event_type'] ?? ''),
            'actor_type' => (string) ($event['actor_type'] ?? 'system'),
            'actor_id' => isset($event['actor_id']) ? (int) $event['actor_id'] : null,
            'client_id' => isset($event['client_id']) ? (int) $event['client_id'] : null,
            'application_id' => isset($event['application_id']) ? (int) $event['application_id'] : null,
            'api_key_id' => isset($event['api_key_id']) ? (int) $event['api_key_id'] : null,
            'sanitized_context_json' => json_encode(
                Redactor::redact(is_array($context) ? $context : []),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ),
            'created_at' => (int) ($event['created_at'] ?? time()),
        ]);
    }
}
