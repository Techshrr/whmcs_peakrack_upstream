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

namespace PeakRack\UpstreamApi\Application;

use InvalidArgumentException;
use JsonException;

final class OnboardingValidator
{
    public function __construct(private readonly bool $requireOutboundIp)
    {
    }

    /**
     * @throws JsonException
     */
    public function validate(array $request, int $now, string $sourceIp): array
    {
        return [
            'brand_name' => $this->boundedString($request, 'brand_name', 2, 120, 'The company or brand name is invalid.'),
            'downstream_domain' => $this->domain($request['downstream_domain'] ?? ''),
            'outbound_ips_json' => json_encode(
                $this->ipList((string) ($request['outbound_ips'] ?? '')),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            ),
            'business_type' => $this->boundedString($request, 'business_type', 2, 200, 'The business type is invalid.'),
            'telegram' => $this->telegram((string) ($request['telegram'] ?? '')),
            'qq' => $this->optionalPattern((string) ($request['qq'] ?? ''), '/^[0-9]{5,12}$/', 'The QQ contact is invalid.'),
            'phone' => $this->optionalPattern((string) ($request['phone'] ?? ''), '/^[+0-9 -]{1,32}$/', 'The contact phone is invalid.'),
            'notes' => $this->truncate(trim((string) ($request['notes'] ?? '')), 2000),
            'terms_accepted_at' => $this->termsAccepted($request['terms_accepted'] ?? null) ? $now : 0,
            'terms_accepted_ip' => $sourceIp,
        ];
    }

    private function boundedString(array $request, string $key, int $min, int $max, string $message): string
    {
        $value = trim((string) ($request[$key] ?? ''));
        $length = strlen($value);
        if ($length < $min || $length > $max) {
            throw new InvalidArgumentException($message);
        }

        return $value;
    }

    private function domain(mixed $value): string
    {
        $domain = strtolower(trim((string) $value));
        if (
            $domain === ''
            || str_contains($domain, '://')
            || preg_match('/[\/?#@:]/', $domain) === 1
            || strlen($domain) > 255
            || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $domain) !== 1
        ) {
            throw new InvalidArgumentException('The downstream WHMCS domain is invalid.');
        }

        return $domain;
    }

    private function ipList(string $input): array
    {
        $entries = preg_split('/[\r\n,]+/', $input) ?: [];
        $entries = array_values(array_unique(array_filter(array_map('trim', $entries))));
        if ($entries === []) {
            if ($this->requireOutboundIp) {
                throw new InvalidArgumentException('At least one outbound IP address is required.');
            }

            return [];
        }

        foreach ($entries as $entry) {
            [$address, $prefix] = array_pad(explode('/', $entry, 2), 2, null);
            $bytes = inet_pton($address);
            if ($bytes === false) {
                throw new InvalidArgumentException('The outbound IP list contains an invalid address.');
            }

            if ($prefix !== null) {
                $maximum = strlen($bytes) * 8;
                if (
                    preg_match('/^[0-9]{1,3}$/', $prefix) !== 1
                    || (int) $prefix > $maximum
                ) {
                    throw new InvalidArgumentException('The outbound IP list contains an invalid CIDR prefix.');
                }
            }
        }

        return $entries;
    }

    private function telegram(string $input): string
    {
        $value = trim($input);
        if (preg_match('~^https?://t\.me/([^/?#]+)~i', $value, $matches) === 1) {
            $value = $matches[1];
        } elseif (preg_match('~^t\.me/([^/?#]+)~i', $value, $matches) === 1) {
            $value = $matches[1];
        } elseif (str_starts_with($value, '@')) {
            $value = substr($value, 1);
        }

        if (preg_match('/^[A-Za-z0-9_]{5,64}$/', $value) !== 1) {
            throw new InvalidArgumentException('The Telegram contact is invalid.');
        }

        return '@' . $value;
    }

    private function optionalPattern(string $value, string $pattern, string $message): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match($pattern, $value) !== 1) {
            throw new InvalidArgumentException($message);
        }

        return $value;
    }

    private function termsAccepted(mixed $value): bool
    {
        $accepted = $value === true || $value === 1 || $value === '1' || $value === 'on';
        if (!$accepted) {
            throw new InvalidArgumentException('The integration terms must be accepted.');
        }

        return true;
    }

    private function truncate(string $value, int $length): string
    {
        if (function_exists('mb_substr')) {
            return (string) mb_substr($value, 0, $length);
        }

        return substr($value, 0, $length);
    }
}
