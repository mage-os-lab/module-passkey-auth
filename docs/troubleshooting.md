# Troubleshooting

Check `var/log/system.log` first. Most failures log a warning with a `reason` there, even when the customer only sees a generic message. Unexpected errors go to `var/log/exception.log`. See [Logging](security.md#logging).

## The passkey button, menu link, or banner doesn't appear

- **Enable Passkey Authentication** must be **Yes** for the website. See [Scope](configuration.md#scope).
- Flush the cache after changing settings.
- The page must be served over HTTPS. The button stays hidden when the browser doesn't support passkeys or the page isn't secure.
- The banner has extra rules: the customer has no passkeys, the matching prompt setting is on, and the customer hasn't dismissed it recently on this device. See [The enrollment banner](configuration.md#the-enrollment-banner).
- On Hyvä, check that the theme's CSS build includes this module's templates. See [Themes](installation.md#themes).

## "Passkey authentication is not enabled."

Passkeys are turned off for the store the request went to. For REST and GraphQL, check which store the request targets (the store code in the URL or the `Store` header) and that passkeys are on for that store's website.

## Sign-in or registration fails with a generic error

Look in `var/log/system.log` for a warning that mentions "passkey", and read its `reason`. Common reasons:

| Log mentions | Cause | Fix |
|---|---|---|
| origin, RP ID, or host | The browser URL doesn't match the store's base URL (different host, `www`, port, or `http`) | Serve the store at exactly its configured secure base URL |
| unknown credential | The passkey was deleted, revoked, or created on another domain or install. With **Share Customer Accounts** set to **Per Website**, it can also be a passkey of a customer on another website that shares this domain ("Passkey credential belongs to another website.") | The customer signs in with their password and adds a new passkey on this website |
| Invalid or expired challenge token / Challenge has expired | The prompt took more than 5 minutes, or the same request was sent twice | Try again |
| counter | The authenticator's counter didn't increase, which can mean it was copied | See [Cloned authenticators](security.md#cloned-authenticators) |

## Passkeys stopped working for everyone

The store's domain changed. Passkeys only work on the domain where they were created. Customers need to sign in with their password and add new passkeys. See [The store domain](configuration.md#the-store-domain).

## Autofill doesn't offer the passkey

- The browser must support passkey autofill. Recent Chrome, Edge, and Safari do. Some Firefox versions and older browsers don't.
- The customer must have a passkey for this domain saved in the browser or password manager they're using.
- Autofill only runs on the login page and the Luma checkout. For other forms, see [Adding autofill to another form](developer-guide.md#adding-autofill-to-another-form).
- The **Sign in with Passkey** button works whether or not autofill does.

## "This device already has a passkey for your account."

The browser found an existing passkey for this account on the device and won't make a second one. The customer can sign in with the existing one. To replace it, delete it first under **My Account > Passkeys**.

## "Passkeys can't be added while an admin is signed in as this customer."

An admin used Login as Customer and tried to add a passkey. This is blocked on purpose, so an admin can't add a passkey of their own to a customer's account. See [Login as Customer](security.md#login-as-customer).

## "The account sign-in was incorrect or your account is disabled temporarily"

The passkey was accepted, but the account is locked after too many wrong passwords, or the customer's group is excluded from this website (**Customers > Customer Groups**). The log in `var/log/system.log` says which. A locked account unlocks by itself after the **Lockout Time** set under **Stores > Configuration > Customers > Customer Configuration > Password Options**. To unlock it now, open the customer in the admin and click **Unlock** at the top of the page.

Failed passkey sign-ins never lock an account. See [Locked and unconfirmed accounts](security.md#locked-and-unconfirmed-accounts).

## "This account isn't confirmed. Verify and try again."

The passkey was accepted, but the account still needs email confirmation, or the customer changed their email address and hasn't confirmed the new one. The customer clicks the link in the confirmation email. See [Locked and unconfirmed accounts](security.md#locked-and-unconfirmed-accounts).

## "Too many passkey requests" or "Too many failed passkey attempts"

A rate limit was hit. Wait up to 15 minutes. On a shared IP address, such as an office network, several customers share the failed-sign-in limit. Flushing the cache resets all counters. See [Rate limits](security.md#rate-limits).

The customer sees the message on the storefront, and REST and GraphQL return it. The log shows it as the `reason` too.

## Behind a load balancer or CDN, everyone hits the rate limit together

The limits count by IP address. If Magento sees the proxy's IP instead of the visitor's, all visitors share one counter. Pass the real client IP to Magento, so each visitor is counted separately. See [The client IP address](security.md#the-client-ip-address).

A headless frontend that calls REST or GraphQL from its own server has the same problem: every sign-in comes from the server's IP.

## Notification emails aren't sent

- **Email Customer When Passkeys Change** must be on for the customer's website.
- Check that store emails work in general (**Stores > Configuration > Advanced > System > Mail Sending Settings**).
- Look for "Failed to send passkey notification email" in `var/log/exception.log`.
- Deleting a customer account doesn't send the "removed" email.

## Expired challenges pile up in `passkey_challenge`

Cron isn't running. Check that Magento cron is set up and that the `passkey_challenge_cleanup` job runs in the `default` group. Expired challenges are rejected either way, so this only affects table size.

## An admin can't complete two-factor sign-in

- If the message says the admin domain has changed, reset the user's passkey. See [Reset an admin's passkey](admin-2fa.md#reset-an-admins-passkey).
- If the admin lost their authenticator, run `bin/magento security:tfa:reset <username> passkey` and have them register a new one.
- Admin passkeys require user verification. A security key without a PIN set may be refused. Set a PIN on the key.
