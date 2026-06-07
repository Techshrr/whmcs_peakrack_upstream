# PeakRack Upstream v1.1 Self-Service Onboarding Design

Status: Approved conversational design, pending written spec review

Date: 2026-06-07

Official repository: https://github.com/Techshrr/whmcs_peakrack_upstream

## 1. Objective

Version `v1.1.0` extends the `v1.0.0` WHMCS-to-WHMCS upstream integration with
deployment and admin experience improvements. The primary addition is a
controlled self-service onboarding flow:

1. A reseller client submits an integration application from the upstream WHMCS
   Client Area.
2. The upstream administrator reviews the application manually.
3. On approval, the Addon creates an API key, applies one policy template, and
   shows the API Secret to the client exactly once.
4. The client downloads the downstream Provisioning Module and follows a
   configuration guide generated from the approved application and key.

The feature reduces manual credential handoff without allowing anonymous or
automatic activation.

## 2. Baseline

The `v1.0.0` implementation remains the baseline:

- The upstream Addon Module manages API keys, product policies, operations,
  services, worker status, and health checks.
- The downstream Server/Provisioning Module connects to the upstream API using
  HMAC-signed requests.
- API keys represent downstream WHMCS instances and bind to one upstream
  reseller client.
- Product access is controlled by explicit upstream product policy rows.
- Background processing remains handled by the upstream worker cron and the
  downstream sync cron.

Version `v1.1.0` adds onboarding and operational UI around that model. It does
not replace the v1 API contract.

## 3. Confirmed Decisions

- Onboarding model: Client Area self-service application with mandatory
  upstream administrator approval.
- Approval model: The administrator approves an application by selecting one
  policy template.
- Policy template granularity: One template can contain multiple product policy
  items and can create multiple Product Policy rows for a single API key.
- API Secret behavior: Generated secret is displayed once after approval. Later
  viewing is not allowed; the client can only reset it.
- Client self-reset behavior:
  - The old secret becomes invalid immediately.
  - The new secret is displayed once.
  - A client can reset at most 3 times per natural month.
  - A client reset requires at least 10 days since the previous client reset.
  - An administrator can reset or bypass the limits manually, with audit
    logging.
- Application eligibility requires:
  - Verified email.
  - No overdue invoices.
  - Active client account status.
  - Membership in a configured allowed client group.
- Client Area delivery after approval includes:
  - Downstream module download link.
  - Configuration wizard and setup guide.
  - Public API key.
  - One-time API Secret display when available.
  - API endpoint and cron examples.
- Application form fields:
  - Company or brand name.
  - Downstream WHMCS domain.
  - Downstream server outbound IP list.
  - Expected sales product or business type.
  - Telegram contact, required.
  - QQ contact, optional.
  - Contact phone, optional.
  - Notes, optional.
  - Integration terms agreement, required.
- Status model for v1.1:
  - Implemented states: `pending`, `approved`, `rejected`.
  - Reserved state: `needs_info`, not exposed as an active v1.1 workflow.

## 4. Non-Goals

The following items are intentionally outside `v1.1.0`:

- Automatic approval without administrator review.
- Anonymous applications.
- Directly writing configuration into the downstream WHMCS installation.
- Automatic downstream WHMCS detection or pairing beyond the submitted domain
  and outbound IP list.
- Upstream-to-downstream webhooks.
- Automatic product catalog synchronization.
- Repeated viewing of an existing API Secret.
- KYC or document verification.
- Customer-controlled service actions beyond the existing downstream module
  behavior.
- Changing the public API v1 route contract.

## 5. Addon Configuration

The upstream Addon gains configuration fields used by onboarding:

- `Allowed Client Group IDs`: comma-separated WHMCS client group IDs. At least
  one group is required before client applications can be submitted.
- `Downstream Module Download URL`: URL shown to approved clients for the
  downstream module package. This is configurable and not hardcoded.
