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
use InvalidArgumentException;
use PeakRack\UpstreamApi\Contracts\ApiKeyRepository;
use PeakRack\UpstreamApi\Contracts\ApplicationRepository;
use PeakRack\UpstreamApi\Contracts\AuditRepository;
use PeakRack\UpstreamApi\Contracts\SecretResetRepository;
use PeakRack\UpstreamApi\Domain\OnboardingApplication;
use RuntimeException;

final class CredentialService
{
    private const CLIENT_MONTHLY_RESET_LIMIT = 3;
    private const CLIENT_RESET_INTERVAL_SECONDS = 864000;

    private readonly Closure $encryptSecret;
    private readonly Closure $decryptSecret;
    private readonly Closure $secretGenerator;
    private readonly Closure $clock;

    public function __construct(
        private readonly ApplicationRepository $applications,
        private readonly ApiKeyRepository $keys,
        private readonly SecretResetRepository $resets,
        private readonly AuditRepository $audits,
        callable $encryptSecret,
        callable $decryptSecret,
        callable $secretGenerator,
        callable $clock
    ) {
        $this->encryptSecret = Closure::fromCallable($encryptSecret);
        $this->decryptSecret = Closure::fromCallable($decryptSecret);
        $this->secretGenerator = Closure::fromCallable($secretGenerator);
        $this->clock = Closure::fromCallable($clock);
    }

    public function displayPendingSecretForClient(int $clientId): ?array
    {
        $application = $this->applications->findApprovedForClient($clientId);
        if (!$application instanceof OnboardingApplication || !$application->secretPendingDisplay()) {
            return null;
        }

        $key = $this->keyForApplication($application);

        return [
            'application_id' => $application->id(),
            'api_key_id' => $application->apiKeyId(),
            'public_key' => (string) ($key['public_key'] ?? ''),
            'secret' => (string) ($this->decryptSecret)((string) ($key['encrypted_secret'] ?? '')),
        ];
    }

    public function confirmSecretDisplayed(int $clientId): void
    {
        $application = $this->applications->findApprovedForClient($clientId);
        if (!$application instanceof OnboardingApplication) {
            throw new InvalidArgumentException('Approved application not found.');
        }

        $now = ($this->clock)();
        $this->applications->save($application->markSecretDisplayed($now));
        $this->audits->append([
            'event_type' => 'secret_viewed_once',
            'actor_type' => 'client',
            'actor_id' => $clientId,
            'client_id' => $clientId,
            'application_id' => $application->id(),
            'api_key_id' => $application->apiKeyId(),
            'created_at' => $now,
            'context' => [],
        ]);
    }

    public function resetByClient(int $clientId, string $sourceIp): array
    {
        $application = $this->applications->findApprovedForClient($clientId);
        if (!$application instanceof OnboardingApplication) {
            throw new InvalidArgumentException('Approved application not found.');
        }

        $now = ($this->clock)();
        $apiKeyId = $this->apiKeyId($application);
        $monthStart = (int) strtotime(date('Y-m-01 00:00:00', $now));
        $monthEnd = (int) strtotime('+1 month', $monthStart);
        if ($this->resets->countClientResetsInMonth($apiKeyId, $clientId, $monthStart, $monthEnd) >= self::CLIENT_MONTHLY_RESET_LIMIT) {
            throw new InvalidArgumentException('You have reached the 3 API Secret resets allowed for this month.');
        }

        $latest = $this->resets->latestClientResetAt($apiKeyId, $clientId);
        if ($latest !== null && $latest + self::CLIENT_RESET_INTERVAL_SECONDS > $now) {
            throw new InvalidArgumentException(
                'You can reset the API Secret again after '
                . date('Y-m-d H:i:s', $latest + self::CLIENT_RESET_INTERVAL_SECONDS)
                . '.'
            );
        }

        return $this->resetSecret($application, 'client', $clientId, $clientId, $sourceIp, false, $now);
    }

    public function resetByAdmin(int $applicationId, int $adminId, string $sourceIp): array
    {
        $application = $this->applications->find($applicationId);
        if (!$application instanceof OnboardingApplication || $application->status() !== OnboardingApplication::APPROVED) {
            throw new InvalidArgumentException('Approved application not found.');
        }

        return $this->resetSecret($application, 'admin', $adminId, $application->clientId(), $sourceIp, true, ($this->clock)());
    }

    private function resetSecret(
        OnboardingApplication $application,
        string $actorType,
        int $actorId,
        int $clientId,
        string $sourceIp,
        bool $bypassedLimits,
        int $now
    ): array {
        $apiKeyId = $this->apiKeyId($application);
        $secret = $this->generatedSecret();
        $this->keys->updateEncryptedSecret($apiKeyId, (string) ($this->encryptSecret)($secret));
        $this->applications->save($application->markSecretPendingDisplay($now));
        $this->resets->record([
            'api_key_id' => $apiKeyId,
            'client_id' => $clientId,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'reset_at' => $now,
            'bypassed_limits' => $bypassedLimits ? 1 : 0,
            'source_ip' => $sourceIp,
            'created_at' => $now,
        ]);
        $this->audits->append([
            'event_type' => $actorType === 'admin' ? 'secret_reset_by_admin' : 'secret_reset_by_client',
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'client_id' => $clientId,
            'application_id' => $application->id(),
            'api_key_id' => $apiKeyId,
            'created_at' => $now,
            'context' => ['bypassed_limits' => $bypassedLimits, 'source_ip' => $sourceIp],
        ]);

        return ['api_key_id' => $apiKeyId, 'secret' => $secret];
    }

    private function keyForApplication(OnboardingApplication $application): array
    {
        $key = $this->keys->find($this->apiKeyId($application));
        if ($key === null || (int) ($key['client_id'] ?? 0) !== $application->clientId()) {
            throw new InvalidArgumentException('API key not found.');
        }

        return $key;
    }

    private function apiKeyId(OnboardingApplication $application): int
    {
        $apiKeyId = $application->apiKeyId();
        if ($apiKeyId === null || $apiKeyId < 1) {
            throw new InvalidArgumentException('API key not found.');
        }

        return $apiKeyId;
    }

    private function generatedSecret(): string
    {
        $secret = (string) ($this->secretGenerator)();
        if ($secret === '' || str_contains($secret, "\0")) {
            throw new RuntimeException('Unable to generate a valid secret.');
        }

        return $secret;
    }
}
