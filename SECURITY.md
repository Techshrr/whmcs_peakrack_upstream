# Security Policy

## Reporting a Vulnerability

Do not open public GitHub issues for security vulnerabilities.

Report security issues to:

security@peakrack.com

Include the affected version, affected module, issue description, reproduction conditions, potential impact, and suggested mitigation when available. Do not include production credentials or customer data.

## Supported Versions

| Version | Supported |
|---|---|
| 1.0.x | Yes |
| < 1.0 | No |

## Sensitive Data

Do not include production API keys, API secrets, HMAC signatures, nonces from live traffic, database credentials, WHMCS license information, customer records, provider credentials, server passwords, SSO URLs, or private signing keys in reports.

If an API secret may have been exposed, disable the affected key or rotate its secret from the upstream Addon admin page before sharing diagnostic details.

## Public Issues

General bugs and feature requests may be submitted through GitHub Issues. Security vulnerabilities must be reported privately by email.
