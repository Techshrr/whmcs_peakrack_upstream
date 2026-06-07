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

namespace PeakRack\UpstreamApi\ClientArea;

use Closure;
use InvalidArgumentException;
use PeakRack\UpstreamApi\Application\CredentialService;
use PeakRack\UpstreamApi\Application\EligibilityService;
use PeakRack\UpstreamApi\Application\OnboardingService;
use PeakRack\UpstreamApi\Application\OnboardingValidator;
use PeakRack\UpstreamApi\Application\SetupGuideService;
use PeakRack\UpstreamApi\Contracts\ApplicationRepository;
use PeakRack\UpstreamApi\Domain\OnboardingApplication;
use Throwable;

final class OnboardingClientAreaController
{
    private const CSRF_SESSION_KEY = 'peakrack_upstream_api_client_csrf';

    private readonly Closure $csrfGenerator;
    private readonly Closure $clock;

    public function __construct(
        private readonly ApplicationRepository $applications,
        private readonly EligibilityService $eligibility,
        private readonly OnboardingValidator $validator,
        private readonly OnboardingService $onboarding,
        private readonly CredentialService $credentials,
        private readonly SetupGuideService $guides,
        private readonly array $config = [],
        ?callable $csrfGenerator = null,
        ?callable $clock = null
    ) {
        $this->csrfGenerator = $csrfGenerator === null
            ? static fn (): string => bin2hex(random_bytes(16))
            : Closure::fromCallable($csrfGenerator);
        $this->clock = $clock === null
            ? static fn (): int => time()
            : Closure::fromCallable($clock);
    }

    public function dispatch(array $request, array &$session, string $sourceIp): array
    {
        $clientId = (int) ($session['uid'] ?? 0);
        if ($clientId < 1) {
            return [
                'state' => 'login_required',
                'requirelogin' => true,
            ];
        }

        $csrfToken = $this->csrfToken($session);
        $action = $this->scalarString($request['action'] ?? '');
        $secretOverride = null;
        $notice = null;
        $error = null;

        try {
            if ($action !== '') {
                $this->assertCsrf($session, $request['csrf_token'] ?? null);
                [$notice, $secretOverride] = $this->runAction($action, $clientId, $request, $sourceIp);
            }
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        } catch (Throwable) {
            $error = 'The integration request could not be completed.';
        }

        $view = $this->viewForClient($clientId, $secretOverride);
        $view['csrf_token'] = $csrfToken;
        $view['terms_url'] = $this->scalarString($this->config['terms_url'] ?? '');
        $view['reset_policy'] = 'API Secret reset limit: 3 resets per calendar month, with at least 10 days between client resets.';
        if ($notice !== null) {
            $view['notice'] = $notice;
        }
        if ($error !== null) {
            $view['error'] = $error;
        }

        return $view;
    }

    private function runAction(string $action, int $clientId, array $request, string $sourceIp): array
    {
        return match ($action) {
            'submit_application' => [$this->submitApplication($clientId, $request, $sourceIp), null],
            'confirm_secret_displayed' => [$this->confirmSecretDisplayed($clientId), null],
            'reset_secret' => $this->resetSecret($clientId, $sourceIp),
            default => throw new InvalidArgumentException('The requested integration action is invalid.'),
        };
    }

    private function submitApplication(int $clientId, array $request, string $sourceIp): string
    {
        $active = $this->applications->findActiveForClient($clientId);
        $eligibility = $this->eligibility->check($clientId, $active instanceof OnboardingApplication);
        if (($eligibility['eligible'] ?? false) !== true) {
            throw new InvalidArgumentException(implode(' ', (array) ($eligibility['reasons'] ?? [])));
        }

        $this->onboarding->submit(
            $clientId,
            $this->validator->validate($request, ($this->clock)(), $sourceIp)
        );

        return 'Integration application submitted.';
    }

