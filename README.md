# PeakRack Upstream WHMCS Integration

WHMCS-to-WHMCS reseller provisioning through a signed upstream API and a downstream Provisioning Module.

> Official repository: https://github.com/Techshrr/whmcs_peakrack_upstream
> License: Apache-2.0

## Overview

This repository contains two WHMCS modules:

- `peakrack_upstream_api`, an upstream Addon Module that manages reseller API keys, product policies, WHMCS Credit billing, idempotent operations, and a signed JSON API.
- `peakrackupstream`, a downstream Provisioning Module that maps WHMCS service actions to the upstream API and synchronizes confirmed service state.

The upstream WHMCS remains authoritative for reseller Credit, upstream orders, invoices, services, and provider delivery. The downstream WHMCS remains authoritative for its own clients and billing. Downstream end-customer records are not created upstream.

## Implemented Features

- HMAC-SHA256 authentication with timestamp, nonce replay protection, optional IP allowlists, and per-key rate limits.
- API key binding to one upstream reseller client and one permanent downstream instance ID.
- Product, billing-cycle, action, location, OS template, delivery-field, SSO-host, and destroy permission policies.
- Native upstream WHMCS Credit billing for create, renew, and package-change operations.
- Idempotent synchronous and queued operation handling with retries, verification, compensation, and manual-review states.
- Upstream lifecycle coordination through WHMCS Local API calls and the upstream products' assigned Provisioning Modules.
- Downstream create, suspend, unsuspend, terminate, renew, package change, status sync, read-only Client Area output, and optional SSO.
- Recursive redaction before operation-event persistence and downstream `logModuleCall` logging.
- CLI worker and synchronization jobs.

## Requirements

- WHMCS `9.0.x` on both installations.
- PHP `8.2` or later. PHP 8.3 is the primary development runtime.
- PHP cURL extension on the downstream installation.
- HTTPS with a valid certificate for the upstream API.
- Upstream products with assigned Provisioning Modules and valid reseller-currency pricing.
- A dedicated upstream reseller client with sufficient WHMCS Credit.
- Upstream WHMCS **Automatic Credit Use disabled**.
- Upstream WHMCS **Credit on Downgrade disabled**.
- A configured non-capturing upstream order payment method, such as the default `mailin` module when appropriate for the installation.

The source is readable PHP and does not require ionCube.

## Installation

1. Back up both WHMCS installations and databases.
2. Copy the upstream module directory to the upstream WHMCS installation:

   `/modules/addons/peakrack_upstream_api/`

3. Copy the downstream module directory to the downstream WHMCS installation:

   `/modules/servers/peakrackupstream/`

4. In the upstream WHMCS admin area, activate **PeakRack Upstream API** under Addon Modules.
5. Confirm that `Automatic Credit Use` and `Credit on Downgrade` are disabled. The Addon can be activated while either setting is still enabled, but System Health and API operations will continue to report blocking errors until both settings are disabled.
6. Configure the Addon payment method and worker batch size.
7. Create an API key for the reseller client. Store the API secret when it is displayed because it is not listed again.
8. Create at least one product policy for the API key. The upstream product must have pricing for the reseller currency and an assigned Provisioning Module.
9. In the downstream WHMCS admin area, create a server using module **PeakRack Upstream**, then assign it to downstream products. The technical module name is `peakrackupstream`, a single lowercase word required by WHMCS Provisioning Module naming rules.
10. Install both Cron entries shown below.

No WHMCS core files are modified.

### Release Package Types

Each module is packaged in two layouts:

- `peakrack-upstream-api-v1.0.0.zip` and `peakrackupstream-v1.0.0.zip` contain only the module directory. Extract or copy them into the matching parent directory: `/modules/addons/` for the upstream Addon and `/modules/servers/` for the downstream Provisioning Module.
- `peakrack-upstream-api-whmcs-root-v1.0.0.zip` and `peakrackupstream-whmcs-root-v1.0.0.zip` contain the `modules/...` path. Extract this package from the upstream or downstream WHMCS root.

For downstream installation, extracting `peakrackupstream-v1.0.0.zip` directly from the WHMCS root creates the wrong path and WHMCS will not list the module. Extract this package from the downstream WHMCS root: `peakrackupstream-whmcs-root-v1.0.0.zip`. Alternatively, place the `peakrackupstream` directory directly under `/modules/servers/`.

