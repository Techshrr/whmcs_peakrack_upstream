# PeakRack Upstream v1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build and release the approved v1.0 upstream WHMCS Addon API and downstream WHMCS Provisioning Module with signed requests, reseller Credit billing, idempotent mixed-mode operations, background processing, and release documentation.

**Architecture:** The repository contains two independently deployable WHMCS modules. Pure PHP domain and protocol classes stay testable without a running WHMCS installation; thin adapters connect them to `WHMCS\Database\Capsule`, WHMCS Local API, provisioning functions, templates, and Cron entry points. Every write operation is persisted and idempotent before side effects, and WHMCS-specific integration behavior is isolated behind interfaces and tested with fakes before live-environment verification.

**Tech Stack:** PHP 8.2+, WHMCS 9.0.x module APIs, `WHMCS\Database\Capsule`, WHMCS Local API, cURL, Smarty templates, GitHub Actions, custom dependency-free PHP CLI test runner.

---

## File Map

### Repository and Tests

- `VERSION`: release version.
- `.gitignore`, `.gitattributes`: repository hygiene and release exclusions.
- `.github/workflows/php-lint.yml`: PHP 8.2/8.3 lint and unit tests.
- `tests/bootstrap.php`: test autoloaders and helpers.
- `tests/TestCase.php`: dependency-free assertions and test base class.
- `tests/run.php`: discovers and executes tests with optional group filtering.
- `tests/fixtures/mockserver/`: deterministic fake API and provisioning fixtures.
- `tests/unit/upstream/`: upstream pure-class tests.
- `tests/unit/downstream/`: downstream pure-class tests.
- `tests/integration/`: opt-in WHMCS integration tests that require an isolated test installation.
- `scripts/check-release.ps1`: lint, metadata, secret scan, packaging, and checksum verification.
- `scripts/sync-installed.ps1`: copies both source modules into the shared WHMCS installed tree and verifies SHA-256 parity.

### Upstream Addon Module

- `modules/addons/peakrack_upstream_api/peakrack_upstream_api.php`: Addon entry functions and admin dispatcher.
- `modules/addons/peakrack_upstream_api/hooks.php`: guards API-managed services from independent module actions.
- `modules/addons/peakrack_upstream_api/api/v1/index.php`: API bootstrap and response emitter.
- `modules/addons/peakrack_upstream_api/cron/worker.php`: CLI worker bootstrap.
- `modules/addons/peakrack_upstream_api/lib/Bootstrap.php`: module-local PSR-4-style autoloader and WHMCS runtime helpers.
- `modules/addons/peakrack_upstream_api/lib/Config.php`: version, table names, defaults, and supported environment.
- `modules/addons/peakrack_upstream_api/lib/Database/Schema.php`: Addon-owned schema install and migration.
- `modules/addons/peakrack_upstream_api/lib/Database/*Repository.php`: Capsule persistence adapters.
- `modules/addons/peakrack_upstream_api/lib/Security/RequestSigner.php`: canonical signature generation.
- `modules/addons/peakrack_upstream_api/lib/Security/Authenticator.php`: API key, timestamp, nonce, signature, IP, and rate-limit checks.
- `modules/addons/peakrack_upstream_api/lib/Security/Redactor.php`: recursive sensitive-data masking.
- `modules/addons/peakrack_upstream_api/lib/Http/Request.php`: normalized immutable API request.
- `modules/addons/peakrack_upstream_api/lib/Http/Response.php`: normalized JSON response.
- `modules/addons/peakrack_upstream_api/lib/Http/Router.php`: strict route matching.
- `modules/addons/peakrack_upstream_api/lib/Domain/*`: operation, service, policy, and exception value objects.
- `modules/addons/peakrack_upstream_api/lib/Contracts/*`: persistence, WHMCS, clock, and ID generator interfaces.
- `modules/addons/peakrack_upstream_api/lib/Application/ApiKernel.php`: authentication and endpoint dispatch.
- `modules/addons/peakrack_upstream_api/lib/Application/CatalogService.php`: authenticated product catalog.
- `modules/addons/peakrack_upstream_api/lib/Application/OperationService.php`: idempotent operation admission and orchestration.
- `modules/addons/peakrack_upstream_api/lib/Application/WorkerService.php`: retry, verification, compensation, and cleanup.
- `modules/addons/peakrack_upstream_api/lib/Application/HealthService.php`: compatibility and worker-health checks.
- `modules/addons/peakrack_upstream_api/lib/Whmcs/LocalApiClient.php`: checked `localAPI()` wrapper.
- `modules/addons/peakrack_upstream_api/lib/Whmcs/BillingGateway.php`: order, invoice, Credit, renewal, upgrade, and cancellation actions.
- `modules/addons/peakrack_upstream_api/lib/Whmcs/ProvisioningGateway.php`: module actions and delivery-field reads.
- `modules/addons/peakrack_upstream_api/lib/Admin/AdminController.php`: admin pages and CSRF-checked actions.
- `modules/addons/peakrack_upstream_api/templates/*.tpl`: theme-neutral Addon admin templates.
- `modules/addons/peakrack_upstream_api/lang/{english,chinese}.php`: Addon translations.

### Downstream Provisioning Module

