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

use LogicException;

final class Operation
{
    public const QUEUED = 'queued';
    public const PROCESSING = 'processing';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';
    public const MANUAL_REVIEW = 'manual_review';

    private function __construct(
        private readonly string $id,
        private readonly int $apiKeyId,
        private readonly string $action,
        private readonly string $idempotencyKey,
        private readonly string $requestHash,
        private readonly string $status,
        private readonly array $result = [],
        private readonly ?string $errorCode = null,
        private readonly ?string $errorMessage = null
    ) {
    }

    public static function admit(
        string $id,
        int $apiKeyId,
        string $action,
        string $idempotencyKey,
        string $requestHash
    ): self {
        return new self($id, $apiKeyId, $action, $idempotencyKey, $requestHash, self::QUEUED);
    }

    public function start(): self
    {
        $this->assertTransitionFrom([self::QUEUED]);
        return $this->withStatus(self::PROCESSING);
    }

    public function complete(array $result): self
    {
        $this->assertTransitionFrom([self::PROCESSING]);
        return $this->withStatus(self::COMPLETED, $result);
    }

    public function fail(string $code, string $message): self
    {
        $this->assertTransitionFrom([self::PROCESSING]);
        return $this->withStatus(self::FAILED, [], $code, $message);
    }

    public function manualReview(string $code, string $message): self
    {
        $this->assertTransitionFrom([self::PROCESSING]);
        return $this->withStatus(self::MANUAL_REVIEW, [], $code, $message);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function apiKeyId(): int
    {
        return $this->apiKeyId;
    }

    public function action(): string
    {
        return $this->action;
    }

    public function idempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function requestHash(): string
    {
        return $this->requestHash;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function result(): array
    {
        return $this->result;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    public function errorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::COMPLETED, self::FAILED, self::MANUAL_REVIEW], true);
    }

    private function assertTransitionFrom(array $allowed): void
    {
        if ($this->isTerminal()) {
            throw new LogicException('A terminal operation cannot transition.');
        }

        if (!in_array($this->status, $allowed, true)) {
            throw new LogicException(sprintf('Invalid operation transition from %s.', $this->status));
        }
    }

    private function withStatus(
        string $status,
        array $result = [],
        ?string $errorCode = null,
        ?string $errorMessage = null
    ): self {
        return new self(
            $this->id,
            $this->apiKeyId,
            $this->action,
            $this->idempotencyKey,
            $this->requestHash,
            $status,
            $result,
            $errorCode,
            $errorMessage
        );
    }
}
