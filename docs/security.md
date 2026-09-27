# How it works and security

This page explains what the module protects against and how. To report a vulnerability, follow [SECURITY.md](../SECURITY.md).

## What the store stores

For each passkey the store keeps the public key, the credential ID, a signature counter, the transports the browser reported, the authenticator model ID (AAGUID), a name, and timestamps. There is no private key and no biometric data. A database leak does not let anyone sign in.

## Domain binding

Passkeys are bound to a domain, called the relying party ID. The module uses the host of the store's base URL for customers and the host of the admin URL for admins. The server also checks that each browser response came from the exact origin: scheme, host, and port.

A phishing site on another domain cannot get a valid response from a customer's passkey. It also means passkeys stop working if the store's domain changes. See [Configuration](configuration.md#the-store-domain).

## Websites with separate customer accounts

When **Share Customer Accounts** is **Per Website**, each website has its own customers. Websites can still share a domain, and a passkey works on every website that uses its domain. So on sign-in the module also checks that the passkey's customer belongs to the current website. If not, the sign-in is rejected the same way as an unknown passkey.

With global account sharing there is no such check, because one account is valid on every website.

## Challenges

Each sign-in or registration starts with a random 32-byte challenge. The server stores it in `passkey_challenge` with a random token. The token:

- works once and is deleted when used, whether the check passes or fails;
- is claimed atomically: if two requests send the same token at the same time, only one of them gets it;
- expires after 5 minutes;
- is tied to the ceremony type, so a registration challenge can't be used to sign in;
- for registration, is tied to the customer who asked for it.

This blocks replay of old responses. The storefront renews the autofill challenge every 4 minutes, so a page left open for a long time still works.

## Accounts that don't exist

When sign-in options are requested for an email, the response has the same shape whether or not the account exists or has passkeys. For an email with no passkeys, the module returns one made-up credential ID derived from the email and the install's secret key. It is the same each time for the same email.

This also stops a browser from offering an unrelated passkey saved on the device when the typed email has none.

Real passkeys differ between authenticators in ID length and transports. The module does not hide those differences.

After a failed sign-in, the storefront and GraphQL show one message for every cause, except the failed sign-in limit (see [Rate limits](#rate-limits)). REST shows the message of the check that failed, so challenge problems can differ, for example "Invalid or expired challenge token." or "Challenge has expired." An unknown passkey and a passkey that fails verification both give "Passkey verification failed. Please try again.", so no REST message tells whether an account exists.

## Rate limits

Limits are counted in the Magento cache. They are best-effort: under heavy parallel load a few extra requests can get through.

| What | Limit | Counted per | Applies to |
|---|---|---|---|
| Sign-in options | 10 per 60 seconds | Email (trimmed and lowercased) and IP address | Storefront, REST, GraphQL |
| Registration options | 10 per 60 seconds | Customer | Storefront, REST, GraphQL |
| Failed sign-ins | 5, then blocked until 15 minutes after the last failure | IP address | Storefront, REST, GraphQL |

Flushing the cache resets the counters.

When the failed sign-in limit is hit, the storefront, REST, and GraphQL all return "Too many failed passkey attempts. Please try again later." The storefront replies with HTTP 429. The message says nothing about the account.

The failed sign-in limit is checked in the service layer, so the storefront, REST, and GraphQL share one counter per IP address. A headless frontend that calls REST or GraphQL from its own server sends every customer's sign-in from that server's IP, so all of them share one counter.

Admin two-factor sign-in uses Magento_TwoFactorAuth's own protections.

### The client IP address

The IP-based limits need the visitor's real IP address. Magento reads it from `REMOTE_ADDR`. Behind a load balancer, CDN, or reverse proxy, `REMOTE_ADDR` is the proxy's address, and all visitors share one counter.

To fix this, do one of these:

- Have the web server set `REMOTE_ADDR` from the proxy's header, for example with nginx's `real_ip` module or Apache's `mod_remoteip`, trusting only your proxies.
- Or pass the header to `Magento\Framework\HTTP\PhpEnvironment\RemoteAddress` as its `alternativeHeaders` argument in your own module's `etc/di.xml`, for example `HTTP_X_FORWARDED_FOR`.

Only use a header your proxy always sets. If visitors can send it themselves, they can pick any IP address and get around the limits.

## Ownership checks

A customer can only list, rename, or delete their own passkeys. The customer ID always comes from the session or token, never from the request. Admin revocation needs the **Revoke Customer Passkeys** permission.

## Login as Customer

While an admin is signed in to the storefront as a customer with Magento's Login as Customer feature, passkey registration is refused and the enrollment banner is hidden. The admin can't add a passkey of their own to the customer's account.

Known limitation: this check reads the storefront session. A customer token from the `generateCustomerTokenAsAdmin` GraphQL mutation looks the same as the customer's own token, so REST and GraphQL registration with such a token is not blocked. If notification emails are on, the customer still gets the "passkey added" email.

## Cloned authenticators

Some authenticators increase a counter on every use. If either the stored counter or the new one is above zero, the new value must be higher than the stored one. If it isn't, the authenticator may have been copied, and the sign-in is rejected as a failed attempt.

Most synced passkeys (iCloud Keychain, Google Password Manager) always report 0, so this check doesn't apply to them.

## Change notifications

Customers are emailed when a passkey is added or removed. Someone with access to an account can't quietly add their own passkey without the owner hearing about it. Keep **Email Customer When Passkeys Change** on unless you send your own notification.

## Passwords

Passkeys are added alongside the password. They don't replace it. An account is only as strong as its password and its email inbox, since password reset still works.

## Logging

Routine rejections are logged as warnings with a `reason` in `var/log/system.log`. Unexpected errors are logged with an `exception` entry, which Magento writes to `var/log/exception.log` instead.

| Event | Where |
|---|---|
| Passkey rejected at sign-in (unknown passkey, passkey of another website, bad signature, wrong origin, counter not increased) | Warning in `var/log/system.log`, plus the `passkey_authentication_failure` event |
| Other rejected sign-ins (passkeys turned off, bad or expired challenge, malformed response, failed sign-in limit) | Warning in `var/log/system.log` |
| Failed registration (response failed verification, or the passkey could not be saved) | Warning in `var/log/system.log`, plus the `passkey_registration_failure` event when verification failed |
| Unexpected error in a storefront passkey request | Error in `var/log/exception.log` |
| Sign-in succeeded but the token or the passkey's counter could not be saved | Error in `var/log/exception.log` |
| Notification email failed | Error in `var/log/exception.log` |
| Admin revoke failed | Error in `var/log/exception.log` |
| Admin passkey registered | Magento_TwoFactorAuth alert, and info in `var/log/system.log` |
| Admin passkey registration or sign-in failed | Magento_TwoFactorAuth alert, and a warning in `var/log/system.log` when the passkey check failed |
| Expired challenges removed by cron | Info in `var/log/system.log` |

Logs include customer IDs and credential IDs, but not keys or challenge tokens.
