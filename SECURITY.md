# Security Policy

## Reporting a Vulnerability

Please **do not** open a public GitHub issue for security vulnerabilities.

Report suspected vulnerabilities privately via
[GitHub Security Advisories](https://github.com/mage-os-lab/module-passkey-auth/security/advisories/new)
for this repository. You should receive an acknowledgement within a few
business days. Please include reproduction steps, the module version, and the
Magento/Mage-OS and PHP versions involved.

We ask that you give us a reasonable window to release a fix before public
disclosure, and we will credit reporters in the release notes unless you
prefer otherwise.

## Supported Versions

Security fixes are provided for the latest minor release line. Older
releases should upgrade to the newest version.

## Security Model (summary)

This module implements WebAuthn/FIDO2 authentication for Magento customer
accounts and admin two-factor authentication. Key properties relied on:

- **Origin & RP ID binding**: The Relying Party ID and allowed origins are
  derived from the store's base URL; assertions from other origins fail
  validation in `web-auth/webauthn-lib`. Note that changing the store's
  domain invalidates all registered passkeys by design.
- **Single-use, short-lived challenges**: Challenge tokens are stored
  server-side, bound to a ceremony type (and customer where applicable),
  consumed on first use, and expire after 5 minutes; expired rows are also
  swept by cron.
- **Anti-enumeration**: Authentication options requests return a valid,
  unusable response for unknown emails, and verification errors are
  deliberately generic.
- **Rate limiting**: Options generation and failed verifications are rate
  limited per identifier/IP across the storefront, REST, and GraphQL entry
  points. The cache-based counters are best-effort, not strictly atomic.
- **Ownership enforcement**: Credential list/rename/delete operations verify
  the credential belongs to the authenticated customer; admin revocation is
  gated by a dedicated ACL resource.
- **Sign-count monitoring**: A decreasing signature counter (possible cloned
  authenticator) is logged as a warning.
- **Change visibility**: Adding or removing a passkey triggers a customer
  notification email (configurable).

Reports about weaknesses in any of the properties above are especially
welcome.
