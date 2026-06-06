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

namespace PeakRack\UpstreamApi\Domain;

final class OperationResult
{
    private function __construct(
        private readonly bool $success,
        private readonly string $status,
        private readonly array $data,
        private readonly ?string $operationId,
        private readonly ?array $error
    ) {
    }

    public static function completed(array $data, ?string $operationId = null): self
    {
        return new self(true, Operation::COMPLETED, $data, $operationId, null);
    }

    public static function processing(array $data, string $operationId): self
    {
        return new self(true, Operation::PROCESSING, $data, $operationId, null);
    }

    public static function failed(string $code, string $message, ?string $operationId = null): self
    {
        return new self(false, Operation::FAILED, [], $operationId, [
            'code' => $code,
            'message' => $message,
        ]);
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'status' => $this->status,
            'data' => $this->data === [] ? null : $this->data,
            'operation_id' => $this->operationId,
            'error' => $this->error,
        ];
    }
}