- `modules/servers/peakrackupstream/peakrackupstream.php`: WHMCS Provisioning Module functions only.
- `modules/servers/peakrackupstream/cron/sync.php`: downstream CLI sync bootstrap.
- `modules/servers/peakrackupstream/lib/Bootstrap.php`: module-local autoloader.
- `modules/servers/peakrackupstream/lib/Config.php`: protocol version and defaults.
- `modules/servers/peakrackupstream/lib/Api/RequestSigner.php`: canonical downstream HMAC signer.
- `modules/servers/peakrackupstream/lib/Api/ApiClient.php`: HTTPS JSON client with timeout and strict response handling.
- `modules/servers/peakrackupstream/lib/Api/ApiException.php`: stable API/client error.
- `modules/servers/peakrackupstream/lib/Redactor.php`: downstream recursive log masking.
- `modules/servers/peakrackupstream/lib/Mapper.php`: WHMCS parameter to API payload mapping.
- `modules/servers/peakrackupstream/lib/ServiceProperties.php`: service-property reads and writes.
- `modules/servers/peakrackupstream/lib/ModuleService.php`: lifecycle operation behavior.
- `modules/servers/peakrackupstream/lib/SyncService.php`: batch status synchronization.
- `modules/servers/peakrackupstream/templates/clientarea.tpl`: read-only service view and SSO action.
- `modules/servers/peakrackupstream/lang/{english,chinese}.php`: Provisioning Module translations.

## Task 1: Repository Scaffold and Dependency-Free Test Runner

**Files:**
- Create: `.gitignore`
- Create: `.gitattributes`
- Create: `VERSION`
- Create: `LICENSE`
- Create: `NOTICE`
- Create: `modules/addons/peakrack_upstream_api/LICENSE`
- Create: `modules/addons/peakrack_upstream_api/NOTICE`
- Create: `modules/servers/peakrackupstream/LICENSE`
- Create: `modules/servers/peakrackupstream/NOTICE`
- Create: `tests/TestCase.php`
- Create: `tests/bootstrap.php`
- Create: `tests/run.php`
- Create: `tests/unit/RepositoryStructureTest.php`

- [ ] **Step 1: Write the failing repository-structure test**

`RepositoryStructureTest` must assert:

```php
$this->assertSame('1.0.0', trim(file_get_contents(ROOT . '/VERSION')));
$this->assertFileExists(ROOT . '/modules/addons/peakrack_upstream_api/LICENSE');
$this->assertFileExists(ROOT . '/modules/addons/peakrack_upstream_api/NOTICE');
$this->assertFileExists(ROOT . '/modules/servers/peakrackupstream/LICENSE');
$this->assertFileExists(ROOT . '/modules/servers/peakrackupstream/NOTICE');
$this->assertSame(
    hash_file('sha256', ROOT . '/LICENSE'),
    hash_file('sha256', ROOT . '/modules/addons/peakrack_upstream_api/LICENSE')
);
```

- [ ] **Step 2: Run the test and verify RED**

Run: `php tests/run.php tests/unit/RepositoryStructureTest.php`

Expected: FAIL because `VERSION`, license copies, and module directories do not exist.

- [ ] **Step 3: Implement the runner and repository scaffold**

The test runner must:

- discover `*Test.php`,
- instantiate subclasses of `PeakRack\Tests\TestCase`,
- execute public methods beginning with `test`,
- report each pass or failure,
- exit `0` only when all selected tests pass.

Create the full Apache-2.0 license text, concise NOTICE files with the official
repository URL, `VERSION` containing `1.0.0`, and release-safe ignore/attribute
rules.

- [ ] **Step 4: Run test and verify GREEN**

Run: `php tests/run.php tests/unit/RepositoryStructureTest.php`

Expected: PASS with zero failures.

- [ ] **Step 5: Commit**

```text
git add .gitignore .gitattributes VERSION LICENSE NOTICE modules tests
git commit -m "Build repository and test foundation"
```

## Task 2: Protocol Canonicalization and Recursive Redaction

**Files:**
- Create: `modules/addons/peakrack_upstream_api/lib/Bootstrap.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Config.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Security/RequestSigner.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Security/Redactor.php`
- Create: `modules/servers/peakrackupstream/lib/Bootstrap.php`
- Create: `modules/servers/peakrackupstream/lib/Config.php`
- Create: `modules/servers/peakrackupstream/lib/Api/RequestSigner.php`
- Create: `modules/servers/peakrackupstream/lib/Redactor.php`
- Create: `tests/unit/upstream/RequestSignerTest.php`
- Create: `tests/unit/upstream/RedactorTest.php`
- Create: `tests/unit/downstream/RequestSignerTest.php`
- Create: `tests/unit/downstream/RedactorTest.php`

- [ ] **Step 1: Write failing shared-vector signature tests**

Both signer tests must use this exact vector:

```php
$canonical = "POST\n/services\npage=1&sort=name\n"
    . hash('sha256', '{"hostname":"example.com"}')
    . "\n1710000000\nnonce-123";

$this->assertSame(
    hash_hmac('sha256', $canonical, 'secret'),
    RequestSigner::sign(
        'secret',
        'POST',
        '/services',
        ['sort' => 'name', 'page' => '1'],
        '{"hostname":"example.com"}',
        1710000000,
        'nonce-123'
    )
);
```

Also assert RFC 3986 query sorting, exact raw-body hashing, and uppercase HTTP
method normalization.

- [ ] **Step 2: Write failing recursive-redaction tests**

Test nested arrays, JSON strings, mixed key casing, and non-sensitive values:

