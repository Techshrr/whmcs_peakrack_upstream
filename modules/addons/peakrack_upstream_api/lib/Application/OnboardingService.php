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
use PeakRack\UpstreamApi\Contracts\PolicyRepository;
use PeakRack\UpstreamApi\Contracts\PolicyTemplateRepository;
use PeakRack\UpstreamApi\Domain\OnboardingApplication;
use RuntimeException;

final class OnboardingService
{
    private readonly Closure $encryptSecret;
    private readonly Closure $publicKeyGenerator;
    private readonly Closure $secretGenerator;
    private readonly Closure $instanceIdGenerator;
    private readonly Closure $clock;
    private readonly Closure $transaction;

    public function __construct(
        private readonly ApplicationRepository $applications,
        private readonly ApiKeyRepository $keys,
        private readonly PolicyRepository $policies,
        private readonly PolicyTemplateRepository $templates,
        private readonly AuditRepository $audits,
        callable $encryptSecret,
        callable $publicKeyGenerator,
        callable $secretGenerator,
        callable $instanceIdGenerator,
        private readonly int $defaultRateLimit,
        callable $clock,
        ?callable $transaction = null
    ) {
        $this->encryptSecret = Closure::fromCallable($encryptSecret);
        $this->publicKeyGenerator = Closure::fromCallable($publicKeyGenerator);
        $this->secretGenerator = Closure::fromCallable($secretGenerator);
        $this->instanceIdGenerator = Closure::fromCallable($instanceIdGenerator);
        $this->clock = Closure::fromCallable($clock);
        $this->transaction = $transaction === null
            ? static fn (callable $callback): mixed => $callback()
            : Closure::fromCallable($transaction);
    }

    public function submit(int $clientId, array $validatedInput): int
    {
        $now = ($this->clock)();

        return $this->applications->create(array_merge($validatedInput, [
            'client_id' => $clientId,
            'status' => OnboardingApplication::PENDING,
            'active_client_key' => 'client:' . $clientId,
            'secret_pending_display' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]));
    }

    public function approve(int $applicationId, int $templateId, int $adminId): array
    {
        return ($this->transaction)(function () use ($applicationId, $templateId, $adminId): array {
            $application = $this->applications->findPending($applicationId);
            if (!$application instanceof OnboardingApplication) {
                throw new InvalidArgumentException('Pending application not found.');
            }

            $template = $this->templates->find($templateId);
            if ($template === null || !$template->enabled()) {
                throw new InvalidArgumentException('Enabled policy template not found.');
            }

            $now = ($this->clock)();
            $publicKey = $this->generatedValue($this->publicKeyGenerator, 'public key');
            $secret = $this->generatedValue($this->secretGenerator, 'secret');
            $instanceId = $this->generatedValue($this->instanceIdGenerator, 'instance ID');
            $apiKeyId = $this->keys->create([
                'public_key' => $publicKey,
                'encrypted_secret' => (string) ($this->encryptSecret)($secret),
                'client_id' => $application->clientId(),
                'instance_id' => $instanceId,
                'enabled' => 1,
                'ip_allowlist_json' => $application->outboundIpsJson(),
                'rate_limit_per_minute' => max(1, $this->defaultRateLimit),
                'rate_window_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($template->policyRowsForApiKey($apiKeyId) as $policyRow) {
                $this->policies->save($policyRow);
            }

            $approved = $application->approve($apiKeyId, $templateId, $adminId, $now);
            $this->applications->save($approved);
            $this->audits->append([
                'event_type' => 'application_approved',
                'actor_type' => 'admin',
                'actor_id' => $adminId,
                'client_id' => $application->clientId(),
                'application_id' => $application->id(),
                'api_key_id' => $apiKeyId,
                'created_at' => $now,
                'context' => ['template_id' => $templateId],
            ]);
            $this->audits->append([
                'event_type' => 'api_key_created_from_application',
                'actor_type' => 'admin',
                'actor_id' => $adminId,
                'client_id' => $application->clientId(),
                'application_id' => $application->id(),
                'api_key_id' => $apiKeyId,
                'created_at' => $now,
                'context' => ['public_key' => $publicKey],
            ]);
            $this->audits->append([
                'event_type' => 'policy_template_applied',
                'actor_type' => 'admin',
                'actor_id' => $adminId,
                'client_id' => $application->clientId(),
                'application_id' => $application->id(),
                'api_key_id' => $apiKeyId,
                'created_at' => $now,
                'context' => ['template_id' => $templateId, 'item_count' => count($template->items())],
            ]);

            return ['api_key_id' => $apiKeyId, 'public_key' => $publicKey, 'secret' => $secret];
        });
    }

    public function reject(int $applicationId, string $message, int $adminId): void
    {
        $application = $this->applications->findPending($applicationId);
        if (!$application instanceof OnboardingApplication) {
            throw new InvalidArgumentException('Pending application not found.');
        }

        $now = ($this->clock)();
        $rejected = $application->reject($message, $adminId, $now);
        $this->applications->save($rejected);
        $this->audits->append([
            'event_type' => 'application_rejected',
            'actor_type' => 'admin',
            'actor_id' => $adminId,
            'client_id' => $application->clientId(),
            'application_id' => $application->id(),
            'created_at' => $now,
            'context' => ['message' => $message],
        ]);
    }

    private function generatedValue(Closure $generator, string $name): string
    {
        $value = (string) $generator();
        if ($value === '' || str_contains($value, "\0")) {
            throw new RuntimeException("Unable to generate a valid {$name}.");
        }

        return $value;
    }
}
