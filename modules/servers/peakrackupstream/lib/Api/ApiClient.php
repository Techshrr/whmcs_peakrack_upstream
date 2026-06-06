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

namespace PeakRack\Upstream\Api;

use Closure;
use InvalidArgumentException;
use JsonException;
use PeakRack\Upstream\Config;
use PeakRack\Upstream\Logger;
use PeakRack\Upstream\Validator;
use RuntimeException;
use Throwable;

final class ApiClient implements ApiClientInterface
{
    private const OPERATION_STATUSES = ['queued', 'processing', 'completed', 'failed', 'manual_review'];

    private readonly string $baseUrl;
    private readonly string $basePath;
    private readonly int $timeout;
    private readonly Closure $transport;
    private readonly Logger $logger;
    private readonly Closure $clock;
    private readonly Closure $nonceGenerator;

    public function __construct(
        string $baseUrl,
        private readonly string $apiKey,
        private readonly string $apiSecret,
        int $timeout = 30,
        ?callable $transport = null,
        ?Logger $logger = null,
        ?callable $clock = null,
        ?callable $nonceGenerator = null
    ) {
        [$this->baseUrl, $this->basePath] = $this->normalizeBaseUrl($baseUrl);
        if (preg_match('/^[A-Za-z0-9._-]{1,128}$/', $apiKey) !== 1 || $apiSecret === '' || str_contains($apiSecret, "\0")) {
            throw new InvalidArgumentException('The upstream API credentials are invalid.');
        }

        $this->timeout = Validator::timeout($timeout);
        $this->transport = $transport === null
            ? Closure::fromCallable([$this, 'curlTransport'])
            : Closure::fromCallable($transport);
        $this->logger = $logger ?? new Logger();
        $this->clock = $clock === null ? static fn (): int => time() : Closure::fromCallable($clock);
        $this->nonceGenerator = $nonceGenerator === null
            ? static fn (): string => bin2hex(random_bytes(16))
            : Closure::fromCallable($nonceGenerator);
    }

    public function health(): array
    {
        return $this->request('GET', '/health');
    }

    public function catalog(): array
    {
        return $this->request('GET', '/catalog');
    }

    public function createService(array $payload, string $idempotencyKey): array
    {
        return $this->request('POST', '/services', $payload, $idempotencyKey);
    }

    public function getService(int $localServiceId): array
    {
        return $this->request('GET', '/services/' . Validator::positiveInt($localServiceId, 'local service ID'));
    }

    public function getOperation(string $operationId): array
    {
        return $this->request('GET', '/operations/' . Validator::operationId($operationId));
    }

    public function suspendService(int $localServiceId, string $idempotencyKey): array
    {
        return $this->serviceWrite($localServiceId, 'suspend', [], $idempotencyKey);
    }

    public function unsuspendService(int $localServiceId, string $idempotencyKey): array
    {
        return $this->serviceWrite($localServiceId, 'unsuspend', [], $idempotencyKey);
    }

    public function terminateService(int $localServiceId, string $mode, string $idempotencyKey): array
    {
        return $this->serviceWrite(
            $localServiceId,
            'terminate',
            ['mode' => Validator::terminateMode($mode)],
            $idempotencyKey
        );
    }

    public function renewService(int $localServiceId, string $renewalBoundary, string $idempotencyKey): array
    {
        return $this->serviceWrite(
            $localServiceId,
            'renew',
            ['renewal_boundary' => Validator::date($renewalBoundary, 'renewal boundary')],
            $idempotencyKey
        );
    }

    public function changePackage(int $localServiceId, array $payload, string $idempotencyKey): array
    {
        return $this->serviceWrite($localServiceId, 'change-package', $payload, $idempotencyKey);
    }

    public function getSsoUrl(int $localServiceId, string $idempotencyKey): array
    {
        return $this->serviceWrite($localServiceId, 'sso', [], $idempotencyKey);
    }

    private function serviceWrite(int $localServiceId, string $action, array $payload, string $idempotencyKey): array
    {
        return $this->request(
            'POST',
            '/services/' . Validator::positiveInt($localServiceId, 'local service ID') . '/' . $action,
            $payload,
            $idempotencyKey
        );
    }

    private function request(
        string $method,
        string $endpoint,
        ?array $payload = null,
        ?string $idempotencyKey = null
    ): array {
        if (
            $idempotencyKey !== null
            && preg_match('/^[A-Za-z0-9._:-]{1,' . Config::MAX_IDEMPOTENCY_KEY_LENGTH . '}$/', $idempotencyKey) !== 1
        ) {
            throw new InvalidArgumentException('The idempotency key is invalid.');
        }

        $body = $payload === null ? '' : $this->encodeObject($payload);
        $path = $this->basePath . $endpoint;
        $timestamp = (int) ($this->clock)();
        $nonce = (string) ($this->nonceGenerator)();
        if ($timestamp < 1 || preg_match('/^[A-Za-z0-9._:-]{1,' . Config::MAX_NONCE_LENGTH . '}$/', $nonce) !== 1) {
            throw new RuntimeException('Unable to generate valid API authentication values.');
        }

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-PeakRack-Key' => $this->apiKey,
            'X-PeakRack-Timestamp' => (string) $timestamp,
            'X-PeakRack-Nonce' => $nonce,
            'X-PeakRack-Signature' => RequestSigner::sign(
                $this->apiSecret,
                $method,
                $path,
                [],
                $body,
                $timestamp,
                $nonce
            ),
        ];
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $transportRequest = [
            'method' => $method,
            'url' => $this->baseUrl . $endpoint,
            'path' => $path,
            'query' => [],
            'headers' => $headers,
            'body' => $body,
            'options' => [
                'verify_peer' => true,
                'verify_host' => true,
                'follow_redirects' => false,
                'timeout' => $this->timeout,
            ],
        ];
        $action = $method . ' ' . $endpoint;

