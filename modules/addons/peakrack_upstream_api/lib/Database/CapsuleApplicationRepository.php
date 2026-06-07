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

use PeakRack\UpstreamApi\Contracts\ApplicationRepository;
use PeakRack\UpstreamApi\Domain\OnboardingApplication;
use WHMCS\Database\Capsule;

final class CapsuleApplicationRepository implements ApplicationRepository
{
    public function create(array $attributes): int
    {
        $now = time();
        $attributes['active_client_key'] = $attributes['active_client_key']
            ?? $this->activeClientKey((int) ($attributes['client_id'] ?? 0), (string) ($attributes['status'] ?? ''));
        $attributes['created_at'] = $attributes['created_at'] ?? $now;
        $attributes['updated_at'] = $attributes['updated_at'] ?? $now;

        return (int) Capsule::table(Schema::APPLICATIONS)->insertGetId($attributes);
    }

    public function find(int $id): ?OnboardingApplication
    {
        return $this->rowToApplication(Capsule::table(Schema::APPLICATIONS)->where('id', $id)->first());
    }

    public function findActiveForClient(int $clientId): ?OnboardingApplication
    {
        return $this->rowToApplication(
            Capsule::table(Schema::APPLICATIONS)
                ->where('active_client_key', $this->activeClientKey($clientId, OnboardingApplication::PENDING))
                ->orderByDesc('id')
                ->first()
        );
    }

    public function findApprovedForClient(int $clientId): ?OnboardingApplication
    {
        return $this->rowToApplication(
            Capsule::table(Schema::APPLICATIONS)
                ->where('client_id', $clientId)
                ->where('status', OnboardingApplication::APPROVED)
                ->orderByDesc('id')
                ->first()
        );
    }

    public function findPending(int $id): ?OnboardingApplication
    {
        return $this->rowToApplication(
            Capsule::table(Schema::APPLICATIONS)
                ->where('id', $id)
                ->where('status', OnboardingApplication::PENDING)
                ->first()
        );
    }

    public function save(OnboardingApplication $application): void
    {
        $row = $application->toRow();
        $id = (int) $row['id'];
        unset($row['id']);

        Capsule::table(Schema::APPLICATIONS)->where('id', $id)->update($row);
    }

    public function listByStatus(?string $status, int $limit = 100): array
    {
        $query = Capsule::table(Schema::APPLICATIONS)->orderByDesc('id')->limit(max(1, min(500, $limit)));
        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        return array_map(static fn (object $row): array => (array) $row, $query->get()->all());
    }

    private function rowToApplication(?object $row): ?OnboardingApplication
    {
        return $row === null ? null : OnboardingApplication::restore((array) $row);
    }

    private function activeClientKey(int $clientId, string $status): ?string
    {
        return in_array($status, [OnboardingApplication::PENDING, OnboardingApplication::APPROVED], true)
            ? 'client:' . $clientId
            : null;
    }
}
