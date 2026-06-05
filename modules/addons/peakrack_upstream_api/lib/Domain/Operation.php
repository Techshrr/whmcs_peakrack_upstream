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
        private readonly ?string $errorMessage = null,
        private readonly ?int $localServiceId = null,
        private readonly array $sanitizedPayload = [],
        private readonly ?string $stage = null,
        private readonly int $attemptCount = 0,
        private readonly ?int $nextAttemptAt = null,
        private readonly array $executionPayload = []
    ) {
    }

    public static function admit(
        string $id,
        int $apiKeyId,
        string $action,
        string $idempotencyKey,
        string $requestHash,
        ?int $localServiceId = null,
        array $sanitizedPayload = [],
        ?array $executionPayload = null
    ): self {
        return new self(
            $id,
            $apiKeyId,
            $action,
            $idempotencyKey,
            $requestHash,
            self::QUEUED,
            [],
            null,
            null,
            $localServiceId,
            $sanitizedPayload,
            'accepted',
            0,
            null,
            $executionPayload ?? $sanitizedPayload
        );
    }

    public static function restore(
        string $id,
        int $apiKeyId,
        string $action,
        string $idempotencyKey,
        string $requestHash,
        string $status,
        array $result = [],
        ?string $errorCode = null,
        ?string $errorMessage = null,
        ?int $localServiceId = null,
        array $sanitizedPayload = [],
        ?string $stage = null,
        int $attemptCount = 0,
        ?int $nextAttemptAt = null,
        ?array $executionPayload = null
    ): self {
        return new self(
            $id,
            $apiKeyId,
            $action,
            $idempotencyKey,
            $requestHash,
            $status,
            $result,
            $errorCode,
            $errorMessage,
            $localServiceId,
            $sanitizedPayload,
            $stage,
            $attemptCount,
            $nextAttemptAt,
            $executionPayload ?? $sanitizedPayload
        );
    }

    public function start(): self
    {
        $this->assertTransitionFrom([self::QUEUED]);
        return $this->withStatus(self::PROCESSING, $this->result);
    }

    public function resumeVerification(): self
    {
        $this->assertTransitionFrom([self::PROCESSING]);
        return $this->withStatus(self::PROCESSING, $this->result, null, null, $this->stage, null);
    }

    public function advance(string $stage, array $result = []): self
    {
        $this->assertTransitionFrom([self::PROCESSING]);
        return $this->withStatus(
            self::PROCESSING,
            array_replace($this->result, $result),
            null,
            null,
            $stage,
            null
        );
    }

    public function defer(string $stage, int $nextAttemptAt, array $result = []): self
    {
        $this->assertTransitionFrom([self::PROCESSING]);
        return $this->withStatus(
            self::PROCESSING,
            array_replace($this->result, $result),
            null,
            null,
            $stage,
            $nextAttemptAt
        );
    }

    public function complete(array $result): self
    {
        $this->assertTransitionFrom([self::PROCESSING]);
        return $this->withStatus(
            self::COMPLETED,
            array_replace($this->result, $result),
            null,
            null,
            'completed',
            null
        );
    }

    public function fail(string $code, string $message): self
    {
        $this->assertTransitionFrom([self::PROCESSING]);
        return $this->withStatus(self::FAILED, $this->result, $code, $message, 'failed', null);
    }

    public function manualReview(string $code, string $message): self
    {
        $this->assertTransitionFrom([self::PROCESSING]);
        return $this->withStatus(self::MANUAL_REVIEW, $this->result, $code, $message, 'manual_review', null);
    }

    public function retry(string $code, string $message, int $nextAttemptAt): self
    {
        $this->assertTransitionFrom([self::QUEUED, self::PROCESSING]);
        return $this->withStatus(self::QUEUED, $this->result, $code, $message, 'retry', $nextAttemptAt);
    }

    public function awaitVerification(string $code, string $message, int $nextAttemptAt): self
    {
        $this->assertTransitionFrom([self::PROCESSING]);
        return $this->withStatus(self::PROCESSING, $this->result, $code, $message, 'verify', $nextAttemptAt);
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

    public function localServiceId(): ?int
    {
        return $this->localServiceId;
    }

    public function sanitizedPayload(): array
    {
        return $this->sanitizedPayload;
    }

    public function executionPayload(): array
    {
        return $this->executionPayload;
    }

    public function stage(): ?string
    {
        return $this->stage;
    }

    public function attemptCount(): int
    {
        return $this->attemptCount;
    }

    public function nextAttemptAt(): ?int
    {
        return $this->nextAttemptAt;
    }

    public function requiresVerification(): bool
    {
        return $this->stage === 'verify';
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
        ?string $errorMessage = null,
        ?string $stage = null,
        ?int $nextAttemptAt = null
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
            $errorMessage,
            $this->localServiceId,
            $this->sanitizedPayload,
            $stage ?? $this->stage,
            $this->attemptCount,
            $nextAttemptAt,
            $this->executionPayload
        );
    }
}