```php
$redacted = Redactor::redact([
    'password' => 'one',
    'nested' => ['Api_Secret' => 'two', 'hostname' => 'safe.example'],
    'json' => '{"token":"three","status":"active"}',
]);

$this->assertSame('[REDACTED]', $redacted['password']);
$this->assertSame('[REDACTED]', $redacted['nested']['Api_Secret']);
$this->assertStringNotContains('three', $redacted['json']);
$this->assertSame('safe.example', $redacted['nested']['hostname']);
```

- [ ] **Step 3: Run protocol tests and verify RED**

Run: `php tests/run.php tests/unit/upstream/RequestSignerTest.php tests/unit/upstream/RedactorTest.php tests/unit/downstream/RequestSignerTest.php tests/unit/downstream/RedactorTest.php`

Expected: FAIL because the signer and redactor classes do not exist.

- [ ] **Step 4: Implement module-local autoloaders, signers, and redactors**

Use these public APIs in both modules:

```php
public static function canonicalize(
    string $method,
    string $path,
    array $query,
    string $rawBody,
    int $timestamp,
    string $nonce
): string;

public static function sign(
    string $secret,
    string $method,
    string $path,
    array $query,
    string $rawBody,
    int $timestamp,
    string $nonce
): string;

public static function redact(mixed $value): mixed;
```

Signer implementations must produce identical output but use separate module
namespaces so both modules can be installed on one WHMCS instance.

- [ ] **Step 5: Run protocol tests and verify GREEN**

Run: `php tests/run.php --group unit`

Expected: all current tests pass.

- [ ] **Step 6: Commit**

```text
git add modules tests
git commit -m "Add signed protocol and recursive redaction"
```

## Task 3: Upstream Domain Model, Errors, and Operation State Machine

**Files:**
- Create: `modules/addons/peakrack_upstream_api/lib/Domain/ApiError.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Domain/Operation.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Domain/OperationResult.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Domain/ProductPolicy.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Domain/ServiceRecord.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Domain/ServiceStatus.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Domain/ValidationException.php`
- Create: `tests/unit/upstream/OperationTest.php`
- Create: `tests/unit/upstream/ProductPolicyTest.php`
- Create: `tests/unit/upstream/ServiceStatusTest.php`

- [ ] **Step 1: Write failing state-machine tests**

Assert:

- `queued -> processing -> completed` is valid.
- `processing -> failed` and `processing -> manual_review` are valid.
- terminal states cannot transition.
- an unknown provider status maps to `unknown`, never `active`.
- a product policy rejects an unauthorized cycle, Location, OS, action, or
  destroy request.

- [ ] **Step 2: Run domain tests and verify RED**

Run: `php tests/run.php tests/unit/upstream/OperationTest.php tests/unit/upstream/ProductPolicyTest.php tests/unit/upstream/ServiceStatusTest.php`

Expected: FAIL because domain classes do not exist.

- [ ] **Step 3: Implement immutable domain objects**

Required stable API:

```php
Operation::admit(string $id, int $apiKeyId, string $action, string $idempotencyKey, string $requestHash): Operation;
Operation::start(): Operation;
Operation::complete(array $result): Operation;
Operation::fail(string $code, string $message): Operation;
Operation::manualReview(string $code, string $message): Operation;
ProductPolicy::assertAllows(string $action, int $productId, string $cycle, ?string $location, ?string $os, bool $destroy): void;
ServiceStatus::normalize(string $upstreamStatus): string;
```

Use only the stable external error codes approved in the design.

- [ ] **Step 4: Run domain tests and verify GREEN**

Run: `php tests/run.php --group unit`

Expected: all current tests pass.

- [ ] **Step 5: Commit**

```text
git add modules/addons/peakrack_upstream_api/lib/Domain tests/unit/upstream
git commit -m "Define upstream operation and policy domain"
```

## Task 4: Upstream Schema and Persistence Contracts

**Files:**
- Create: `modules/addons/peakrack_upstream_api/lib/Contracts/ApiKeyRepository.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Contracts/PolicyRepository.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Contracts/ServiceRepository.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Contracts/OperationRepository.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Contracts/NonceRepository.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Contracts/LockRepository.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Contracts/Clock.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Contracts/IdGenerator.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Database/Schema.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Database/CapsuleApiKeyRepository.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Database/CapsulePolicyRepository.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Database/CapsuleServiceRepository.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Database/CapsuleOperationRepository.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Database/CapsuleNonceRepository.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Database/CapsuleLockRepository.php`
- Create: `tests/unit/upstream/SchemaTest.php`
- Create: `tests/unit/upstream/PersistenceContractTest.php`

- [ ] **Step 1: Write failing schema-definition tests**

`Schema::definitions()` must describe all seven approved tables, required
columns, and unique keys:

```php
$definitions = Schema::definitions();
$this->assertArrayHasKey('mod_peakrack_upstream_operations', $definitions);
$this->assertContains(
    ['api_key_id', 'idempotency_key'],
    $definitions['mod_peakrack_upstream_operations']['unique']
);
$this->assertContains(
    ['api_key_id', 'local_service_id'],
    $definitions['mod_peakrack_upstream_services']['unique']
);
```

- [ ] **Step 2: Write failing persistence contract tests**

Use in-memory fake repositories in the test to assert the contract required by
the application layer:

- atomic operation admission returns the existing operation on identical replay,
- conflicting request hashes are detectable,
- nonce insertion is unique per API key,
- service lookup is always scoped by API key,
- lock acquisition has owner and expiry.

- [ ] **Step 3: Run tests and verify RED**

