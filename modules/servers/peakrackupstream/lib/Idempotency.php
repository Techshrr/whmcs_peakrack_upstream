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

namespace PeakRack\Upstream;

use Closure;
use JsonException;
use RuntimeException;

final class Idempotency
{
    private readonly Closure $randomGenerator;

    public function __construct(
        private readonly ServiceProperties $properties,
        ?callable $randomGenerator = null
    ) {
        $this->randomGenerator = $randomGenerator === null
            ? static fn (): string => bin2hex(random_bytes(16))
            : Closure::fromCallable($randomGenerator);
    }

    public function create(int $localServiceId): string
    {
        return $this->claimExact('service:create:' . Validator::positiveInt($localServiceId, 'local service ID'));
    }

    public function lifecycle(string $action, int $localServiceId): string
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,63}$/', $action) !== 1) {
            throw new RuntimeException('The lifecycle action is invalid.');
        }

        $prefix = 'service:' . $action . ':' . Validator::positiveInt($localServiceId, 'local service ID') . ':';
        $current = $this->current();
        if ($current !== null) {
            if (str_starts_with($current, $prefix)) {
                return $current;
            }

            throw new RuntimeException('Another upstream operation is still pending.');
        }

        $random = (string) ($this->randomGenerator)();
        if (preg_match('/^[A-Za-z0-9._-]{1,64}$/', $random) !== 1) {
            throw new RuntimeException('Unable to generate an idempotency key.');
        }

        return $this->persist($prefix . $random);
    }

    public function renewal(int $localServiceId, string $boundary): string
    {
        return $this->claimExact(
            'service:renew:'
            . Validator::positiveInt($localServiceId, 'local service ID')
            . ':'
            . Validator::date($boundary, 'renewal boundary')
        );
    }

    public function changePackage(int $localServiceId, array $payload): string
    {
        $normalized = $this->normalize($payload);
        try {
            $json = json_encode(
                $normalized,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            throw new RuntimeException('Unable to calculate the package-change idempotency key.');
        }

        return $this->claimExact(
            'service:change_package:'
            . Validator::positiveInt($localServiceId, 'local service ID')
            . ':'
            . hash('sha256', (string) $json)
        );
    }

    public function clearIfMatches(string $key): void
    {
        if ($this->current() === $key) {
            $this->properties->save([ServiceProperties::CURRENT_IDEMPOTENCY_KEY => '']);
        }
    }

    public function current(): ?string
    {
        return $this->properties->get(ServiceProperties::CURRENT_IDEMPOTENCY_KEY);
    }

    private function claimExact(string $key): string
    {
        $current = $this->current();
        if ($current === $key) {
            return $current;
        }
        if ($current !== null) {
            throw new RuntimeException('Another upstream operation is still pending.');
        }

        return $this->persist($key);
    }

    private function persist(string $key): string
    {
        if (preg_match('/^[A-Za-z0-9._:-]{1,191}$/', $key) !== 1) {
            throw new RuntimeException('The generated idempotency key is invalid.');
        }
        $this->properties->save([ServiceProperties::CURRENT_IDEMPOTENCY_KEY => $key]);
        return $key;
    }

    private function normalize(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed => is_array($item) ? $this->normalize($item) : $item,
                $value
            );
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->normalize($item);
            }
        }

        return $value;
    }
}
