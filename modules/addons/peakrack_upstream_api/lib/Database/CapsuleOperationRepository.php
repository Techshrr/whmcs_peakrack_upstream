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

namespace PeakRack\UpstreamApi\Database;

use PeakRack\UpstreamApi\Contracts\OperationRepository;
use PeakRack\UpstreamApi\Domain\ApiError;
use PeakRack\UpstreamApi\Domain\Operation;
use PeakRack\UpstreamApi\Domain\ValidationException;
use Throwable;
use WHMCS\Database\Capsule;

final class CapsuleOperationRepository implements OperationRepository
{
    public function admit(Operation $operation, array $sanitizedPayload): Operation
    {
        try {
            return Capsule::connection()->transaction(function () use ($operation, $sanitizedPayload): Operation {
                $row = Capsule::table(Schema::OPERATIONS)
                    ->where('api_key_id', $operation->apiKeyId())
                    ->where('idempotency_key', $operation->idempotencyKey())
                    ->lockForUpdate()
                    ->first();

                if ($row !== null) {
                    return $this->validateReplay($this->hydrate((array) $row), $operation);
                }

                $now = time();
                Capsule::table(Schema::OPERATIONS)->insert([
                    'operation_id' => $operation->id(),
                    'api_key_id' => $operation->apiKeyId(),
                    'local_service_id' => $this->localServiceId($sanitizedPayload),
                    'action' => $operation->action(),
                    'idempotency_key' => $operation->idempotencyKey(),
                    'request_hash' => $operation->requestHash(),
                    'sanitized_payload_json' => $this->encode($sanitizedPayload),
                    'status' => $operation->status(),
                    'attempt_count' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                return $operation;
            });
        } catch (Throwable $exception) {
            // A concurrent request can win the unique-key race after the initial lookup.
            $existing = $this->findByIdempotency($operation->apiKeyId(), $operation->idempotencyKey());
            if ($existing === null) {
                throw $exception;
            }

            return $this->validateReplay($existing, $operation);
        }
    }

    public function findById(string $operationId): ?Operation
    {
        $row = Capsule::table(Schema::OPERATIONS)
            ->where('operation_id', $operationId)
            ->first();

        return $row === null ? null : $this->hydrate((array) $row);
    }

    public function findByIdempotency(int $apiKeyId, string $idempotencyKey): ?Operation
    {
        $row = Capsule::table(Schema::OPERATIONS)
            ->where('api_key_id', $apiKeyId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        return $row === null ? null : $this->hydrate((array) $row);
    }

    public function save(Operation $operation): void
    {
        $attributes = [
            'status' => $operation->status(),
            'result_json' => $this->encode($operation->result()),
            'last_error_code' => $operation->errorCode(),
            'last_error_message' => $operation->errorMessage(),
            'updated_at' => time(),
        ];

        if ($operation->isTerminal()) {
            $attributes['next_attempt_at'] = null;
            $attributes['locked_by'] = null;
            $attributes['locked_until'] = null;
        }

        Capsule::table(Schema::OPERATIONS)
            ->where('operation_id', $operation->id())
            ->update($attributes);
    }

    public function claimDue(int $limit, int $now, string $owner, int $lockUntil): array
    {
        if ($limit < 1) {
            return [];
        }

        return Capsule::connection()->transaction(function () use ($limit, $now, $owner, $lockUntil): array {
            $rows = Capsule::table(Schema::OPERATIONS)
                ->whereIn('status', [Operation::QUEUED, Operation::PROCESSING])
                ->where(static function ($query) use ($now): void {
                    $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now);
                })
                ->where(static function ($query) use ($now): void {
                    $query->whereNull('locked_until')
                        ->orWhere('locked_until', '<=', $now);
                })
                ->orderBy('created_at')
                ->limit($limit)
                ->lockForUpdate()
                ->get()
                ->all();

            $operations = [];
            foreach ($rows as $row) {
                $attributes = (array) $row;
                Capsule::table(Schema::OPERATIONS)
                    ->where('operation_id', $attributes['operation_id'])
                    ->update([
                        'locked_by' => $owner,
                        'locked_until' => $lockUntil,
                        'attempt_count' => ((int) $attributes['attempt_count']) + 1,
                        'updated_at' => $now,
                    ]);

                $operations[] = $this->hydrate($attributes);
            }

            return $operations;
        });
    }

    public function appendEvent(string $operationId, string $stage, array $sanitizedContext): void
    {
        Capsule::table(Schema::EVENTS)->insert([
            'operation_id' => $operationId,
            'stage' => $stage,
            'sanitized_context_json' => $this->encode($sanitizedContext),
            'created_at' => time(),
        ]);
    }

    private function validateReplay(Operation $existing, Operation $requested): Operation
    {
        if (!hash_equals($existing->requestHash(), $requested->requestHash())) {
            throw new ValidationException(
                ApiError::SERVICE_STATE_CONFLICT,
                'The idempotency key was already used with a different request.',
                409
            );
        }

        return $existing;
    }

    private function hydrate(array $row): Operation
    {
        return Operation::restore(
            (string) $row['operation_id'],
            (int) $row['api_key_id'],
            (string) $row['action'],
            (string) $row['idempotency_key'],
            (string) $row['request_hash'],
            (string) $row['status'],
            $this->decode($row['result_json'] ?? null),
            isset($row['last_error_code']) ? (string) $row['last_error_code'] : null,
            isset($row['last_error_message']) ? (string) $row['last_error_message'] : null
        );
    }

    private function localServiceId(array $payload): ?int
    {
        $value = $payload['local_service_id'] ?? null;
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            return null;
        }

        $serviceId = (int) $value;
        return $serviceId > 0 ? $serviceId : null;
    }

    private function decode(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function encode(array $value): string
    {
        return (string) json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }
}