    private function confirmSecretDisplayed(int $clientId): string
    {
        $this->credentials->confirmSecretDisplayed($clientId);

        return 'API Secret display confirmed.';
    }

    private function resetSecret(int $clientId, string $sourceIp): array
    {
        $result = $this->credentials->resetByClient($clientId, $sourceIp);

        return ['API Secret reset. Store the new value now.', (string) ($result['secret'] ?? '')];
    }

    private function viewForClient(int $clientId, ?string $secretOverride): array
    {
        $active = $this->applications->findActiveForClient($clientId);
        if ($active instanceof OnboardingApplication) {
            if ($active->status() === OnboardingApplication::PENDING) {
                return [
                    'state' => 'pending',
                    'application' => $active->toRow(),
                ];
            }

            if ($active->status() === OnboardingApplication::APPROVED) {
                return $this->approvedView($active, $clientId, $secretOverride);
            }
        }

        $rejected = $this->latestRejectedForClient($clientId);
        if ($rejected instanceof OnboardingApplication) {
            return [
                'state' => 'rejected',
                'application' => $rejected->toRow(),
            ];
        }

        $eligibility = $this->eligibility->check($clientId, false);
        if (($eligibility['eligible'] ?? false) !== true) {
            return [
                'state' => 'ineligible',
                'reasons' => (array) ($eligibility['reasons'] ?? []),
            ];
        }

        return ['state' => 'empty'];
    }

    private function approvedView(OnboardingApplication $application, int $clientId, ?string $secretOverride): array
    {
        $credentials = $this->credentials->keyDetailsForClient($clientId);
        if ($credentials === null) {
            return [
                'state' => 'approved',
                'application' => $application->toRow(),
                'guide' => $this->guides->build([
                    'download_url' => $this->scalarString($this->config['download_url'] ?? ''),
                    'api_base_url' => $this->scalarString($this->config['api_base_url'] ?? ''),
                    'public_key' => '',
                    'secret' => '',
                    'downstream_domain' => $application->downstreamDomain(),
                ]),
            ];
        }

        $secret = $secretOverride !== null && $secretOverride !== ''
            ? $secretOverride
            : (string) ($credentials['secret'] ?? '');

        return [
            'state' => 'approved',
            'application' => $application->toRow(),
            'secret_pending_display' => $secret !== '',
            'guide' => $this->guides->build([
                'download_url' => $this->scalarString($this->config['download_url'] ?? ''),
                'api_base_url' => $this->scalarString($this->config['api_base_url'] ?? ''),
                'public_key' => (string) ($credentials['public_key'] ?? ''),
                'secret' => $secret,
                'downstream_domain' => $application->downstreamDomain(),
            ]),
        ];
    }

    private function latestRejectedForClient(int $clientId): ?OnboardingApplication
    {
        foreach ($this->applications->listByStatus(OnboardingApplication::REJECTED, 100) as $row) {
            if (is_array($row)) {
                $application = OnboardingApplication::restore($row);
            } elseif ($row instanceof OnboardingApplication) {
                $application = $row;
            } else {
                continue;
            }

            if ($application->clientId() === $clientId) {
                return $application;
            }
        }

        return null;
    }

    private function csrfToken(array &$session): string
    {
        $current = $session[self::CSRF_SESSION_KEY] ?? null;
        if (is_string($current) && $current !== '') {
            return $current;
        }

        $token = (string) ($this->csrfGenerator)();
        if ($token === '') {
            $token = bin2hex(random_bytes(16));
        }
        $session[self::CSRF_SESSION_KEY] = $token;

        return $token;
    }

    private function assertCsrf(array $session, mixed $submitted): void
    {
        $expected = $session[self::CSRF_SESSION_KEY] ?? '';
        if (!is_string($expected) || !is_string($submitted) || !hash_equals($expected, $submitted)) {
            throw new InvalidArgumentException('The session token is invalid.');
        }
    }

    private function scalarString(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