Run: `php tests/run.php tests/unit/upstream/SchemaTest.php tests/unit/upstream/PersistenceContractTest.php`

Expected: FAIL because contracts and schema do not exist.

- [ ] **Step 4: Implement schema and Capsule repositories**

`Schema::install()` uses Capsule schema builder and creates or migrates each
Addon-owned table. `Schema::uninstall()` is intentionally not called during
deactivation. Repository methods use parameterized Capsule queries and never
accept a raw table name from requests.

- [ ] **Step 5: Run tests and PHP lint**

Run: `php tests/run.php --group unit`

Run: `Get-ChildItem modules/addons/peakrack_upstream_api -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }`

Expected: tests pass and every lint command reports no syntax errors.

- [ ] **Step 6: Commit**

```text
git add modules/addons/peakrack_upstream_api/lib tests/unit/upstream
git commit -m "Add upstream schema and persistence adapters"
```

## Task 5: HTTP Request, Router, Authentication, and Rate Limiting

**Files:**
- Create: `modules/addons/peakrack_upstream_api/lib/Http/Request.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Http/Response.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Http/Router.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Security/Authenticator.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Security/RateLimiter.php`
- Create: `tests/unit/upstream/RequestTest.php`
- Create: `tests/unit/upstream/RouterTest.php`
- Create: `tests/unit/upstream/AuthenticatorTest.php`

- [ ] **Step 1: Write failing strict-request and router tests**

Assert JSON-only parsing, maximum body size, unknown-field rejection, exact
route matching, positive integer service IDs, and no redirect behavior.

- [ ] **Step 2: Write failing authentication tests**

Use fake repositories and a fake clock to assert:

- valid request authenticates,
- invalid signature returns `AUTHENTICATION_FAILED`,
- timestamp outside 300 seconds returns `SIGNATURE_EXPIRED`,
- duplicate nonce returns `NONCE_REUSED`,
- a blocked source IP returns `403`,
- the 121st request in a default one-minute window returns `RATE_LIMITED`.

- [ ] **Step 3: Run tests and verify RED**

Run: `php tests/run.php tests/unit/upstream/RequestTest.php tests/unit/upstream/RouterTest.php tests/unit/upstream/AuthenticatorTest.php`

Expected: FAIL because HTTP and authentication classes do not exist.

- [ ] **Step 4: Implement HTTP and authentication classes**

Authenticate in this order:

```text
header format
-> API key enabled
-> source IP
-> timestamp
-> signature
-> nonce unique insert
-> rate limit
```

Return only `Http\Response` objects with the approved envelope and status codes.

- [ ] **Step 5: Run tests and verify GREEN**

Run: `php tests/run.php --group unit`

Expected: all tests pass.

- [ ] **Step 6: Commit**

```text
git add modules/addons/peakrack_upstream_api/lib tests/unit/upstream
git commit -m "Authenticate and route upstream API requests"
```

## Task 6: Idempotent Operation Admission and Worker Orchestration

**Files:**
- Create: `modules/addons/peakrack_upstream_api/lib/Application/OperationService.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Application/WorkerService.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Application/OperationExecutor.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Contracts/OperationExecutor.php`
- Create: `tests/unit/upstream/OperationServiceTest.php`
- Create: `tests/unit/upstream/WorkerServiceTest.php`

- [ ] **Step 1: Write failing operation-admission tests**

Assert:

- first request persists before execution,
- same key and hash returns the original result,
- same key with different hash throws a `409` conflict,
- same service cannot run two lifecycle writes concurrently,
- a Credit-consuming operation also acquires the reseller billing lock.

- [ ] **Step 2: Write failing worker tests**

Assert:

- due tasks are claimed once,
- safe failure schedules exponential retry capped at 60 minutes,
- 12 exhausted attempts become `manual_review`,
- unknown outcome is verified instead of immediately replayed,
- due `cancel_only` operation invokes authorized termination,
- nonce cleanup and stale lock cleanup run.

- [ ] **Step 3: Run tests and verify RED**

Run: `php tests/run.php tests/unit/upstream/OperationServiceTest.php tests/unit/upstream/WorkerServiceTest.php`

Expected: FAIL because application services do not exist.

- [ ] **Step 4: Implement admission and worker services**

Operation admission computes the canonical request hash from the normalized
business payload, not from secret-bearing raw JSON. The persisted sanitized
payload excludes passwords and SSO URLs. Worker error events pass through the
recursive redactor before persistence.

- [ ] **Step 5: Run tests and verify GREEN**

Run: `php tests/run.php --group unit`

Expected: all tests pass.

- [ ] **Step 6: Commit**

```text
git add modules/addons/peakrack_upstream_api/lib/Application modules/addons/peakrack_upstream_api/lib/Contracts tests/unit/upstream
git commit -m "Coordinate idempotent upstream operations"
```

## Task 7: WHMCS Local API, Billing, Provisioning, and Automation Guards

**Files:**
- Create: `modules/addons/peakrack_upstream_api/lib/Contracts/BillingGateway.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Contracts/ProvisioningGateway.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Whmcs/LocalApiClient.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Whmcs/BillingGateway.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Whmcs/ProvisioningGateway.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Whmcs/OperationContext.php`
- Create: `modules/addons/peakrack_upstream_api/hooks.php`
- Create: `tests/unit/upstream/LocalApiClientTest.php`
- Create: `tests/unit/upstream/BillingGatewayTest.php`
- Create: `tests/unit/upstream/ProvisioningGatewayTest.php`
- Create: `tests/unit/upstream/OperationContextTest.php`

