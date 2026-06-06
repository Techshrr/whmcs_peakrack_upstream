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

use PeakRack\UpstreamApi\Contracts\LockRepository;
use Throwable;
use WHMCS\Database\Capsule;

final class CapsuleLockRepository implements LockRepository
{
    public function acquire(string $resource, string $owner, int $expiresAt): bool
    {
        try {
            return Capsule::connection()->transaction(function () use ($resource, $owner, $expiresAt): bool {
                $row = Capsule::table(Schema::LOCKS)
                    ->where('lock_name', $resource)
                    ->lockForUpdate()
                    ->first();
                $now = time();

                if ($row === null) {
                    Capsule::table(Schema::LOCKS)->insert([
                        'lock_name' => $resource,
                        'owner' => $owner,
                        'expires_at' => $expiresAt,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    return true;
                }

                $attributes = (array) $row;
                if ((string) $attributes['owner'] !== $owner && (int) $attributes['expires_at'] > $now) {
                    return false;
                }

                Capsule::table(Schema::LOCKS)
                    ->where('lock_name', $resource)
                    ->update([
                        'owner' => $owner,
                        'expires_at' => $expiresAt,
                        'updated_at' => $now,
                    ]);

                return true;
            });
        } catch (Throwable $exception) {
            $row = Capsule::table(Schema::LOCKS)->where('lock_name', $resource)->first();
            if ($row !== null && (string) $row->owner === $owner) {
                return true;
            }

            if ($row !== null && (int) $row->expires_at > time()) {
                return false;
            }

            throw $exception;
        }
    }

    public function release(string $resource, string $owner): void
    {
        Capsule::table(Schema::LOCKS)
            ->where('lock_name', $resource)
            ->where('owner', $owner)
            ->delete();
    }

    public function purgeExpired(int $now): int
    {
        return Capsule::table(Schema::LOCKS)
            ->where('expires_at', '<=', $now)
            ->delete();
    }
}
