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
  and expire after 5 minutes. The first request that uses a token claims it
  atomically by deleting its row, so concurrent requests with the same token
  cannot both succeed. Expired rows are also swept by cron.
- **Anti-enumeration**: Authentication options for an email with no
  passkeys (or no account) carry one stable decoy credential descriptor
  derived from the install's crypt key, so they have the same shape as an
  account with one passkey. An unknown credential and a failed verification
  return the same error. The storefront and GraphQL use one generic message
  for every sign-in failure except the failed sign-in limit; REST also
  reports challenge errors (such as an expired challenge). Neither says
  anything about the account. Descriptor
  details (ID length, transports) of real credentials vary by authenticator
  and are not disguised.
- **Wrong-account sign-in**: Because the decoy is never empty, a passkey
  sign-in started for an email without passkeys cannot be answered by an
  unrelated passkey saved on the device.
- **Rate limiting**: Sign-in options are limited per email (trimmed and
  lowercased) and IP address, registration options per customer, and failed
  sign-ins per IP address. The checks run in the service layer, so the
  storefront, REST, and GraphQL share the same counters. IP-based limits
  rely on Magento seeing the real client IP behind a proxy. The cache-based
  counters are best-effort, not strictly atomic.
- **Ownership enforcement**: Credential list/rename/delete operations verify
  the credential belongs to the authenticated customer, whose ID comes from
  the session or token, never the request. Admin revocation is gated by a
  dedicated ACL resource.
- **Per-website accounts**: When customer accounts are shared per website,
  a passkey whose customer belongs to another website is rejected like an
  unknown credential, even if the websites share a domain.
- **Login as Customer**: Passkey registration is refused while an admin is
  signed in to the storefront as the customer. This reads the storefront
  session. A customer token from `generateCustomerTokenAsAdmin` cannot be
  told apart from the customer's own, so REST and GraphQL registration with
  such a token is not blocked. This is a known limitation.
- **Sign-count check**: When the stored or the new signature counter is
  above zero, an assertion whose counter does not increase (possible cloned
  authenticator) is rejected. Authenticators that always report zero are
  not checked.
- **Change visibility**: Adding or removing a passkey triggers a customer
  notification email (configurable).

Reports about weaknesses in any of the properties above are especially
welcome.