- [ ] **Step 1: Write failing Local API and billing tests**

With a callable fake for `localAPI()`, assert exact command and parameter use:

- create: `AddOrder`, then final invoice read, `ApplyCredit` with `noemail`,
  then `AcceptOrder`,
- insufficient final invoice balance cancels order/invoice before provisioning,
- renew reuses an existing renewal invoice or uses `AddOrder.servicerenewals`,
- package change uses `UpgradeProduct`,
- confirmed create failure restores the exact stored amount with `AddCredit`,
- customer-facing email flags are disabled.

- [ ] **Step 2: Write failing provisioning and operation-context tests**

Assert:

- `ModuleCreate`, `ModuleSuspend`, `ModuleUnsuspend`, and `ModuleTerminate` use
  the expected service ID,
- mapped delivery fields exclude unsafe custom fields,
- SSO requires HTTPS and an allowed target host,
- automation guard decisions allow unrelated services,
- API-managed services are blocked outside an authorized operation context.

- [ ] **Step 3: Run tests and verify RED**

Run: `php tests/run.php tests/unit/upstream/LocalApiClientTest.php tests/unit/upstream/BillingGatewayTest.php tests/unit/upstream/ProvisioningGatewayTest.php tests/unit/upstream/OperationContextTest.php`

Expected: FAIL because WHMCS adapters do not exist.

- [ ] **Step 4: Implement checked Local API and WHMCS gateways**

`LocalApiClient::call()` accepts only an allowlisted command and throws a
sanitized domain exception when `result !== success`. `OperationContext` is
request-scoped static state set only by application code and cleared in a
`finally` block.

`hooks.php` registers:

```text
PreModuleSuspend
PreModuleUnsuspend
PreModuleTerminate
PreModuleRenew
PreModuleChangePackage
```

Each hook returns `abortcmd => true` only for an API-managed service outside the
authorized context.

- [ ] **Step 5: Run tests and verify GREEN**

Run: `php tests/run.php --group unit`

Expected: all tests pass.

- [ ] **Step 6: Commit**

```text
git add modules/addons/peakrack_upstream_api tests/unit/upstream
git commit -m "Integrate upstream WHMCS billing and provisioning"
```

## Task 8: Service Lifecycle Executors, Catalog, Health, and API Kernel

**Files:**
- Create: `modules/addons/peakrack_upstream_api/lib/Application/CreateExecutor.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Application/RenewExecutor.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Application/ChangePackageExecutor.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Application/LifecycleExecutor.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Application/CatalogService.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Application/HealthService.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Application/ApiKernel.php`
- Create: `modules/addons/peakrack_upstream_api/api/v1/index.php`
- Create: `tests/unit/upstream/CreateExecutorTest.php`
- Create: `tests/unit/upstream/RenewExecutorTest.php`
- Create: `tests/unit/upstream/ChangePackageExecutorTest.php`
- Create: `tests/unit/upstream/ApiKernelTest.php`

- [ ] **Step 1: Write failing lifecycle executor tests**

Use fake billing and provisioning gateways to prove:

- create follows order, invoice, Credit, provisioning, and delivery-confirmation
  stages,
- `processing` does not compensate,
- confirmed never-delivered failure compensates exactly once,
- renew advances the due boundary exactly once,
- downgrade never adds Credit,
- `cancel_only` queues due termination,
- `destroy` requires both downstream request and upstream policy permission.

- [ ] **Step 2: Write failing API kernel tests**

Exercise every approved route and assert:

- authentication occurs before business lookup,
- service ownership is always API-key scoped,
- `/catalog` returns only authorized values,
- `/health` reports protocol and blocking Credit settings,
- stable error envelope and status codes,
- SSO URL never appears in logs or persisted events.

- [ ] **Step 3: Run tests and verify RED**

Run: `php tests/run.php tests/unit/upstream/CreateExecutorTest.php tests/unit/upstream/RenewExecutorTest.php tests/unit/upstream/ChangePackageExecutorTest.php tests/unit/upstream/ApiKernelTest.php`

Expected: FAIL because executors and kernel do not exist.

- [ ] **Step 4: Implement executors, catalog, health, kernel, and API entry**

The API entry:

- locates and loads the WHMCS `init.php`,
- builds the production dependency graph,
- passes raw body, headers, path, query, source IP, and method into `ApiKernel`,
- sends the response status, JSON content type, and body,
- catches all throwables and returns sanitized `INTERNAL_ERROR`.

- [ ] **Step 5: Run tests and verify GREEN**

Run: `php tests/run.php --group unit`

Expected: all tests pass.

- [ ] **Step 6: Commit**

```text
git add modules/addons/peakrack_upstream_api tests/unit/upstream
git commit -m "Expose upstream service lifecycle API"
```

## Task 9: Upstream Addon Activation, Worker CLI, and Admin Interface

