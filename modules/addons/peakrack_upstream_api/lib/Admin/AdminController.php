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

namespace PeakRack\UpstreamApi\Admin;

use Closure;
use InvalidArgumentException;
use JsonException;
use PeakRack\UpstreamApi\Contracts\ApiKeyRepository;
use PeakRack\UpstreamApi\Contracts\OperationRepository;
use PeakRack\UpstreamApi\Contracts\PolicyRepository;
use PeakRack\UpstreamApi\Domain\Operation;
use RuntimeException;

final class AdminController
{
    private const PAGES = [
        'dashboard',
        'api_keys',
        'product_policies',
        'operations',
        'services',
        'system_health',
    ];

    private readonly Closure $encryptSecret;
    private readonly Closure $policyValidator;
    private readonly Closure $keyUpdater;
    private readonly Closure $publicKeyGenerator;
    private readonly Closure $secretGenerator;
    private readonly Closure $instanceIdGenerator;
    private readonly Closure $pageReader;
    private readonly Closure $clock;
    private readonly ?Closure $clientValidator;

    public function __construct(
        private readonly ApiKeyRepository $keys,
        private readonly PolicyRepository $policies,
        private readonly OperationRepository $operations,
        private readonly Csrf $csrf,
        callable $encryptSecret,
        callable $policyValidator,
        callable $keyUpdater,
        callable $publicKeyGenerator,
        callable $secretGenerator,
        callable $instanceIdGenerator,
        callable $pageReader,
        callable $clock,
        ?callable $clientValidator = null
    ) {
        $this->encryptSecret = Closure::fromCallable($encryptSecret);
        $this->policyValidator = Closure::fromCallable($policyValidator);
        $this->keyUpdater = Closure::fromCallable($keyUpdater);
        $this->publicKeyGenerator = Closure::fromCallable($publicKeyGenerator);
        $this->secretGenerator = Closure::fromCallable($secretGenerator);
        $this->instanceIdGenerator = Closure::fromCallable($instanceIdGenerator);
        $this->pageReader = Closure::fromCallable($pageReader);
        $this->clock = Closure::fromCallable($clock);
        $this->clientValidator = $clientValidator === null ? null : Closure::fromCallable($clientValidator);
    }

    public function dispatch(array $request, ?int $adminId, array &$session, string $moduleLink): array
    {
        if ($adminId === null || $adminId < 1) {
            throw new RuntimeException('An authenticated administrator is required.');
        }

        $page = (string) ($request['page'] ?? 'dashboard');
        if (!in_array($page, self::PAGES, true)) {
            $page = 'dashboard';
        }

        $notice = null;
        $secretOnce = null;
        $action = (string) ($request['action'] ?? '');
        if ($action !== '') {
            $this->csrf->assertValid($session, $request['csrf_token'] ?? null);
            [$notice, $secretOnce] = $this->runAction($action, $request, $adminId);
        }

        $data = $page === 'api_keys'
            ? $this->safeKeyList()
            : ($this->pageReader)($page);

        return [
            'page' => $page,
            'modulelink' => $moduleLink,
            'csrf_token' => $this->csrf->token($session),
            'notice' => $notice,
            'secret_once' => $secretOnce,
            'data' => is_array($data) ? $data : [],
        ];
    }

    private function runAction(string $action, array $request, int $adminId): array
    {
        return match ($action) {
            'create_key' => $this->createKey($request),
            'rotate_key' => $this->rotateKey($request),
            'set_key_enabled' => $this->setKeyEnabled($request),
            'update_key_settings' => $this->updateKeySettings($request),
            'delete_key' => $this->deleteKey($request),
            'save_policy' => $this->savePolicy($request),
            'retry_operation' => $this->retryOperation($request, $adminId),
            default => throw new InvalidArgumentException('The requested administrator action is invalid.'),
        };
    }