- `Integration Terms URL`: URL shown next to the required agreement checkbox.
- `Default API Rate Limit`: fallback per-minute rate limit for keys created
  through onboarding.
- `Require Outbound IP Allowlist`: enabled by default. When enabled, at least
  one valid IP or CIDR entry is required in the application.

Existing manual API key and policy management remains available for operators.

## 6. Components

### 6.1 Eligibility Service

The eligibility service checks the currently logged-in WHMCS client before the
application form is shown or submitted. It returns a list of human-readable
blocking reasons instead of a generic failure.

Checks:

- Client is authenticated.
- Client status is `Active`.
- Client email is verified.
- Client belongs to one of the configured allowed client groups.
- Client has no overdue invoices.
- Client does not already have an active `pending` or `approved` application.

Rejected applications do not block resubmission.

### 6.2 Client Area Onboarding Controller

The upstream Addon exposes a Client Area page for reseller onboarding. The page
uses WHMCS-compatible Smarty templates and avoids theme-specific assumptions.

Client states:

- Not eligible: Show blocking reasons and do not render the submission form.
- Eligible, no active application: Show the application form.
- Pending: Show the submitted data, current status, and expected review path.
- Rejected: Show the rejection message and allow a new submission.
- Approved: Show integration details, download link, configuration guide, key
  status, secret display or reset controls, and policy summary.

All Client Area actions use CSRF protection and verify that the application and
API key belong to the logged-in client.

### 6.3 Admin Review Controller

The upstream Addon admin area gains an onboarding review section.

Admin capabilities:

- List applications by status.
- View submitted reseller information.
- Approve a pending application by selecting one enabled policy template.
- Reject a pending application with a visible reason.
- View the generated API key record, policy template used, and audit events.
- Reset a client's API Secret with limit bypass, recorded as an admin action.

Approval is transactional. If API key creation or policy template application
fails, the application stays `pending` and no partially usable key is enabled.

### 6.4 Policy Template Manager

Policy templates let an administrator define reusable bundles of product
policies. One approved application maps to one API key and one selected
template. Applying the template creates one policy row per template item for
that API key.

Template items use the same core fields as existing Product Policies:

- Upstream product ID.
- Billing cycles list.
- Actions list.
- Locations list.
- OS template list.
- Delivery mappings.
- SSO allowed flag.
- SSO host list.
- Destroy allowed flag.
- Sort order.

Template validation reuses the same friendly comma-separated or JSON input
rules already used by Product Policies.

### 6.5 Credential Service

The credential service creates and rotates API secrets.

Rules:

- The public API key is visible after approval.
- The API Secret is stored encrypted and is never logged.
- A newly generated secret can be displayed once to the owning client.
- After the client acknowledges or leaves the one-time display state, the secret
  cannot be shown again.
- Resetting a secret immediately invalidates the previous secret because HMAC
  verification uses only the latest encrypted secret.
- Client reset limits are checked before generation.
- Admin reset bypasses client limits but still invalidates the old secret and
  creates an audit event.

Secrets are never sent by email and never shown in admin tables.

### 6.6 Audit Writer

The audit writer records onboarding and credential events with sanitized
context.

Events include:

- Application submitted.
- Application approved.
- Application rejected.
- API key generated through onboarding.
- Policy template applied.
- Secret viewed once.
- Secret reset by client.
- Secret reset by administrator.
- Reset denied because of monthly or interval limits.

Audit payloads redact secrets, passwords, tokens, API keys where appropriate,
and request signatures.

### 6.7 Download and Configuration Guide

The approved client page renders a setup guide with values from configuration
and the approved key:

- Downstream module download URL.
- Upstream API base URL.
- API public key.
- One-time API Secret when available.
- Suggested WHMCS Server configuration mapping.
- Product module selection guidance.
- Downstream sync cron example.
- Upstream API health test URL.
- Optional Nginx rewrite reminder for upstream deployments.

The guide is generated at runtime and escaped for Smarty output. It does not
hardcode server usernames or assume cPanel.