**Files:**
- Create: `modules/addons/peakrack_upstream_api/peakrack_upstream_api.php`
- Create: `modules/addons/peakrack_upstream_api/cron/worker.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Admin/AdminController.php`
- Create: `modules/addons/peakrack_upstream_api/lib/Admin/Csrf.php`
- Create: `modules/addons/peakrack_upstream_api/templates/dashboard.tpl`
- Create: `modules/addons/peakrack_upstream_api/templates/api-keys.tpl`
- Create: `modules/addons/peakrack_upstream_api/templates/product-policies.tpl`
- Create: `modules/addons/peakrack_upstream_api/templates/operations.tpl`
- Create: `modules/addons/peakrack_upstream_api/templates/services.tpl`
- Create: `modules/addons/peakrack_upstream_api/templates/system-health.tpl`
- Create: `modules/addons/peakrack_upstream_api/lang/english.php`
- Create: `modules/addons/peakrack_upstream_api/lang/chinese.php`
- Create: `tests/unit/upstream/AddonEntryTest.php`
- Create: `tests/unit/upstream/AdminControllerTest.php`
- Create: `tests/unit/upstream/WorkerCliTest.php`

- [ ] **Step 1: Write failing Addon-entry tests**

Assert:

- config declares name, description, version, author, and required fields,
- activation rejects PHP below 8.2 or WHMCS outside 9.0.x,
- activation installs schema,
- deactivation preserves data,
- admin actions require an authenticated administrator and valid CSRF token.

- [ ] **Step 2: Write failing admin and CLI tests**

Assert:

- API secret is returned once on creation/rotation and never in list data,
- keys with services cannot be deleted,
- key client and instance binding cannot change,
- policy save validates product, cycle, currency, and module assignment,
- manual review retry is explicit and audited,
- worker refuses non-CLI execution and reports a nonzero exit on bootstrap error.

- [ ] **Step 3: Run tests and verify RED**

Run: `php tests/run.php tests/unit/upstream/AddonEntryTest.php tests/unit/upstream/AdminControllerTest.php tests/unit/upstream/WorkerCliTest.php`

Expected: FAIL because Addon entry, admin controller, templates, and worker do not exist.

- [ ] **Step 4: Implement Addon entry, admin pages, languages, and worker**

Use standard WHMCS Addon functions:

```text
peakrack_upstream_api_config
peakrack_upstream_api_activate
peakrack_upstream_api_deactivate
peakrack_upstream_api_output
```

Admin output uses local templates and escaped variables. Secret values are
never placed into list templates. The CLI worker uses the production dependency
graph and `WorkerService`.

- [ ] **Step 5: Run tests and lint**

Run: `php tests/run.php --group unit`

Run: `Get-ChildItem modules/addons/peakrack_upstream_api -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }`

Expected: tests pass and PHP lint passes.

- [ ] **Step 6: Commit**

```text
git add modules/addons/peakrack_upstream_api tests/unit/upstream
git commit -m "Add upstream administration and worker"
```

## Task 10: Downstream API Client, Validation, Mapping, and Logging

**Files:**
- Create: `modules/servers/peakrackupstream/lib/Api/ApiException.php`
- Create: `modules/servers/peakrackupstream/lib/Api/ApiClient.php`
- Create: `modules/servers/peakrackupstream/lib/Mapper.php`
- Create: `modules/servers/peakrackupstream/lib/Validator.php`
- Create: `modules/servers/peakrackupstream/lib/Logger.php`
- Create: `tests/unit/downstream/ApiClientTest.php`
- Create: `tests/unit/downstream/MapperTest.php`
- Create: `tests/unit/downstream/ValidatorTest.php`
- Create: `tests/unit/downstream/LoggerTest.php`

- [ ] **Step 1: Write failing HTTP-client tests**

Inject a transport callable and assert:

- signed headers and JSON content type are present,
- HTTPS is mandatory,
- TLS verification and hostname verification are always enabled,
- redirects are disabled,
- timeout is clamped to 5 through 120 seconds,
- HTTP `202` is parsed as processing,
- malformed JSON and mismatched envelopes throw sanitized `ApiException`,
- request and response logs are recursively redacted.

- [ ] **Step 2: Write failing mapper and validator tests**

Assert:

- hostname, product ID, cycle, Location, OS, and terminate mode validation,
- `auto` billing cycle maps to the downstream service cycle,
- create payload contains local service ID and no client identity or price,
- password is present only in the create transport payload,
- base path rejects URL authority, query string, fragment, and traversal.

- [ ] **Step 3: Run tests and verify RED**

Run: `php tests/run.php tests/unit/downstream/ApiClientTest.php tests/unit/downstream/MapperTest.php tests/unit/downstream/ValidatorTest.php tests/unit/downstream/LoggerTest.php`

Expected: FAIL because downstream client classes do not exist.

- [ ] **Step 4: Implement downstream client classes**

`ApiClient` exposes:

```php
public function health(): array;
public function catalog(): array;
public function createService(array $payload, string $idempotencyKey): array;
public function getService(int $localServiceId): array;
public function suspendService(int $localServiceId, string $idempotencyKey): array;
public function unsuspendService(int $localServiceId, string $idempotencyKey): array;
public function terminateService(int $localServiceId, string $mode, string $idempotencyKey): array;
public function renewService(int $localServiceId, string $idempotencyKey): array;
public function changePackage(int $localServiceId, array $payload, string $idempotencyKey): array;
public function getSsoUrl(int $localServiceId, string $idempotencyKey): array;
```

- [ ] **Step 5: Run tests and verify GREEN**

Run: `php tests/run.php --group unit`

Expected: all tests pass.

- [ ] **Step 6: Commit**

```text
git add modules/servers/peakrackupstream tests/unit/downstream
git commit -m "Build downstream signed API client"
```

## Task 11: Downstream Service Properties and Module Lifecycle Service

