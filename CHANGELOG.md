# Changelog

All notable changes to this project are documented in this file.

This project follows Semantic Versioning where practical.

## [1.1.0] - 2026-06-08

### Added

- Added optional Client Area onboarding for reseller API access with eligibility checks and manual administrator approval.
- Added policy templates so one approved application can create multiple product policy rows for the new API key.
- Added one-time API Secret display for approved clients and client-initiated reset limits of three resets per calendar month with at least ten days between resets.
- Added administrator secret reset for approved onboarding applications. Administrator resets bypass client reset limits and write audit records.
- Added setup-guide data for approved clients, including downstream module download URL, API base URL, public key, and portable Cron example.

### Changed

- Added Addon configuration fields for allowed client groups, downstream module download URL, integration terms URL, default onboarding rate limit, and required outbound IP allowlists.

### Security

- Client Area onboarding actions use a separate CSRF token and derive ownership only from the logged-in WHMCS client session.

## [1.0.0] - 2026-06-05

### Added

- Added the upstream `peakrack_upstream_api` Addon Module with API key, product policy, managed-service, operation, worker, and system-health administration.
- Added the authenticated API v1 endpoints for health, catalog, service lifecycle, service status, SSO, and operation status.
- Added HMAC-SHA256 request authentication, nonce replay protection, IP allowlists, rate limiting, idempotency, operation locks, retry verification, and recursive redaction.
- Added upstream WHMCS Credit billing flows for create, renew, and package change, including confirmed create-failure compensation.
- Added the downstream `peakrackupstream` Provisioning Module with lifecycle functions, read-only Client Area output, optional SSO, and pull-based status synchronization.
- Added unit tests, mock API contract tests, release packaging checks, and installation-tree synchronization tooling.
- Added WHMCS-root release package layouts for both modules so administrators can extract packages from the WHMCS root without creating the wrong module path.

### Changed

- Upstream Addon activation now installs the module while reporting unsafe Credit settings through the activation message and System Health. API operations remain blocked until `Automatic Credit Use` and `Credit on Downgrade` are disabled.
- Reduced composite unique index string lengths used by idempotency and nonce tables for older MySQL/MariaDB InnoDB index compatibility.
- Added explicit short database index names and repair of missing unique indexes after a failed partial schema install.
- Schema activation failures now include a short sanitized database error summary to help administrators identify local database compatibility or permission problems.
- Product Policy list fields now accept JSON, comma-separated values, single values, and slash-escaped JSON submitted by the WHMCS request layer.

### Security

- API secrets are encrypted with WHMCS helpers and displayed only when created or rotated.
- Sensitive request, response, audit, and module-log fields are recursively redacted before persistence or logging.