## Upstream Addon Configuration

| Option | Description | Default |
|---|---|---|
| Order Payment Method | Payment method used for API-created upstream orders | `mailin` |
| Worker Batch Size | Maximum operations claimed by each one-minute worker run | `25` |

The Addon admin pages manage API keys, product policies, operations, managed services, and system health. API secrets are shown only when created or rotated. An empty IP allowlist requires explicit administrator acknowledgement.

## Downstream Server Configuration

| WHMCS Server Field | Value |
|---|---|
| Hostname | Upstream WHMCS hostname without scheme or path |
| IP Address | Optional; not used to build the API URL |
| Username | Upstream API public key |
| Password | Upstream API secret |
| Access Hash | Optional upstream WHMCS base path, for example `/billing` |
| Secure | Required |
| Port | HTTPS port, normally `443` |

Each downstream instance must use its own API key.

## Downstream Product Configuration

| Option | Description | Default |
|---|---|---|
| Upstream Product ID | Product ID authorized by the upstream API policy | Required |
| Upstream Billing Cycle | Authorized upstream cycle or the local service cycle | `auto` |
| Upstream Location | Optional policy-managed location identifier | Empty |
| Default OS Template | Optional policy-managed OS identifier | Empty |
| Terminate Mode | End-of-period cancellation or immediate destroy when policy permits it | `cancel_only` |
| Request Timeout | Signed API request timeout in seconds, clamped to 5 through 120 | `30` |

## Cron Jobs

Run the upstream worker every minute:

```cron
* * * * * php /path/to/whmcs/modules/addons/peakrack_upstream_api/cron/worker.php
```

Run downstream service synchronization every five minutes:

```cron
*/5 * * * * php /path/to/whmcs/modules/servers/peakrackupstream/cron/sync.php
```

Both scripts refuse browser execution. The downstream sync uses a non-blocking process lock and updates local service status only after a valid completed upstream response.

## Operational Notes

- Create uses a deterministic idempotency key. Pending lifecycle actions reuse their stored key.
- Renewal keys include the target renewal boundary. Package-change keys include a canonical target hash.
- `cancel_only` schedules cancellation without an automatic Credit refund.
- `destroy` requires both downstream product configuration and upstream policy permission.
- The upstream API controls renewal. Disable conflicting independent automation for API-managed services.
- The downstream Client Area shows only cached status, primary IP, safe HTTPS panel URL, last synchronization time, and optional SSO.
- Customer-controlled lifecycle buttons are intentionally not included.
- Addon deactivation preserves Addon-owned data.

## Validation Boundary

Unit and mock contract tests validate module behavior, signatures, envelopes, and idempotency without creating a billable provider order. Validate the complete flow against a staging WHMCS installation and a real upstream Provisioning Module before claiming provider compatibility or enabling production traffic.

For an opt-in safe integration check against an installed WHMCS tree, first synchronize the module source, then run the `whmcs-safe` group:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/sync-installed.ps1
$env:PEAKRACK_WHMCS_TEST = '1'
php tests/run.php --group whmcs-safe
Remove-Item Env:PEAKRACK_WHMCS_TEST
```

This check requires a bootable WHMCS CLI environment, including its database connection and ionCube Loader. It loads module entry points, installs only Addon-owned tables, verifies that deactivation preserves them, and compares source and installed module hashes. It does not create an order or invoke a provider.

## API Documentation

See [docs/api-v1.md](docs/api-v1.md).

## Release Packages

Run:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/check-release.ps1
```

The script runs tests and lint checks, scans tracked files for credential patterns, creates separate upstream and downstream ZIP files under `release-packages/`, and writes SHA-256 checksum files.

## Upgrade

See [UPGRADE.md](UPGRADE.md).

## Chinese Documentation

See [README.zh-CN.md](README.zh-CN.md).

## Security

Do not commit or include production API keys, API secrets, database credentials, WHMCS license data, customer data, provider credentials, or private signing keys in logs or issue reports.

To report a security issue, see [SECURITY.md](SECURITY.md).

## License

This project is licensed under Apache-2.0. See [LICENSE](LICENSE) and [NOTICE](NOTICE).

The same license and notice files are included in both deployable module directories.
