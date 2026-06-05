<?php

require_once __DIR__ . '/state.php';

const PEAKRACK_MOCK_API_PATH = '/modules/addons/peakrack_upstream_api/api/v1';
const PEAKRACK_MOCK_OPERATION_ID = '123e4567-e89b-42d3-a456-426614174000';

function peakrackMockHeaders(): array
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $normalized = [];
    foreach (is_array($headers) ? $headers : [] as $name => $value) {
        $normalized[strtolower((string) $name)] = (string) $value;
    }
    foreach ($_SERVER as $name => $value) {
        if (str_starts_with($name, 'HTTP_')) {
            $normalized[strtolower(str_replace('_', '-', substr($name, 5)))] = (string) $value;
        }
    }

    return $normalized;
}

function peakrackMockCanonicalQuery(array $query): string
{
    $pairs = [];
    foreach ($query as $key => $value) {
        foreach (is_array($value) ? $value : [$value] as $item) {
            $pairs[] = [rawurlencode((string) $key), rawurlencode((string) $item)];
        }
    }
    usort($pairs, static fn (array $left, array $right): int => [$left[0], $left[1]] <=> [$right[0], $right[1]]);

    return implode('&', array_map(
        static fn (array $pair): string => $pair[0] . '=' . $pair[1],
        $pairs
    ));
}

function peakrackMockExpectedSignature(
    string $secret,
    string $method,
    string $path,
    array $query,
    string $body,
    string $timestamp,
    string $nonce
): string {
    $canonical = implode("\n", [
        strtoupper($method),
        $path,
        peakrackMockCanonicalQuery($query),
        hash('sha256', $body),
        $timestamp,
        $nonce,
    ]);

    return hash_hmac('sha256', $canonical, $secret);
}

