# Configuration

Settings are under **Stores > Configuration > Customers > Customer Configuration > Passkey Authentication**.

## Settings

| Setting | Config path | Default | What it does |
|---|---|---|---|
| Enable Passkey Authentication | `customer/passkey/enabled` | No | Turns on all customer passkey features: login button, autofill, My Account page, banner, REST, and GraphQL. |
| Prompt After Password Login | `customer/passkey/prompt_after_login` | Yes | Shows the "Sign in faster with a passkey" banner on account pages after a password sign-in. |
| Prompt After Account Creation | `customer/passkey/prompt_on_registration` | No | Shows the same banner right after a customer creates an account. |
| Email Customer When Passkeys Change | `customer/passkey/notify_credential_changes` | Yes | Emails the customer when a passkey is added to or removed from their account. |
| Passkey Notification Email Sender | `customer/passkey/notification_email_identity` | General Contact | Sender identity for those emails. |
| Passkey Added Email Template | `customer/passkey/added_email_template` | Passkey Added to Account | Template for the "added" email. |
| Passkey Removed Email Template | `customer/passkey/removed_email_template` | Passkey Removed from Account | Template for the "removed" email. |

### The enrollment banner

The banner only shows when all of these are true:

- The customer is signed in and has no passkeys.
- The matching prompt setting is on. In the session where the customer created their account, **Prompt After Account Creation** applies. Otherwise **Prompt After Password Login** applies.
- The browser supports passkeys.
- The customer has not clicked **Not now** in the last 30 days, and has clicked it fewer than 3 times. This is stored in the browser, so it is per device.

The banner does not show on the **My Account > Passkeys** page itself.

### Scope

All settings can be set at Default and Website scope. To offer passkeys on one website only, leave **Enable Passkey Authentication** off at Default Config and turn it on for that website.

Notification emails use the settings of the website the customer belongs to.

## The store domain

Every passkey is bound to the domain it was created on. The module takes that domain from the store's base URL. There is nothing to configure.

- A passkey created on `www.example.com` does not work on `example.com` or `shop.example.com`.
- If you change the store's domain, every customer passkey stops working. Customers need to sign in with their password and add a new passkey. Tell customers before you move domains.
- Store views on the same domain share passkeys. Websites on different domains do not. A customer with one account across two domains needs a passkey on each.
- If **Share Customer Accounts** is set to **Per Website** (under **Customer Configuration > Account Sharing Options**), a passkey only signs in on its own customer's website. This holds even when websites share a domain.
- The store must be served over HTTPS at the base URL. A mismatch between the URL in the browser and the configured base URL (including port) makes passkey requests fail.

Admin passkeys for two-factor authentication are bound to the admin URL's domain instead. See [Admin two-factor authentication](admin-2fa.md).

## Fixed values

These are set in code and have no admin setting:

| Value | Setting |
|---|---|
| Maximum passkeys per customer | 10 |
| Time to complete a passkey prompt | 60 seconds |
| Challenge lifetime | 5 minutes |
| User verification (fingerprint, face, or PIN) | Preferred for customers, required for admins |
| Attestation | Not requested |
| Discoverable passkeys (sign in without typing an email) | Preferred for customers |
| Signature algorithms | ES256, RS256 |
| Rate limits | See [How it works and security](security.md#rate-limits) |

Developers can change most of these. See the [Developer guide](developer-guide.md#changing-webauthn-settings).