        try {
            $response = ($this->transport)($transportRequest);
        } catch (Throwable) {
            $this->logger->log($action, $transportRequest, ['transport_error' => true]);
            throw new ApiException('The upstream API request could not be completed.');
        }
        if (!is_array($response) || !is_int($response['status'] ?? null) || !is_string($response['body'] ?? null)) {
            $this->logger->log($action, $transportRequest, ['transport_response_invalid' => true]);
            throw new ApiException('The upstream API returned an invalid transport response.');
        }

        $decoded = $this->decodeResponse($response['body']);
        if ($decoded === null) {
            $this->logger->log($action, $transportRequest, [
                'http_status' => $response['status'],
                'invalid_json' => true,
            ]);
            throw new ApiException('The upstream API returned malformed JSON.', 'INVALID_RESPONSE', $response['status']);
        }
        $this->logger->log($action, $transportRequest, [
            'http_status' => $response['status'],
            'body' => $decoded,
        ]);

        return $this->validateEnvelope($decoded, $response['status']);
    }

    private function validateEnvelope(array $envelope, int $httpStatus): array
    {
        foreach (['success', 'status', 'data', 'operation_id', 'error'] as $key) {
            if (!array_key_exists($key, $envelope)) {
                throw new ApiException('The upstream API returned a mismatched response envelope.', 'INVALID_RESPONSE', $httpStatus);
            }
        }
        if (
            !is_bool($envelope['success'])
            || !is_string($envelope['status'])
            || !in_array($envelope['status'], self::OPERATION_STATUSES, true)
            || (!is_array($envelope['data']) && $envelope['data'] !== null)
            || (!is_string($envelope['operation_id']) && $envelope['operation_id'] !== null)
            || (is_string($envelope['operation_id']) && !$this->isOperationId($envelope['operation_id']))
            || (!is_array($envelope['error']) && $envelope['error'] !== null)
        ) {
            throw new ApiException('The upstream API returned a mismatched response envelope.', 'INVALID_RESPONSE', $httpStatus);
        }

        if ($envelope['success'] === true) {
            $validStatus = ($httpStatus === 200 && $envelope['status'] === 'completed')
                || ($httpStatus === 202 && in_array($envelope['status'], ['queued', 'processing'], true));
            if (
                !$validStatus
                || $envelope['error'] !== null
                || ($httpStatus === 202 && $envelope['operation_id'] === null)
            ) {
                throw new ApiException('The upstream API returned a mismatched response envelope.', 'INVALID_RESPONSE', $httpStatus);
            }

            return $envelope;
        }

        $error = $envelope['error'];
        if (
            $httpStatus < 400
            || !is_array($error)
            || !is_string($error['code'] ?? null)
            || !is_string($error['message'] ?? null)
            || $error['code'] === ''
            || $error['message'] === ''
            || $envelope['data'] !== null
        ) {
            throw new ApiException('The upstream API returned a mismatched response envelope.', 'INVALID_RESPONSE', $httpStatus);
        }

        throw new ApiException(
            $this->safeMessage($error['message']),
            $error['code'],
            $httpStatus,
            $envelope['operation_id'],
            $envelope['status']
        );
    }

    private function decodeResponse(string $body): ?array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) && !array_is_list($decoded) ? $decoded : null;
    }

    private function encodeObject(array $payload): string
    {
        if ($payload !== [] && array_is_list($payload)) {
            throw new InvalidArgumentException('The upstream API payload must be an object.');
        }

        return (string) json_encode(
            $payload === [] ? (object) [] : $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    private function normalizeBaseUrl(string $baseUrl): array
    {
        $parts = parse_url($baseUrl);
        if (
            !is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new InvalidArgumentException('The upstream API base URL must be a credential-free HTTPS URL.');
        }

        $host = Validator::serverHost((string) $parts['host']);
        $urlHost = str_contains($host, ':') ? '[' . $host . ']' : $host;
        $port = isset($parts['port']) ? Validator::port($parts['port']) : 443;
        $path = Validator::basePath((string) ($parts['path'] ?? ''));
        $url = 'https://' . $urlHost . ($port === 443 ? '' : ':' . $port) . $path;

        return [$url, $path];
    }

    private function safeMessage(string $message): string
    {
        $message = trim((string) preg_replace('/[\x00-\x1f\x7f]/', ' ', $message));
        if ($message === '') {
            return 'The upstream API rejected the request.';
        }

        return strlen($message) > 512 ? substr($message, 0, 512) : $message;
    }

    private function isOperationId(string $operationId): bool
    {
        return preg_match(
            '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89aAbB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/',
            $operationId
        ) === 1;
    }

    private function curlTransport(array $request): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is required.');
        }

        $handle = curl_init((string) $request['url']);
        if ($handle === false) {
            throw new RuntimeException('Unable to initialize cURL.');
        }

        $headers = [];
        foreach ((array) $request['headers'] as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        $options = [
            CURLOPT_CUSTOMREQUEST => (string) $request['method'],
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => min(15, (int) $request['options']['timeout']),
            CURLOPT_TIMEOUT => (int) $request['options']['timeout'],
            CURLOPT_NOSIGNAL => true,
        ];
        if ((string) $request['body'] !== '') {
            $options[CURLOPT_POSTFIELDS] = (string) $request['body'];
        }
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        curl_setopt_array($handle, $options);

        $body = curl_exec($handle);
        if (!is_string($body)) {
            throw new RuntimeException('The upstream API transport failed.');
        }

        return [
            'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
            'body' => $body,
        ];
    }
}