**Files:**
- Create: `modules/servers/peakrackupstream/lib/ServiceProperties.php`
- Create: `modules/servers/peakrackupstream/lib/ModuleService.php`
- Create: `modules/servers/peakrackupstream/lib/Idempotency.php`
- Create: `tests/unit/downstream/ServicePropertiesTest.php`
- Create: `tests/unit/downstream/ModuleServiceTest.php`
- Create: `tests/unit/downstream/IdempotencyTest.php`

- [ ] **Step 1: Write failing service-property and idempotency tests**

Assert:

- admin-only property names are stable,
- primary IP maps to Dedicated IP,
- create uses one deterministic key,
- pending lifecycle actions reuse persisted keys,
- terminal lifecycle actions clear their current key,
- renewal key includes the target due boundary,
- package-change key includes target request hash.

- [ ] **Step 2: Write failing module-service tests**

With a fake `ApiClient`, assert:

- duplicate create refuses a second upstream create,
- completed create returns `success`,
- processing create persists operation and returns an administrator-readable
  message so WHMCS remains Pending,
- suspend, unsuspend, renew, change package, cancel, and destroy use the proper
  API method and save sanitized results,
- customer-facing data excludes internal IDs and errors.

- [ ] **Step 3: Run tests and verify RED**

Run: `php tests/run.php tests/unit/downstream/ServicePropertiesTest.php tests/unit/downstream/ModuleServiceTest.php tests/unit/downstream/IdempotencyTest.php`

Expected: FAIL because lifecycle classes do not exist.

- [ ] **Step 4: Implement service properties, idempotency, and module service**

The module service returns exactly `success` only for confirmed completed
actions. It returns concise administrator-readable errors for processing or
failed actions. It never returns raw upstream responses.

- [ ] **Step 5: Run tests and verify GREEN**

Run: `php tests/run.php --group unit`

Expected: all tests pass.

- [ ] **Step 6: Commit**

```text
git add modules/servers/peakrackupstream tests/unit/downstream
git commit -m "Implement downstream lifecycle behavior"
```

## Task 12: Downstream Provisioning Entry Functions, Client Area, and Sync Cron

**Files:**
- Create: `modules/servers/peakrackupstream/peakrackupstream.php`
- Create: `modules/servers/peakrackupstream/lib/SyncService.php`
- Create: `modules/servers/peakrackupstream/cron/sync.php`
- Create: `modules/servers/peakrackupstream/templates/clientarea.tpl`
- Create: `modules/servers/peakrackupstream/lang/english.php`
- Create: `modules/servers/peakrackupstream/lang/chinese.php`
- Create: `tests/unit/downstream/ProvisioningEntryTest.php`
- Create: `tests/unit/downstream/ClientAreaTest.php`
- Create: `tests/unit/downstream/SyncServiceTest.php`

- [ ] **Step 1: Write failing Provisioning Module entry tests**

Assert all approved functions exist and `ClientAreaCustomButtonArray` does not:

```php
$required = [
    'peakrackupstream_MetaData',
    'peakrackupstream_ConfigOptions',
    'peakrackupstream_TestConnection',
    'peakrackupstream_CreateAccount',
    'peakrackupstream_SuspendAccount',
    'peakrackupstream_UnsuspendAccount',
    'peakrackupstream_TerminateAccount',
    'peakrackupstream_Renew',
    'peakrackupstream_ChangePackage',
    'peakrackupstream_ClientArea',
    'peakrackupstream_ServiceSingleSignOn',
];
```

Also assert the exact server and product configuration contract.

- [ ] **Step 2: Write failing Client Area and sync tests**

Assert:

- Client Area template data includes only status, IP, panel URL, sync time, and
  SSO availability,
- SSO action returns only an allowed HTTPS redirect,
- sync prioritizes Pending services,
- one service error does not stop the batch,
- confirmed delivery changes Pending to Active,
- failed sync preserves last known safe cached values,
- CLI entry refuses browser execution.

- [ ] **Step 3: Run tests and verify RED**

Run: `php tests/run.php tests/unit/downstream/ProvisioningEntryTest.php tests/unit/downstream/ClientAreaTest.php tests/unit/downstream/SyncServiceTest.php`

Expected: FAIL because entry functions, template, languages, and sync do not
exist.

- [ ] **Step 4: Implement entry functions, template, languages, and sync**

Keep `peakrackupstream.php` limited to WHMCS function entry points and
dependency construction. Do not declare strict types. Use standard Smarty
syntax and theme-neutral Bootstrap-compatible classes without assuming Lagom.

- [ ] **Step 5: Run tests and lint**

Run: `php tests/run.php --group unit`

Run: `Get-ChildItem modules/servers/peakrackupstream -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }`

Expected: tests pass and PHP lint passes.

- [ ] **Step 6: Commit**

```text
git add modules/servers/peakrackupstream tests/unit/downstream
git commit -m "Complete downstream provisioning module"
```

## Task 13: Mock Server and End-to-End Contract Tests

**Files:**
- Create: `tests/fixtures/mockserver/router.php`
- Create: `tests/fixtures/mockserver/state.php`
- Create: `tests/integration/DownstreamApiContractTest.php`
- Create: `tests/integration/OperationFlowContractTest.php`

- [ ] **Step 1: Write failing end-to-end contract tests**

The tests start the PHP built-in server on `127.0.0.1`, call it through the
real downstream cURL transport, and assert:

