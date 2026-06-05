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

namespace PeakRack\UpstreamApi\Security;

use Closure;
use PeakRack\UpstreamApi\Config;
use PeakRack\UpstreamApi\Contracts\ApiKeyRepository;
use PeakRack\UpstreamApi\Contracts\Clock;
use PeakRack\UpstreamApi\Contracts\NonceRepository;
use PeakRack\UpstreamApi\Domain\ApiError;
use PeakRack\UpstreamApi\Domain\ValidationException;
use PeakRack\UpstreamApi\Http\Request;
use Throwable;

final class Authenticator
{
    private readonly Closure $decryptSecret;

    public function __construct(
        private readonly ApiKeyRepository $apiKeys,
        private readonly NonceRepository $nonces,
        private readonly Clock $clock,
        private readonly RateLimiter $rateLimiter,
        callable $decryptSecret
    ) {
        $this->decryptSecret = Closure::fromCallable($decryptSecret);
    }

    public function authenticate(Request $request): array
    {
        [$publicKey, $timestamp, $nonce, $signature] = $this->validateHeaders($request);

        $apiKey = $this->apiKeys->findEnabledByPublicKey($publicKey);
        if ($apiKey === null) {
            throw $this->authenticationFailure();
        }

        if (!$this->isIpAllowed($request->sourceIp(), $apiKey['ip_allowlist_json'] ?? null)) {
            throw new ValidationException(ApiError::AUTHENTICATION_FAILED, 'The source IP is not allowed.', 403);
        }

        $now = $this->clock->now();
        if (abs($now - $timestamp) > Config::SIGNATURE_TOLERANCE_SECONDS) {
            throw new ValidationException(ApiError::SIGNATURE_EXPIRED, 'The request timestamp is outside the allowed window.', 401);
        }

        try {
            $secret = ($this->decryptSecret)((string) $apiKey['encrypted_secret']);
            if (!is_string($secret) || $secret === '') {
                throw new \RuntimeException('The decrypted API secret is invalid.');
            }
        } catch (Throwable) {
            throw $this->authenticationFailure();
        }

        $expected = RequestSigner::sign(
            $secret,
            $request->method(),
            $request->signaturePath(),
            $request->query(),
            $request->rawBody(),
            $timestamp,
            $nonce
        );
        if (!hash_equals($expected, $signature)) {
            throw $this->authenticationFailure();
        }

        $apiKeyId = (int) $apiKey['id'];
        if (!$this->nonces->claim($apiKeyId, $nonce, $now + Config::SIGNATURE_TOLERANCE_SECONDS + 1)) {
            throw new ValidationException(ApiError::NONCE_REUSED, 'The request nonce has already been used.', 401);
        }

        if (!$this->rateLimiter->allow($apiKeyId, $now)) {
            throw new ValidationException(ApiError::RATE_LIMITED, 'The API key rate limit was exceeded.', 429);
        }

        $this->apiKeys->updateUsage($apiKeyId, $request->sourceIp(), $now);

        return $apiKey;
    }

    private function validateHeaders(Request $request): array
    {
        $publicKey = (string) $request->header('x-peakrack-key');
        $timestamp = (string) $request->header('x-peakrack-timestamp');
        $nonce = (string) $request->header('x-peakrack-nonce');
        $signature = (string) $request->header('x-peakrack-signature');
        $idempotencyKey = (string) $request->header('idempotency-key');

        if (
            preg_match('/^[A-Za-z0-9._-]{1,128}$/', $publicKey) !== 1
            || preg_match('/^[0-9]{1,12}$/', $timestamp) !== 1
            || preg_match('/^[A-Za-z0-9._:-]{1,191}$/', $nonce) !== 1
            || preg_match('/^[0-9a-f]{64}$/', $signature) !== 1
            || ($request->method() !== 'GET' && preg_match('/^[A-Za-z0-9._:-]{1,191}$/', $idempotencyKey) !== 1)
        ) {
            throw new ValidationException(ApiError::AUTHENTICATION_FAILED, 'The authentication headers are invalid.', 400);
        }

        return [$publicKey, (int) $timestamp, $nonce, $signature];
    }

    private function authenticationFailure(): ValidationException
    {
        return new ValidationException(ApiError::AUTHENTICATION_FAILED, 'Authentication failed.', 401);
    }

    private function isIpAllowed(string $sourceIp, mixed $allowlistJson): bool
    {
        if ($allowlistJson === null || $allowlistJson === '') {
            return true;
        }

        $allowlist = json_decode((string) $allowlistJson, true);
        if (!is_array($allowlist)) {
            return false;
        }

        if ($allowlist === []) {
            return true;
        }

        foreach ($allowlist as $allowedRange) {
            if (is_string($allowedRange) && $this->containsIp($allowedRange, $sourceIp)) {
                return true;
            }
        }

        return false;
    }

    private function containsIp(string $allowedRange, string $sourceIp): bool
    {
        [$network, $prefix] = array_pad(explode('/', $allowedRange, 2), 2, null);
        $networkBytes = inet_pton($network);
        $sourceBytes = inet_pton($sourceIp);

        if ($networkBytes === false || $sourceBytes === false || strlen($networkBytes) !== strlen($sourceBytes)) {
            return false;
        }

        $maximumPrefix = strlen($networkBytes) * 8;
        if ($prefix === null) {
            $prefixLength = $maximumPrefix;
        } elseif (preg_match('/^[0-9]{1,3}$/', $prefix) === 1) {
            $prefixLength = (int) $prefix;
        } else {
            return false;
        }

        if ($prefixLength < 0 || $prefixLength > $maximumPrefix) {
            return false;
        }

        $wholeBytes = intdiv($prefixLength, 8);
        if (substr($networkBytes, 0, $wholeBytes) !== substr($sourceBytes, 0, $wholeBytes)) {
            return false;
        }

        $remainingBits = $prefixLength % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xff << (8 - $remainingBits)) & 0xff;
        return (ord($networkBytes[$wholeBytes]) & $mask) === (ord($sourceBytes[$wholeBytes]) & $mask);
    }
}
