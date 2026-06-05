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

final class Response
{
    private function __construct(
        private readonly int $statusCode,
        private readonly array $payload
    ) {
    }

    public static function success(
        array $data,
        string $status = 'completed',
        ?string $operationId = null,
        int $statusCode = 200
    ): self {
        return new self($statusCode, [
            'success' => true,
            'status' => $status,
            'data' => $data === [] ? null : $data,
            'operation_id' => $operationId,
            'error' => null,
        ]);
    }

    public static function error(
        string $code,
        string $message,
        int $statusCode,
        ?string $operationId = null,
        string $status = 'failed'
    ): self {
        return new self($statusCode, [
            'success' => false,
            'status' => $status,
            'data' => null,
            'operation_id' => $operationId,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ]);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function payload(): array
    {
        return $this->payload;
    }

    public function body(): string
    {
        return (string) json_encode(
            $this->payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }
}
