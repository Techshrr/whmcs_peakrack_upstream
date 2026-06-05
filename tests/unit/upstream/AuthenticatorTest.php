<?php

namespace PeakRack\Tests\Unit\Upstream;

use PeakRack\Tests\TestCase;
use PeakRack\UpstreamApi\Contracts\ApiKeyRepository;
use PeakRack\UpstreamApi\Contracts\Clock;
use PeakRack\UpstreamApi\Contracts\NonceRepository;
use PeakRack\UpstreamApi\Domain\ValidationException;
use PeakRack\UpstreamApi\Http\Request;
use PeakRack\UpstreamApi\Security\Authenticator;
use PeakRack\UpstreamApi\Security\RateLimiter;
use PeakRack\UpstreamApi\Security\RequestSigner;
use RuntimeException;

final class AuthenticatorTest extends TestCase
{
    private const NOW = 1780617600;
    private const SECRET = 'test-secret';

    public function testAuthenticatesValidSignedRequestAndUpdatesUsage(): void
    {
        [$authenticator, $keys, $nonces] = $this->authenticator();
        $request = $this->signedRequest('nonce-1');

        $authenticated = $authenticator->authenticate($request);

        $this->assertSame(7, $authenticated['id']);
        $this->assertSame(['id' => 7, 'ip' => '192.0.2.10', 'at' => self::NOW], $keys->lastUsage);
        $this->assertSame(1, count($nonces->claimed));
    }

    public function testAuthenticatesTheFullRequestPathWhileRoutingOnTheRelativePath(): void
    {
        [$authenticator] = $this->authenticator();
        $request = $this->signedRequest(
            'nonce-full-path',
            self::NOW,
            self::SECRET,
            '192.0.2.10',
            '/billing/modules/addons/peakrack_upstream_api/api/v1/health'
        );

        $this->assertSame('/health', $request->path());
        $this->assertSame(7, $authenticator->authenticate($request)['id']);
    }

    public function testRejectsInvalidSignatureBeforeClaimingNonce(): void
    {
        [$authenticator, , $nonces] = $this->authenticator();
        $request = $this->signedRequest('nonce-invalid-signature', self::NOW, 'wrong-secret');

        $this->assertApiError($authenticator, $request, 'AUTHENTICATION_FAILED', 401);
        $this->assertSame(0, count($nonces->claimed));
    }

    public function testRejectsExpiredTimestampAndReusedNonce(): void
    {
        [$authenticator] = $this->authenticator();
        $expired = $this->signedRequest('nonce-expired', self::NOW - 301);
        $this->assertApiError($authenticator, $expired, 'SIGNATURE_EXPIRED', 401);

        $valid = $this->signedRequest('nonce-reused');
        $authenticator->authenticate($valid);
        $this->assertApiError($authenticator, $valid, 'NONCE_REUSED', 401);
    }

    public function testRejectsBlockedSourceIpBeforeSignatureAndNonce(): void
    {
        [$authenticator, , $nonces] = $this->authenticator(['ip_allowlist_json' => '["192.0.2.0/24"]']);
        $blocked = $this->signedRequest('nonce-blocked', self::NOW, self::SECRET, '198.51.100.20');

        $this->assertApiError($authenticator, $blocked, 'AUTHENTICATION_FAILED', 403);
        $this->assertSame(0, count($nonces->claimed));
    }

    public function testAllowsIpv6CidrAndRejectsMalformedHeaders(): void
    {
        [$authenticator] = $this->authenticator(['ip_allowlist_json' => '["2001:db8::/32"]']);
        $valid = $this->signedRequest('nonce-ipv6', self::NOW, self::SECRET, '2001:db8::100');
        $this->assertSame(7, $authenticator->authenticate($valid)['id']);

        $invalid = new Request('GET', '/health', [], [
            'X-PeakRack-Key' => 'pk_test',
            'X-PeakRack-Timestamp' => 'not-a-timestamp',
            'X-PeakRack-Nonce' => 'nonce',
            'X-PeakRack-Signature' => strtoupper(str_repeat('a', 64)),
        ], '', '192.0.2.10');
        $this->assertApiError($authenticator, $invalid, 'AUTHENTICATION_FAILED', 400);
    }

    public function testDefaultRateLimitRejectsThe121stRequest(): void
    {
        [$authenticator] = $this->authenticator();

        for ($index = 1; $index <= 120; $index++) {
            $authenticator->authenticate($this->signedRequest('rate-' . $index));
        }

        $this->assertApiError(
            $authenticator,
            $this->signedRequest('rate-121'),
            'RATE_LIMITED',
            429
        );
    }

    public function testRequiresValidIdempotencyKeyForWriteRequests(): void
    {
        [$authenticator] = $this->authenticator();

        $this->assertApiError(
            $authenticator,
            $this->signedWriteRequest('write-missing', null),
            'AUTHENTICATION_FAILED',
            400
        );
        $this->assertApiError(
            $authenticator,
            $this->signedWriteRequest('write-invalid', 'contains spaces'),
            'AUTHENTICATION_FAILED',
            400
        );

        $authenticated = $authenticator->authenticate(
            $this->signedWriteRequest('write-valid', 'service:create:123')
        );
        $this->assertSame(7, $authenticated['id']);
    }