function peakrackMockResponse(
    int $httpStatus,
    bool $success,
    string $status,
    ?array $data,
    ?string $operationId,
    ?array $error
): never {
    http_response_code($httpStatus);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'success' => $success,
        'status' => $status,
        'data' => $data,
        'operation_id' => $operationId,
        'error' => $error,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function peakrackMockError(int $httpStatus, string $code, string $message): never
{
    peakrackMockResponse($httpStatus, false, 'failed', null, null, [
        'code' => $code,
        'message' => $message,
    ]);
}

function peakrackMockDelivery(int $localServiceId): array
{
    return [
        'upstream_service_id' => $localServiceId * 10,
        'upstream_order_id' => $localServiceId * 10 + 1,
        'upstream_invoice_id' => $localServiceId * 10 + 2,
        'service_status' => 'active',
        'primary_ip' => '192.0.2.10',
        'panel_url' => 'https://panel.example.test/service/' . $localServiceId,
    ];
}

$statePath = (string) getenv('PEAKRACK_MOCK_STATE');
$publicKey = (string) getenv('PEAKRACK_MOCK_KEY');
$secret = (string) getenv('PEAKRACK_MOCK_SECRET');
if ($statePath === '' || $publicKey === '' || $secret === '') {
    peakrackMockError(500, 'MOCK_CONFIGURATION_ERROR', 'Mock server configuration is incomplete.');
}

$store = new PeakRackMockState($statePath);
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$body = (string) file_get_contents('php://input');
$headers = peakrackMockHeaders();
$timestamp = (string) ($headers['x-peakrack-timestamp'] ?? '');
$nonce = (string) ($headers['x-peakrack-nonce'] ?? '');
$signature = (string) ($headers['x-peakrack-signature'] ?? '');
$expected = peakrackMockExpectedSignature($secret, $method, $path, $_GET, $body, $timestamp, $nonce);

if (
    ($headers['x-peakrack-key'] ?? '') !== $publicKey
    || !ctype_digit($timestamp)
    || preg_match('/^[A-Za-z0-9._:-]{1,191}$/', $nonce) !== 1
    || preg_match('/^[0-9a-f]{64}$/', $signature) !== 1
    || !hash_equals($expected, $signature)
) {
    $store->update(static function (array $state): array {
        $state['invalid_signatures']++;
        return $state;
    });
    peakrackMockError(401, 'AUTHENTICATION_FAILED', 'Authentication failed.');
}

$store->update(static function (array $state): array {
    $state['valid_requests']++;
    return $state;
});

$relative = str_starts_with($path, PEAKRACK_MOCK_API_PATH)
    ? substr($path, strlen(PEAKRACK_MOCK_API_PATH))
    : '';

if ($method === 'GET' && $relative === '/health') {
    peakrackMockResponse(200, true, 'completed', [
        'protocol' => 'v1',
        'module_version' => '1.0.0',
        'instance_id' => '123e4567-e89b-42d3-a456-426614174000',
        'key_enabled' => true,
        'blocking_errors' => [],
        'worker_last_run' => 1780660800,
    ], null, null);
}

if ($method === 'POST' && $relative === '/services') {
    $idempotencyKey = (string) ($headers['idempotency-key'] ?? '');
    $payload = json_decode($body, true);
    if (
        preg_match('/^[A-Za-z0-9._:-]{1,191}$/', $idempotencyKey) !== 1
        || !is_array($payload)
        || array_is_list($payload)
        || !is_int($payload['local_service_id'] ?? null)
        || !is_string($payload['hostname'] ?? null)
    ) {
        peakrackMockError(422, 'VALIDATION_ERROR', 'The create request is invalid.');
    }

    $requestHash = hash('sha256', $method . "\n" . $path . "\n" . $body);
    $state = $store->read();
    $existing = $state['operations'][$idempotencyKey] ?? null;
    if (is_array($existing)) {
        if (($existing['request_hash'] ?? '') !== $requestHash) {
            peakrackMockError(409, 'IDEMPOTENCY_CONFLICT', 'The idempotency key was reused for another request.');
        }
        peakrackMockResponse(
            (int) $existing['http_status'],
            (bool) $existing['response']['success'],
            (string) $existing['response']['status'],
            is_array($existing['response']['data'] ?? null) ? $existing['response']['data'] : null,
            is_string($existing['response']['operation_id'] ?? null) ? $existing['response']['operation_id'] : null,
            is_array($existing['response']['error'] ?? null) ? $existing['response']['error'] : null
        );
    }

    $serviceId = (int) $payload['local_service_id'];
    $hostname = (string) $payload['hostname'];
    if ($hostname === 'failure.example.test') {
        $httpStatus = 422;
        $response = [
            'success' => false,
            'status' => 'failed',
            'data' => null,
            'operation_id' => null,
            'error' => [
                'code' => 'PROVISIONING_FAILED',
                'message' => 'The mock provisioning request failed.',
            ],
        ];
    } elseif ($hostname === 'processing.example.test') {
        $httpStatus = 202;
        $response = [
            'success' => true,
            'status' => 'processing',
            'data' => null,
            'operation_id' => PEAKRACK_MOCK_OPERATION_ID,
            'error' => null,
        ];
    } else {
        $httpStatus = 200;
        $response = [
            'success' => true,
            'status' => 'completed',
            'data' => peakrackMockDelivery($serviceId),
            'operation_id' => PEAKRACK_MOCK_OPERATION_ID,
            'error' => null,
        ];
    }

    $store->update(static function (array $state) use (
        $idempotencyKey,
        $requestHash,
        $httpStatus,
        $response,
        $serviceId
    ): array {
        $state['create_executions']++;
        $state['operations'][$idempotencyKey] = [
            'request_hash' => $requestHash,
            'http_status' => $httpStatus,
            'response' => $response,
        ];
        $state['services'][(string) $serviceId] = peakrackMockDelivery($serviceId);
        return $state;
    });

    peakrackMockResponse(
        $httpStatus,
        $response['success'],
        $response['status'],
        $response['data'],
        $response['operation_id'],
        $response['error']
    );
}

if ($method === 'GET' && preg_match('#^/services/([1-9][0-9]*)$#', $relative, $matches) === 1) {
    $serviceId = (int) $matches[1];
    $delivery = $store->read()['services'][(string) $serviceId] ?? null;
    if (!is_array($delivery)) {
        peakrackMockError(404, 'SERVICE_NOT_FOUND', 'The service was not found.');
    }
    peakrackMockResponse(200, true, 'completed', $delivery, PEAKRACK_MOCK_OPERATION_ID, null);
}

if ($method === 'POST' && preg_match('#^/services/([1-9][0-9]*)/sso$#', $relative, $matches) === 1) {
    peakrackMockResponse(200, true, 'completed', [
        'sso_url' => 'https://panel.example.test/sso/secret-contract-token-' . $matches[1],
    ], null, null);
}

peakrackMockError(404, 'ROUTE_NOT_FOUND', 'The requested API route was not found.');