## 7. Data Model

### 7.1 `mod_peakrack_upstream_applications`

Stores reseller onboarding applications.

Columns:

- `id`
- `client_id`
- `status`
- `brand_name`
- `downstream_domain`
- `outbound_ips_json`
- `business_type`
- `telegram`
- `qq`
- `phone`
- `notes`
- `terms_accepted_at`
- `terms_accepted_ip`
- `admin_message`
- `admin_id`
- `reviewed_at`
- `api_key_id`
- `template_id`
- `secret_pending_display`
- `created_at`
- `updated_at`

Indexes:

- `client_id`
- `status`
- `api_key_id`
- A short named uniqueness guard for one active `pending` or `approved`
  application per client. Rejected applications do not block resubmission.

### 7.2 `mod_peakrack_upstream_policy_templates`

Stores reusable policy bundles.

Columns:

- `id`
- `name`
- `description`
- `enabled`
- `created_at`
- `updated_at`

Indexes:

- `enabled`
- `name`

### 7.3 `mod_peakrack_upstream_policy_template_items`

Stores one product-policy item inside a template.

Columns:

- `id`
- `template_id`
- `product_id`
- `billing_cycles_json`
- `actions_json`
- `locations_json`
- `os_templates_json`
- `delivery_mappings_json`
- `sso_allowed`
- `sso_hosts_json`
- `destroy_allowed`
- `sort_order`
- `created_at`
- `updated_at`

Indexes:

- `template_id`
- `product_id`

### 7.4 `mod_peakrack_upstream_secret_reset_events`

Stores reset limit evidence and audit support.

Columns:

- `id`
- `api_key_id`
- `client_id`
- `actor_type`
- `actor_id`
- `reset_at`
- `bypassed_limits`
- `source_ip`
- `created_at`

Indexes:

- `api_key_id`
- `client_id`
- `reset_at`

### 7.5 `mod_peakrack_upstream_audit_events`

Stores sanitized onboarding audit events.

Columns:

- `id`
- `event_type`
- `actor_type`
- `actor_id`
- `client_id`
- `application_id`
- `api_key_id`
- `sanitized_context_json`
- `created_at`

Indexes:

- `event_type`
- `client_id`
- `application_id`
- `api_key_id`
- `created_at`

## 8. Workflows

### 8.1 Client Application Submission

1. Client opens the onboarding page.
2. Eligibility service checks the client account.
3. If eligible, the client submits the form and accepts the terms.
4. Input validation normalizes domain and outbound IP list.
5. The Addon creates a `pending` application and audit event.
6. The client sees a pending status page.

### 8.2 Admin Approval

1. Administrator opens a pending application.
2. Administrator selects one enabled policy template.
3. The Addon validates that the application is still pending.
4. Inside one transaction, the Addon:
   - Creates an API key bound to the applicant client.
   - Generates and stores the encrypted API Secret.
   - Applies all policy template items to the new API key.
   - Marks the application `approved`.
   - Sets `secret_pending_display`.
   - Writes audit events.
5. The approved client can open the Client Area guide and retrieve the one-time
   API Secret.

### 8.3 Admin Rejection

1. Administrator enters a clear rejection reason.
2. The application changes from `pending` to `rejected`.
3. The reason is visible to the client.
4. The client can submit a new application after correcting the issue.

### 8.4 One-Time Secret Display

1. Approved client opens the integration guide.
2. If `secret_pending_display` is enabled, the page displays the current secret.
3. The client must confirm that the secret was saved.
4. After confirmation, the Addon clears `secret_pending_display`.
5. Later page loads show reset guidance instead of the secret.

If the client leaves before confirming, the secret remains available until it is
confirmed or reset. This avoids losing the only display because of a browser
close or network interruption.

### 8.5 Client Secret Reset

