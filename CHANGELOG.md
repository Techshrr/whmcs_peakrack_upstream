# Changelog

All notable changes to this project are documented in this file.

This project follows Semantic Versioning where practical.

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
- Schema activation failures now include a short sanitized database error summary to help administrators identify local database compatibility or permission problems.

### Security

- API secrets are encrypted with WHMCS helpers and displayed only when created or rotated.
- Sensitive request, response, audit, and module-log fields are recursively redacted before persistence or logging.