    private function createKey(array $request): array
    {
        $clientId = $this->positiveInt($request, 'client_id');
        if ($this->clientValidator !== null) {
            ($this->clientValidator)($clientId);
        }
        $rateLimit = $this->positiveInt($request, 'rate_limit_per_minute');
        if ($rateLimit > 10000) {
            throw new InvalidArgumentException('The rate limit is too large.');
        }

        $publicKey = $this->generatedValue($this->publicKeyGenerator, 'public key');
        $secret = $this->generatedValue($this->secretGenerator, 'secret');
        $instanceId = $this->generatedValue($this->instanceIdGenerator, 'instance ID');
        $ipAllowlist = $this->ipAllowlist(
            (string) ($request['ip_allowlist'] ?? ''),
            $this->boolean($request['acknowledge_empty_ip_allowlist'] ?? null)
        );
        $now = ($this->clock)();
        $this->keys->create([
            'public_key' => $publicKey,
            'encrypted_secret' => (string) ($this->encryptSecret)($secret),
            'client_id' => $clientId,
            'instance_id' => $instanceId,
            'enabled' => 1,
            'ip_allowlist_json' => $this->encode($ipAllowlist),
            'rate_limit_per_minute' => $rateLimit,
            'rate_window_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['API key created. Store the secret now; it will not be shown again.', $secret];
    }

    private function rotateKey(array $request): array
    {
        $keyId = $this->positiveInt($request, 'key_id');
        $this->assertKeyExists($keyId);

        $secret = $this->generatedValue($this->secretGenerator, 'secret');
        $this->keys->updateEncryptedSecret($keyId, (string) ($this->encryptSecret)($secret));
        return ['API secret rotated. Store the new secret now.', $secret];
    }

    private function setKeyEnabled(array $request): array
    {
        $keyId = $this->positiveInt($request, 'key_id');
        $this->assertKeyExists($keyId);
        $enabled = $this->boolean($request['enabled'] ?? null);
        ($this->keyUpdater)($keyId, ['enabled' => $enabled ? 1 : 0]);
        return [$enabled ? 'API key enabled.' : 'API key disabled.', null];
    }

    private function updateKeySettings(array $request): array
    {
        $keyId = $this->positiveInt($request, 'key_id');
        $this->assertKeyExists($keyId);
        $rateLimit = $this->positiveInt($request, 'rate_limit_per_minute');
        if ($rateLimit > 10000) {
            throw new InvalidArgumentException('The rate limit is too large.');
        }

        $ipAllowlist = $this->ipAllowlist(
            (string) ($request['ip_allowlist'] ?? ''),
            $this->boolean($request['acknowledge_empty_ip_allowlist'] ?? null)
        );
        ($this->keyUpdater)($keyId, [
            'ip_allowlist_json' => $this->encode($ipAllowlist),
            'rate_limit_per_minute' => $rateLimit,
        ]);

        return ['API key allowlist and rate limit updated.', null];
    }

    private function deleteKey(array $request): array
    {
        $keyId = $this->positiveInt($request, 'key_id');
        $this->assertKeyExists($keyId);
        if ($this->keys->hasServices($keyId)) {
            ($this->keyUpdater)($keyId, ['enabled' => 0]);
            return ['The API key has bound services and was disabled instead of deleted.', null];
        }

        $this->keys->delete($keyId);
        return ['API key deleted.', null];
    }

    private function savePolicy(array $request): array
    {
        $attributes = [
            'api_key_id' => $this->positiveInt($request, 'api_key_id'),
            'product_id' => $this->positiveInt($request, 'product_id'),
            'enabled' => $this->boolean($request['enabled'] ?? '1') ? 1 : 0,
            'billing_cycles' => $this->stringList($request, 'billing_cycles'),
            'actions' => $this->stringList($request, 'actions'),
            'locations' => $this->jsonArray($request, 'locations', true),
            'os_templates' => $this->jsonArray($request, 'os_templates', true),
            'delivery_mappings' => $this->jsonArray($request, 'delivery_mappings', true),
            'sso_hosts' => $this->stringList($request, 'sso_hosts'),
            'sso_allowed' => $this->boolean($request['sso_allowed'] ?? null) ? 1 : 0,
            'destroy_allowed' => $this->boolean($request['destroy_allowed'] ?? null) ? 1 : 0,
        ];
        if ($attributes['billing_cycles'] === [] || $attributes['actions'] === []) {
            throw new InvalidArgumentException('A product policy requires at least one billing cycle and action.');
        }
        if ($attributes['sso_allowed'] === 1 && $attributes['sso_hosts'] === []) {
            throw new InvalidArgumentException('An SSO-enabled policy requires at least one allowed host.');
        }
        if (isset($request['policy_id']) && (string) $request['policy_id'] !== '') {
            $attributes['id'] = $this->positiveInt($request, 'policy_id');
        }

        ($this->policyValidator)($attributes);
        $this->policies->save($attributes);
        return ['Product policy saved.', null];
    }

    private function retryOperation(array $request, int $adminId): array
    {
        $operationId = (string) ($request['operation_id'] ?? '');
        $operation = $this->operations->findById($operationId);
        if (!$operation instanceof Operation || $operation->status() !== Operation::MANUAL_REVIEW) {
            throw new InvalidArgumentException('Only a manual-review operation can be retried.');
        }

        $retry = $operation->retryManualReview((int) ($this->clock)());
        $this->operations->save($retry);
        $this->operations->appendEvent($operationId, 'admin_retry', ['admin_id' => $adminId]);
        return ['Manual-review operation scheduled for verification.', null];
    }

    private function safeKeyList(): array
    {
        return array_map(static function (array $key): array {
            unset($key['encrypted_secret'], $key['secret_once']);
            return $key;
        }, $this->keys->all());
    }

    private function ipAllowlist(string $input, bool $emptyAcknowledged): array
    {
        $entries = preg_split('/[\r\n,]+/', $input) ?: [];
        $entries = array_values(array_unique(array_filter(array_map('trim', $entries))));
        if ($entries === [] && !$emptyAcknowledged) {
            throw new InvalidArgumentException('An empty IP allowlist requires explicit administrator acknowledgement.');
        }
        foreach ($entries as $entry) {
            [$address, $prefix] = array_pad(explode('/', $entry, 2), 2, null);
            $bytes = inet_pton($address);
            if ($bytes === false) {
                throw new InvalidArgumentException('The IP allowlist contains an invalid address.');
            }
            if ($prefix !== null) {
                $maximum = strlen($bytes) * 8;
                if (preg_match('/^[0-9]{1,3}$/', $prefix) !== 1 || (int) $prefix > $maximum) {
                    throw new InvalidArgumentException('The IP allowlist contains an invalid CIDR prefix.');
                }
            }
        }

        return $entries;
    }

    private function assertKeyExists(int $keyId): void
    {
        if ($this->keys->find($keyId) === null) {
            throw new InvalidArgumentException('API key not found.');
        }
    }

    private function stringList(array $request, string $key): array
    {
        $value = $request[$key] ?? null;
        if (is_array($value)) {
            return $this->normalizeStringList($value, $key);
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException("The {$key} value must be a list.");
        }

        $value = trim($this->normalizeSubmittedString($value));
        if ($value === '') {
            return [];
        }

        $decoded = $this->decodeJsonArray($value, $key, false);
        if ($decoded !== null) {
            return $this->normalizeStringList($decoded, $key);
        }

        return $this->normalizeStringList(preg_split('/[\r\n,]+/', $value) ?: [], $key);
    }

    private function jsonArray(array $request, string $key, bool $allowEmptyString = false): array
    {
        $value = $request[$key] ?? null;
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException("The {$key} value must be JSON.");
        }

        $value = trim($this->normalizeSubmittedString($value));
        if ($value === '' && $allowEmptyString) {
            return [];
        }

        return $this->decodeJsonArray($value, $key, true) ?? [];
    }

    private function decodeJsonArray(string $value, string $key, bool $required): ?array
    {
        $candidates = [$value];
        $stripped = stripslashes($value);
        if ($stripped !== $value) {
            $candidates[] = $stripped;
        }

        foreach (array_values(array_unique($candidates)) as $candidate) {
            if (trim($candidate) === '') {
                continue;
            }

            try {
                $decoded = json_decode($candidate, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            if (!is_array($decoded)) {
                throw new InvalidArgumentException("The {$key} value must decode to an array or object.");
            }

            return $decoded;
        }

        if ($required || preg_match('/^\s*[\[{]/', $value) === 1) {
            throw new InvalidArgumentException("The {$key} value must contain valid JSON.");
        }

        return null;
    }

    private function normalizeSubmittedString(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function normalizeStringList(array $values, string $key): array
    {
        $normalized = [];
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                throw new InvalidArgumentException("The {$key} value must contain only scalar list entries.");
            }

            $entry = trim((string) $value);
            if ($entry !== '') {
                $normalized[] = $entry;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function positiveInt(array $source, string $key): int
    {
        $value = $source[$key] ?? null;
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new InvalidArgumentException("The {$key} value must be a positive integer.");
        }

        $integer = (int) $value;
        if ($integer < 1) {
            throw new InvalidArgumentException("The {$key} value must be a positive integer.");
        }

        return $integer;
    }

    private function boolean(mixed $value): bool
    {
        return in_array($value, [1, '1', true, 'true', 'on', 'yes'], true);
    }

    private function generatedValue(Closure $generator, string $name): string
    {
        $value = (string) $generator();
        if ($value === '' || str_contains($value, "\0")) {
            throw new RuntimeException("Unable to generate a valid {$name}.");
        }

        return $value;
    }

    private function encode(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