1. Client requests a reset from the approved onboarding page.
2. The Addon checks ownership, CSRF, monthly count, and 10-day interval.
3. If allowed, the Addon generates a new secret and stores it encrypted.
4. The old secret becomes invalid immediately.
5. The new secret is displayed once and a reset event is written.
6. If denied, the page shows the next eligible reset date or monthly limit
   reason.

Monthly limits use the upstream WHMCS server timezone and natural calendar
months.

### 8.6 Admin Secret Reset

1. Administrator resets from the application or key detail page.
2. Client reset limits are bypassed.
3. The old secret becomes invalid immediately.
4. The new secret is made available to the client as a one-time display.
5. The Addon writes an admin reset audit event with `bypassed_limits` enabled.

## 9. Validation Rules

- `brand_name`: required, 2 to 120 characters.
- `downstream_domain`: required host name only; schemes, paths, ports, query
  strings, and fragments are rejected.
- `outbound_ips_json`: required when IP allowlist is required; accepts IPv4,
  IPv6, IPv4 CIDR, and IPv6 CIDR entries.
- `business_type`: required, 2 to 200 characters.
- `telegram`: required; accepts `@username`, bare username, or `t.me/...`.
- `qq`: optional, 5 to 12 digits.
- `phone`: optional, digits, plus sign, spaces, and hyphens only; max 32
  characters.
- `notes`: optional, max 2000 characters.
- `terms_accepted`: required.
- Policy template JSON/list fields use the same validators as Product Policies.

All rejected fields return administrator- or client-readable messages without
printing raw secrets or stack traces.

## 10. Security Requirements

- Only authenticated clients can access the onboarding page.
- Client Area reads and actions are scoped to the logged-in client ID.
- Admin actions require WHMCS admin authentication and CSRF validation.
- API Secrets are not emailed, logged, or displayed in admin tables.
- Secret reset and one-time display events are audited.
- IP allowlists for onboarding-created keys come from submitted outbound IPs.
- Approved keys are enabled only after policy template application succeeds.
- Dynamic template output is escaped.
- Logs and audit context mask API keys, API secrets, passwords, tokens,
  request signatures, and Authorization headers.
- Deactivation does not drop onboarding tables or secrets.

## 11. Backward Compatibility

- Existing v1 API keys and product policies continue to work.
- Existing manual API key creation remains available.
- Existing downstream module configuration remains valid.
- Onboarding is optional; operators can keep using manual provisioning.
- Existing public API v1 routes and response envelopes do not change.
- Schema changes are additive.

## 12. Testing Plan

Automated coverage should include:

- Eligibility checks for verified email, overdue invoices, client status, and
  client group membership.
- Application validation for domain, IP/CIDR list, Telegram, QQ, phone, notes,
  and terms acceptance.
- Application status transitions and resubmission after rejection.
- Policy template create, update, disable, validation, and application to a new
  API key.
- Transaction rollback when template application fails during approval.
- One-time secret display, confirmation, and reset behavior.
- Client reset monthly limit and 10-day interval.
- Admin reset bypass and audit logging.
- Output escaping and secret redaction.
- Additive schema installation under WHMCS-compatible Capsule usage.
- PHP 8.2 and 8.3 lint/test coverage.

Release verification should still include repository checks, module structure
checks, `php -l` on PHP files, SHA-256 source/runtime parity when an installed
WHMCS tree is used, and CI results.

## 13. Implementation Acceptance Criteria

- A logged-in eligible reseller can submit one pending application from the
  upstream Client Area.
- Ineligible clients see exact blocking reasons and cannot submit.
- An administrator can approve a pending application with one enabled policy
  template.
- Approval creates one enabled API key and all policy rows from the selected
  template.
- The approved client can see the public key, generated guide, download link,
  and one-time API Secret.
- The same API Secret cannot be viewed repeatedly after confirmation.
- Client reset limits enforce 3 resets per natural month and at least 10 days
  between client resets.
- Administrator reset bypasses client reset limits and leaves an audit record.
- Existing v1 manual API key and Product Policy flows continue to function.
- Tests cover the new workflow and existing v1 tests continue to pass.