    private function authenticator(array $overrides = []): array
    {
        $keys = new AuthenticatorFakeApiKeyRepository(array_merge([
            'id' => 7,
            'public_key' => 'pk_test',
            'encrypted_secret' => self::SECRET,
            'client_id' => 44,
            'instance_id' => '123e4567-e89b-42d3-a456-426614174000',
            'enabled' => 1,
            'ip_allowlist_json' => '["192.0.2.10"]',
            'rate_limit_per_minute' => 120,
        ], $overrides));
        $nonces = new AuthenticatorFakeNonceRepository();
        $clock = new AuthenticatorFakeClock(self::NOW);
        $rateLimiter = new RateLimiter($keys);
        $authenticator = new Authenticator(
            $keys,
            $nonces,
            $clock,
            $rateLimiter,
            static fn (string $encrypted): string => $encrypted
        );

        return [$authenticator, $keys, $nonces];
    }

    private function signedRequest(
        string $nonce,
        int $timestamp = self::NOW,
        string $secret = self::SECRET,
        string $sourceIp = '192.0.2.10',
        string $signaturePath = '/health'
    ): Request {
        $signature = RequestSigner::sign($secret, 'GET', $signaturePath, [], '', $timestamp, $nonce);

        return new Request('GET', '/health', [], [
            'X-PeakRack-Key' => 'pk_test',
            'X-PeakRack-Timestamp' => (string) $timestamp,
            'X-PeakRack-Nonce' => $nonce,
            'X-PeakRack-Signature' => $signature,
        ], '', $sourceIp, true, $signaturePath);
    }

    private function signedWriteRequest(string $nonce, ?string $idempotencyKey): Request
    {
        $body = '{}';
        $signature = RequestSigner::sign(self::SECRET, 'POST', '/services', [], $body, self::NOW, $nonce);
        $headers = [
            'X-PeakRack-Key' => 'pk_test',
            'X-PeakRack-Timestamp' => (string) self::NOW,
            'X-PeakRack-Nonce' => $nonce,
            'X-PeakRack-Signature' => $signature,
            'Content-Type' => 'application/json',
        ];

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        return new Request('POST', '/services', [], $headers, $body, '192.0.2.10');
    }

    private function assertApiError(
        Authenticator $authenticator,
        Request $request,
        string $code,
        int $status
    ): void {
        try {
            $authenticator->authenticate($request);
        } catch (ValidationException $exception) {
            $this->assertSame($code, $exception->apiErrorCode());
            $this->assertSame($status, $exception->httpStatus());
            return;
        }

        throw new RuntimeException("Expected API error {$code}.");
    }
}

final class AuthenticatorFakeClock implements Clock
{
    public function __construct(private readonly int $timestamp)
    {
    }

    public function now(): int
    {
        return $this->timestamp;
    }
}

final class AuthenticatorFakeNonceRepository implements NonceRepository
{
    public array $claimed = [];

    public function claim(int $apiKeyId, string $nonce, int $expiresAt): bool
    {
        $key = $apiKeyId . ':' . $nonce;
        if (isset($this->claimed[$key])) {
            return false;
        }

        $this->claimed[$key] = $expiresAt;
        return true;
    }

    public function purgeExpired(int $now): int
    {
        return 0;
    }
}

final class AuthenticatorFakeApiKeyRepository implements ApiKeyRepository
{
    public ?array $lastUsage = null;
    private int $rateCount = 0;
    private ?int $rateWindowStart = null;

    public function __construct(private array $key)
    {
    }

    public function find(int $id): ?array
    {
        return $id === (int) $this->key['id'] ? $this->key : null;
    }

    public function findEnabledByPublicKey(string $publicKey): ?array
    {
        if ($publicKey !== $this->key['public_key'] || !(bool) $this->key['enabled']) {
            return null;
        }

        return $this->key;
    }

    public function all(): array
    {
        return [$this->key];
    }

    public function create(array $attributes): int
    {
        throw new RuntimeException('Not implemented by test fake.');
    }

    public function updateEncryptedSecret(int $id, string $encryptedSecret): void
    {
        throw new RuntimeException('Not implemented by test fake.');
    }

    public function updateUsage(int $id, string $sourceIp, int $usedAt): void
    {
        $this->lastUsage = ['id' => $id, 'ip' => $sourceIp, 'at' => $usedAt];
    }

    public function consumeRateLimit(int $id, int $now, int $windowSeconds): bool
    {
        if ($this->rateWindowStart === null || $this->rateWindowStart + $windowSeconds <= $now) {
            $this->rateWindowStart = $now;
            $this->rateCount = 0;
        }

        if ($this->rateCount >= (int) $this->key['rate_limit_per_minute']) {
            return false;
        }

        $this->rateCount++;
        return true;
    }

    public function hasServices(int $id): bool
    {
        return false;
    }

    public function delete(int $id): void
    {
        throw new RuntimeException('Not implemented by test fake.');
    }
}
