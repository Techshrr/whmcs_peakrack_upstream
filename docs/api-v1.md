# PeakRack Upstream API v1

Official repository: https://github.com/Techshrr/whmcs_peakrack_upstream

## Scope

API v1 is exposed by the upstream `peakrack_upstream_api` Addon Module for authenticated downstream WHMCS instances. All routes require HTTPS and an enabled API key. The API key determines the reseller client, permanent instance identity, product policies, and service ownership.

Base entry point:

```text
/modules/addons/peakrack_upstream_api/api/v1/index.php
```

The downstream module appends route paths such as `/health` and `/services` to the entry-point base path.

## Authentication

Every request sends:

```text
X-PeakRack-Key
X-PeakRack-Timestamp
X-PeakRack-Nonce
X-PeakRack-Signature
```

Every write request also sends:

```text
Idempotency-Key
Content-Type: application/json
```

The signature is lowercase hexadecimal HMAC-SHA256 over:

```text
HTTP_METHOD
REQUEST_PATH
CANONICAL_QUERY_STRING
SHA256_REQUEST_BODY
TIMESTAMP
NONCE
```

Canonical query keys and values use RFC 3986 encoding and are sorted by encoded key, then encoded value. The body hash uses the exact raw request bytes. The default timestamp tolerance is 300 seconds. A nonce can be accepted only once per API key during its validity window.

The upstream validates the source address against the key's optional IP allowlist and applies the configured per-minute rate limit.

## Response Envelope

Every handled response is JSON:

```json
{
  "success": true,
  "status": "completed",
  "data": {},
  "operation_id": null,
  "error": null
}
```

Operation status values:

```text
queued
processing
completed
failed
manual_review
```

Service status values:

```text
pending
provisioning
active
suspended
cancellation_pending
terminated
failed
manual_review
unknown
```

A successful synchronous write returns HTTP `200` with `completed`. An unfinished accepted write returns HTTP `202`, `queued` or `processing`, and a UUID operation ID. Failed responses set `success` to `false`, `data` to `null`, and return a stable error object.

## Routes

| Method | Route | JSON fields |
|---|---|---|
| GET | `/health` | None |
| GET | `/catalog` | None |
| POST | `/services` | Required: `local_service_id`, `product_id`, `billing_cycle`, `hostname`, `password`; optional: `location`, `os_template` |
| GET | `/services/{local_service_id}` | None |
| POST | `/services/{local_service_id}/suspend` | Optional: `reason` |
| POST | `/services/{local_service_id}/unsuspend` | Empty object |
| POST | `/services/{local_service_id}/terminate` | Optional: `mode`, either `cancel_only` or `destroy` |
| POST | `/services/{local_service_id}/renew` | Required: `renewal_boundary` in `YYYY-MM-DD` form |
| POST | `/services/{local_service_id}/change-package` | Required: `product_id`, `billing_cycle`; optional: `location`, `os_template` |
| POST | `/services/{local_service_id}/sso` | Empty object |
| GET | `/operations/{operation_id}` | None |

Unknown JSON fields are rejected. Request bodies are JSON objects and are limited to 65,536 bytes.

## Create Example

```json
{
  "local_service_id": 123,
  "product_id": 10,
  "billing_cycle": "monthly",
  "hostname": "server.example.test",
  "password": "temporary-example-value",
  "location": "hk-1",
  "os_template": "ubuntu-24.04"
}
```

The caller cannot submit an upstream client ID, upstream service ID, price, currency, arbitrary Configurable Option name, or arbitrary Custom Field name. Optional standard identifiers are translated only through the authenticated key's product policy.

## Service Response

```json
{
  "success": true,
  "status": "completed",
  "data": {
    "local_service_id": 123,
    "upstream_service_id": 456,
    "upstream_order_id": 789,
    "upstream_invoice_id": 321,
    "service_status": "active",
    "primary_ip": "192.0.2.10",
    "panel_url": "https://panel.example.test/service/456"
  },
  "operation_id": null,
  "error": null
}
```

Fields that are not available or not safely mapped may be omitted or `null`.

## Idempotency

Every write endpoint requires an idempotency key. The upstream stores the key with a canonical request hash before executing side effects.

- Repeating the same key and request returns the existing operation.
- Reusing a key with a different request returns HTTP `409`.
- Create uses one deterministic downstream service key.
- Renewal includes the target renewal boundary.
- Pending lifecycle operations reuse their persisted action key.
- Package change includes a hash of the target package request.

## Authorization and Billing

The API key's product policy must authorize the product, billing cycle, action, mapped identifiers, SSO host, and destroy permission where applicable. Service reads and writes are resolved by the authenticated key and the downstream local service ID.

Create, renew, and package-change operations use the bound reseller client's native upstream WHMCS Credit. The upstream invoice total is authoritative. `cancel_only` and `destroy` do not automatically refund Credit. Confirmed create failures may restore only the exact Credit amount stored for the failed operation.

The upstream installation must keep `Automatic Credit Use` disabled and `Credit on Downgrade` disabled.

## Common HTTP Statuses

| Status | Meaning |
|---|---|
| 200 | Query completed or write completed |
| 202 | Write accepted and still processing |
| 400 | Invalid or unsupported request input |
| 401 | Authentication failure |
| 403 | Key, IP, policy, action, or service authorization failure |
| 404 | Resource not found within the authenticated instance |
| 409 | Idempotency or service-state conflict |
| 422 | Business-rule or provisioning failure |
| 429 | Per-key rate limit exceeded |
| 500 | Sanitized internal error |

Stable errors include `AUTHENTICATION_FAILED`, `SIGNATURE_EXPIRED`, `NONCE_REUSED`, `RATE_LIMITED`, `PRODUCT_NOT_ALLOWED`, `INVALID_PRODUCT_MAPPING`, `INSUFFICIENT_CREDIT`, `SERVICE_NOT_FOUND`, `SERVICE_STATE_CONFLICT`, `PROVISIONING_FAILED`, `OPERATION_PROCESSING`, `MANUAL_REVIEW_REQUIRED`, and `INTERNAL_ERROR`.

## Logging and SSO

Passwords, API secrets, signatures, tokens, and SSO URLs are recursively redacted before operation-event persistence or downstream module logging. SSO responses are ephemeral and must contain an HTTPS URL on an allowed policy host.

## Validation Boundary

The repository contract tests use a deterministic mock API. Before production use, validate API-managed operations against a staging WHMCS installation and a real upstream Provisioning Module.
