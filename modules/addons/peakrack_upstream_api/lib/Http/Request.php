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

namespace PeakRack\UpstreamApi\Http;

use InvalidArgumentException;
use JsonException;
use PeakRack\UpstreamApi\Config;

final class Request
{
    private readonly string $method;
    private readonly array $headers;
    private readonly string $signaturePath;

    public function __construct(
        string $method,
        private readonly string $path,
        private readonly array $query,
        array $headers,
        private readonly string $rawBody,
        private readonly string $sourceIp,
        private readonly bool $secure = true,
        ?string $signaturePath = null
    ) {
        $this->method = strtoupper($method);
        $this->headers = $this->normalizeHeaders($headers);
        $this->signaturePath = $signaturePath ?? $path;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function signaturePath(): string
    {
        return $this->signaturePath;
    }

    public function query(): array
    {
        return $this->query;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    public function sourceIp(): string
    {
        return $this->sourceIp;
    }

    public function isSecure(): bool
    {
        return $this->secure;
    }

    public function json(array $allowedFields, array $requiredFields = []): array
    {
        if (strlen($this->rawBody) > Config::MAX_REQUEST_BODY_BYTES) {
            throw new InvalidArgumentException('The JSON request body exceeds the documented maximum size.');
        }

        $contentType = strtolower(trim((string) $this->header('content-type')));
        if (!preg_match('/^application\/json(?:\s*;\s*charset=utf-8)?$/', $contentType)) {
            throw new InvalidArgumentException('The request Content-Type must be application/json.');
        }

        try {
            $root = json_decode($this->rawBody, false, 512, JSON_THROW_ON_ERROR);
            $decoded = json_decode($this->rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('The request body must contain valid JSON.');
        }

        if (!is_object($root) || !is_array($decoded)) {
            throw new InvalidArgumentException('The request body must be a JSON object.');
        }

        $unknown = array_values(array_diff(array_keys($decoded), $allowedFields));
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown JSON field: ' . (string) $unknown[0]);
        }

        foreach ($requiredFields as $requiredField) {
            if (!array_key_exists($requiredField, $decoded)) {
                throw new InvalidArgumentException("The required JSON field {$requiredField} is missing.");
            }
        }

        return $decoded;
    }

    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            if (is_array($value)) {
                $value = implode(',', array_map('strval', $value));
            }

            if (is_scalar($value)) {
                $normalized[strtolower((string) $name)] = trim((string) $value);
            }
        }

        return $normalized;
    }
}
