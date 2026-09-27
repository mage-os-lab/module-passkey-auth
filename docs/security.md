# How it works and security

This page explains what the module protects against and how. To report a vulnerability, follow [SECURITY.md](../SECURITY.md).

## What the store stores

For each passkey the store keeps the public key, the credential ID, a signature counter, the transports the browser reported, the authenticator model ID (AAGUID), a name, and timestamps. There is no private key and no biometric data. A database leak does not let anyone sign in.

## Domain binding

Passkeys are bound to a domain, called the relying party ID. The module uses the host of the store's base URL for customers and the host of the admin URL for admins. The server also checks that each browser response came from the exact origin: scheme, host, and port.

A phishing site on another domain cannot get a valid response from a customer's passkey. It also means passkeys stop working if the store's domain changes. See [Configuration](configuration.md#the-store-domain).

## Challenges

Each sign-in or registration starts with a random 32-byte challenge. The server stores it in `passkey_challenge` with a random token. The token:

- works once and is deleted when used, whether the check passes or fails;
- expires after 5 minutes;
- is tied to the ceremony type, so a registration challenge can't be used to sign in;
- for registration, is tied to the customer who asked for it.

This blocks replay of old responses. The storefront renews the autofill challenge every 4 minutes, so a page left open for a long time still works.

## Accounts that don't exist

When sign-in options are requested for an email, the response has the same shape whether or not the account exists or has passkeys. For an email with no passkeys, the module returns one made-up credential ID derived from the email and the install's secret key. It is the same each time for the same email. Error messages after a failed sign-in are the same for every cause.

This also stops a browser from offering an unrelated passkey saved on the device when the typed email has none.

Real passkeys differ between authenticators in ID length and transports. The module does not hide those differences.

## Rate limits

Limits are counted in the Magento cache. They are best-effort: under heavy parallel load a few extra requests can get through.

| What | Limit | Counted per | Applies to |
|---|---|---|---|
| Sign-in options | 10 per 60 seconds | Email and IP address | Storefront, REST, GraphQL |
| Registration options | 10 per 60 seconds | Customer | Storefront, REST, GraphQL |
| Failed sign-ins | 5, then blocked until 15 minutes after the last failure | IP address | Storefront, REST, GraphQL |

Flushing the cache resets the counters.

Admin two-factor sign-in uses Magento_TwoFactorAuth's own protections.

## Ownership checks

A customer can only list, rename, or delete their own passkeys. The customer ID always comes from the session or token, never from the request. Admin revocation needs the **Revoke Customer Passkeys** permission.

## Cloned authenticators

Some authenticators increase a counter on every use. If either the stored counter or the new one is above zero, the new value must be higher than the stored one. If it isn't, the authenticator may have been copied, and the sign-in is rejected as a failed attempt.

Most synced passkeys (iCloud Keychain, Google Password Manager) always report 0, so this check doesn't apply to them.

## Change notifications

Customers are emailed when a passkey is added or removed. Someone with access to an account can't quietly add their own passkey without the owner hearing about it. Keep **Email Customer When Passkeys Change** on unless you send your own notification.

## Passwords

Passkeys are added alongside the password. They don't replace it. An account is only as strong as its password and its email inbox, since password reset still works.

## Logging

| Event | Where |
|---|---|
| Rejected sign-in (unknown passkey, bad signature, wrong origin, counter not increased) | Warning in `var/log/system.log`, plus the `passkey_authentication_failure` event |
| Failed registration | Error in `var/log/system.log`, plus the `passkey_registration_failure` event |
| Notification email failed | Error in `var/log/system.log` |
| Admin passkey registered or failed | Magento_TwoFactorAuth alerts, and `var/log/system.log` for failures |
| Expired challenges removed by cron | Info in `var/log/system.log` |

Logs include customer IDs and credential IDs, but not keys or challenge tokens.