- signed health request,
- completed create,
- processing create then completed poll,
- invalid signature,
- repeated idempotent create,
- idempotency-key request conflict,
- sanitized failure,
- SSO URL response is not logged.

- [ ] **Step 2: Run tests and verify RED**

Run: `php tests/run.php --group integration-contract`

Expected: FAIL because the mock server does not exist.

- [ ] **Step 3: Implement deterministic mock server and contract flow**

The mock server validates the real canonical signature and writes only
non-secret deterministic state under the system temporary directory. It must
not be copied into either production module directory.

- [ ] **Step 4: Run tests and verify GREEN**

Run: `php tests/run.php --group integration-contract`

Expected: all contract tests pass.

- [ ] **Step 5: Commit**

```text
git add tests
git commit -m "Test signed API flow end to end"
```

## Task 14: Documentation, Metadata, Release Checks, and CI

**Files:**
- Create: `README.md`
- Create: `README.zh-CN.md`
- Create: `CHANGELOG.md`
- Create: `UPGRADE.md`
- Create: `UPGRADE.zh-CN.md`
- Create: `SECURITY.md`
- Create: `docs/api-v1.md`
- Create: `.github/workflows/php-lint.yml`
- Create: `scripts/check-release.ps1`
- Create: `scripts/sync-installed.ps1`
- Create: `tests/unit/DocumentationTest.php`
- Modify: all primary PHP files to include Apache-2.0 SPDX and repository headers.

- [ ] **Step 1: Write failing documentation and metadata tests**

Assert:

- all required documents exist,
- README files begin with the official repository reference,
- all primary PHP files include `SPDX-License-Identifier: Apache-2.0` and the
  official repository URL,
- docs describe `Automatic Credit Use` and `Credit on Downgrade` as disabled,
- docs contain both Cron commands,
- docs do not claim unimplemented provider compatibility.

- [ ] **Step 2: Run tests and verify RED**

Run: `php tests/run.php tests/unit/DocumentationTest.php`

Expected: FAIL because release documents and headers do not exist.

- [ ] **Step 3: Write factual release documentation and scripts**

`scripts/check-release.ps1` must:

1. run all tests,
2. lint every PHP file,
3. verify required files and SPDX headers,
4. scan tracked files for credential patterns,
5. ensure test fixtures are excluded from production module packages,
6. build separate upstream and downstream ZIP packages,
7. emit SHA-256 checksums.

`scripts/sync-installed.ps1` must:

1. resolve the repository and shared WHMCS workspace paths,
2. copy the two source module directories into their installed locations,
3. verify all relative files and SHA-256 hashes match,
4. never delete unrelated installed WHMCS module directories.

- [ ] **Step 4: Run documentation tests and release checks**

Run: `php tests/run.php --group unit`

Run: `powershell -ExecutionPolicy Bypass -File scripts/check-release.ps1`

Expected: all tests, lint, metadata, package, checksum, and secret checks pass.

- [ ] **Step 5: Commit**

```text
git add .github README.md README.zh-CN.md CHANGELOG.md UPGRADE.md UPGRADE.zh-CN.md SECURITY.md docs modules scripts tests
git commit -m "Prepare PeakRack upstream v1 release"
```

## Task 15: Installed-Tree Sync and Isolated WHMCS Verification

**Files:**
- Create: `tests/integration/WhmcsEnvironmentTest.php`
- Create: `tests/integration/WhmcsSchemaTest.php`
- Create: `tests/integration/WhmcsModuleRegistrationTest.php`
- Modify only if tests expose defects: relevant module files.

- [ ] **Step 1: Write opt-in WHMCS integration checks**

The integration tests run only when `PEAKRACK_WHMCS_TEST=1` and assert:

- WHMCS version is 9.0.x and PHP is at least 8.2,
- Addon schema installs and indexes exist,
- Addon and Provisioning Module entry functions load without fatal errors,
- Addon deactivation preserves tables,
- installed source/runtime hashes match.

The tests must not create a billable order or execute a real provider module.

- [ ] **Step 2: Synchronize source into installed tree**

Run: `powershell -ExecutionPolicy Bypass -File scripts/sync-installed.ps1`

Expected: source and installed Addon/Server module files have identical
relative paths and SHA-256 hashes.

- [ ] **Step 3: Run safe WHMCS integration checks**

Run: `$env:PEAKRACK_WHMCS_TEST='1'; php tests/run.php --group whmcs-safe`

Expected: environment, schema, entry loading, deactivation preservation, and
parity tests pass without creating orders or contacting a provider.

- [ ] **Step 4: Run complete verification**

Run: `php tests/run.php`

Run: `powershell -ExecutionPolicy Bypass -File scripts/check-release.ps1`

Run: `git status --short`

Expected: all automated tests pass, release checks pass, and only intentional
generated release-package files are untracked or ignored.

- [ ] **Step 5: Commit any integration-test fixes**

```text
git add modules tests scripts
git commit -m "Verify PeakRack upstream modules in WHMCS"
```

## Final Review Checklist

- [ ] Compare every implementation task against the approved design.
- [ ] Confirm no unknown API fields, prices, client IDs, or raw upstream fields
  can pass through.
- [ ] Confirm all logs are redacted before persistence.
- [ ] Confirm every lifecycle operation has stable idempotency behavior.
- [ ] Confirm real-provider validation remains explicitly documented as
  outstanding until performed in an isolated provider test environment.
- [ ] Run a final code review across the complete branch.
- [ ] Run `scripts/check-release.ps1` after the final review fixes.
