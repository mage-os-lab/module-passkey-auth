# Changelog

All notable changes to this module are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [1.0.1] - 2026-09-28

### Fixed

- Fixed `bin/magento` commands to work when `Magento_TwoFactorAuth` is disabled. `security:tfa:passkey:reset-all` now receives a proxy, so the CLI works without 2FA; only running that command still needs it.

## [1.0.0] - 2026-09-27

### Breaking changes

- **Customer passkeys are off by default on new installs.** `customer/passkey/enabled` now defaults to No. On upgrade, a store that already has customer passkeys and never saved the setting in Default Config gets Yes saved in Default Config, so passkey sign-in keeps working. A Default Config value you saved yourself is kept, and so are website and store view values. (#10)
- **Enable and prompt settings are read at store scope.** Before, `Config::isEnabled()`, `isPromptAfterLoginEnabled()` and `isPromptOnRegistrationEnabled()` read Default Config only. A website-level value for these settings now takes effect. (#11)
- **Removed the unused ACL resource `MageOS_PasskeyAuth::config`.** No config section used it. Roles that granted it keep working. (#11)
- **Smaller REST passkey objects.** `GET /V1/passkey/credentials`, `PUT /V1/passkey/credentials/:entityId` and `POST /V1/passkey/registration/verify` now return `{id, name, transports, created_at, last_used_at}`, the same fields as GraphQL. `name` and `last_used_at` are left out when empty. The old full record (`entity_id`, `friendly_name`, `credential_id`, the public key record, `user_handle`, `sign_count`, `aaguid`) is no longer returned. Request parameters are unchanged. `DELETE` still returns `true`.
- **Event payloads changed.**
  - `reason` is now a fixed code, `credential_not_found` or `verification_failed`. Before, it was sometimes webauthn-lib's text. The text is now in a new `message` key.
  - `passkey_credential_remove_after`: `credential_id` is now the WebAuthn credential ID (base64). Before, it was the integer row ID, which is now `entity_id`.
  - `passkey_credential_register_after` and `passkey_authentication_success` gain `entity_id` and `credential_id`.
  - `passkey_authentication_failure` gains `customer_id` (null when no stored passkey matches) and `message`.
  - `passkey_registration_failure` gains `message`.
- **New methods on `@api` interfaces.** Custom implementations must add them.
  - `Api\Data\CredentialInterface` now extends `ExtensibleDataInterface` and has `getExtensionAttributes()` and `setExtensionAttributes()`.
  - `Api\CredentialRepositoryInterface::getList()`.
- **`CredentialManagementInterface::revokeCredential()` returns `bool`** (was `void`), and now signs the customer out everywhere after deleting the passkey. It returns `false` when the passkey was deleted but signing out failed. Custom implementations must change the return type.
- **Constructor changes in non-API classes.** If you extend these classes or configure their arguments in `di.xml`, update your code.
  - `Controller\Authentication\Verify`: drops `RateLimiter`, adds `PostLoginRedirect` and `Magento\Customer\Model\Url`.
  - `CustomerData\PasskeySection`: adds `AdminImpersonationGuard`.
  - `Controller\Registration\Options`: drops `RequestInterface`.
  - `Model\Authentication\Verifier`: adds `RateLimiter`, `RemoteAddress`, `Share`, `CustomerRepositoryInterface`, `StoreManagerInterface` and `AccountGuard`.
  - `Model\CredentialManagement`: adds `CustomerSignOut`.
  - `Model\CredentialRepository`: adds `CollectionProcessorInterface` and `CredentialSearchResultsInterfaceFactory`.
  - `Model\Registration\OptionsGenerator` and `Model\Registration\Verifier`: add `AdminImpersonationGuard`.
  - `Model\Resolver\CredentialFormatter`: new constructor with `CustomerPasskeyMapper`.
  - `Model\Resolver\VerifyAuthentication`: drops `RateLimiter`, `RemoteAddress` and `LoggerInterface`.
  - `Ui\Component\Listing\Column\CredentialActions`: adds `Escaper` before `$components`.
  - `Model\UserHandleGenerator::getOrGenerate()` now returns raw bytes, not base64.
- **Requires `web-auth/webauthn-lib` ^5.2** (was ^5.0). `composer.json` also lists the packages the code uses directly: the Magento modules, `web-auth/cose-lib` and `symfony/serializer`. In practice this needs Magento Open Source or Mage-OS 2.4.6 or later. The unit tests need PHPUnit 10.5 or later.
- **The failed sign-in limit now covers REST.** `POST /V1/passkey/authentication/verify` had no limit. It now shares the per-IP counter with the storefront and GraphQL. A headless frontend that calls REST from one server sends every customer's sign-in from that server's IP, so they all share one counter. (#11)
- **Per-website accounts need the right store in API calls.** With **Share Customer Accounts** set to **Per Website**, a passkey only signs in on its customer's website. A REST or GraphQL client that calls without the store code in the URL or the `Store` header targets the default store, so customers of other websites now get the generic sign-in failure.

### Added

- `Api\CustomerPasskeyManagementInterface` (`getPasskeys`, `renamePasskey`, `verifyRegistration`) and `Api\Data\CustomerPasskeyInterface`. The REST list, rename and registration routes use them.
- `Api\Data\CredentialSearchResultsInterface` and `CredentialRepositoryInterface::getList()`. `getList()` is not exposed over REST.
- Extension attributes on `CredentialInterface` and `CustomerPasskeyInterface`.
- `Model\PasskeyEvents` (`@api`) with the event names and reason codes.
- `WebAuthnConfigInterface` is now `@api`.
- `Model\Exception\RateLimitExceededException`, a `LocalizedException` thrown by `Model\RateLimiter` when a limit is hit.
- `Model\Registration\AdminImpersonationGuard::isImpersonated()`.
- `passkeyCore.postJson()` errors carry the HTTP status as `status`.
- `passkeyCore.hasCustomerMessage(error)`: whether a failed request's message came from the server, and so can be shown.
- `passkeyCore.t()`, which translates messages without `mage/translate`. It falls back to English.
- `Model\Authentication\AccountGuard` (account checks at sign-in) and `Model\CustomerSignOut` (sign-out after a passkey is removed).
- REST, SOAP and GraphQL passkey sign-ins fire core `customer_login`, as password token requests do. The customer's last login time is updated.
- The storefront verify endpoint returns `redirect_url`: the page a password sign-in would land on. The login page follows it instead of reloading. `passkeyCore.completeSignIn()` does this, and `startConditional`'s `onSuccess` now receives the verify reply. Checkout autofill still reloads the page.
- A user manual in `docs/`. (#11)
- README screenshots. (#10)
- License headers on source files, and `LICENSE.txt` (OSL-3.0). (#12)
- CI runs on PHP 8.5.

### Changed

- Routine rejections are logged as warnings with a `reason` in `var/log/system.log`. Unexpected errors still go to `var/log/exception.log`. Failed registration was an error and is now a warning.
- Rejected sign-ins (passkeys turned off, bad or expired challenge, malformed response, failed sign-in limit) are logged once by the sign-in service, so REST sign-ins are now logged too. The storefront and GraphQL no longer log them a second time.
- Rate-limit cache keys use SHA-256 instead of MD5.
- The unreachable "log a warning and allow" sign-count code was removed. webauthn-lib already rejects a counter that doesn't increase, so behavior is unchanged. (#11)
- Unit tests run on PHPUnit 10 and 12. (#13)
- CI uses a read-only token and pins its actions to commits, including `actions/checkout` v4.4.0.

### Fixed

- New passkeys reused the stored, base64-encoded user handle as the raw handle, so the handle grew with each passkey. The oldest valid handle is now reused. Passkeys from earlier releases may keep their own handle and still work.
- Malformed passkey responses caused HTTP 500 errors. They are now rejected as invalid.
- Looking up a passkey by its credential ID scanned the table and ignored letter case. It now uses the indexed hash of the ID and an exact match.
- Notification emails for customers created in the admin used the default website's store view. They now use the customer's own website's default store view, or the default store view if that website no longer exists.
- A PHP error (not an exception) while sending a notification email could break the action that triggered it. It is now logged like other email failures.
- Hyvä: the passkey components could miss Alpine's start and not load. They now register before Alpine starts.
- Hyvä My Account > Passkeys: server errors are shown, the Add button is disabled while busy, the empty state returns after the last passkey is deleted, and Delete removes the whole row.
- Admin two-factor failure messages were not picked up for translation.
- Hyvä messages could not be translated. The new `i18n.phtml` template renders their translations with `__()`, and the Hyvä scripts read them through `passkeyCore.t()`. The suggested passkey name ("Chrome on Windows") is translated on Luma and Hyvä too.
- Customers never saw "Too many failed passkey attempts. Please try again later." The storefront and GraphQL showed the generic failure instead. They now show it, and the storefront replies with HTTP 429. Luma and Hyvä show it for autofill sign-ins too. Other failures still show the generic message, except a refused account (see Security).

### Security

- Challenges are claimed atomically. Two requests with the same token can't both use it.
- With per-website customer accounts, a passkey only signs in on its own customer's website, even when websites share a domain.
- Passkey registration is refused, and the enrollment banner hidden, while an admin is signed in as the customer with Login as Customer. This covers the storefront session only. Known limitation: tokens from `generateCustomerTokenAsAdmin` can't be told apart from the customer's own, so REST and GraphQL registration with them is not blocked.
- The sign-in options limit counts the email trimmed and lowercased, so changing the case of the email no longer gets a fresh counter.
- The failed sign-in limit covers REST. (#11)
- The passkey name and customer email are escaped in the admin revoke confirmation, and the passkey name in the Luma delete confirmation.
- Passkey sign-in ignored Magento's account lockout and email confirmation. It now refuses a locked account, an account awaiting email confirmation (including a changed email), and a customer group excluded from the website, on the storefront, REST and GraphQL. The checks run after the passkey is verified and don't count toward the failed sign-in limit. Every entry point shows the message a password sign-in shows: the confirmation message, or Magento's generic "disabled temporarily" message for a locked account or excluded group. The storefront replies with HTTP 403, and Luma and Hyvä show the message for autofill sign-ins too. Failed passkey attempts never count toward the lockout, since anyone could otherwise lock a customer out. A successful passkey sign-in resets the wrong-password count, as a password sign-in does.
- Removing a passkey left sessions and tokens from it signed in. An admin revoke now ends all of the customer's storefront sessions and revokes their REST and GraphQL tokens. A customer deleting their own passkey (storefront, REST or GraphQL) ends their other storefront sessions and keeps API tokens, as a password change does. If signing out fails, the passkey is still removed, the error is logged, and the admin sees a warning.

## [1.0.0-beta3] - 2026-09-26

### Fixed

- Passkey sign-in failed with HTTP 500 on webauthn-lib 5.3, on the storefront, REST, GraphQL and admin two-factor. (#9)
- The header and mini cart stayed in the guest state after a passkey sign-in. (#9)
- **Prompt After Account Creation** was never read. (#9)
- Autofill: the challenge is refreshed every 4 minutes, no error shows when the browser ends the request itself, and fields use `autocomplete="username webauthn"`. (#9)
- My Account: Cancel in the name dialog no longer registers a passkey, names are checked before the browser prompt, and rename has Save and Cancel. `/passkey/account/` returns 404 when passkeys are off. (#9)
- Admin two-factor screens show readable errors. The grid's "Uses" column is now "Signature Counter" and hidden by default. (#9)
- Removed unused Hyvä login request helpers. (#8)

### Security

- Sign-in options for an email with no passkeys, or no account, carry a stable decoy credential instead of an empty list. The empty list showed which accounts had passkeys, and let an unrelated passkey on the device sign in. (#9)

## [1.0.0-beta2] - 2026-09-26

### Added

- Hyvä theme support with Alpine.js components. (#2)
- Passkey provider for admin two-factor authentication, with the `security:tfa:passkey:reset-all` command. (#3)
- Passkey autofill on the login page and the Luma checkout. (#6)
- Emails to the customer when a passkey is added or removed. (#6)
- **Customers > Customer Passkeys** admin grid with revoke and mass revoke. (#6)
- GraphQL API for sign-in, registration and passkey management. (#6)
- Integration tests, `SECURITY.md` and translations. (#6)

### Fixed

- PHP 8.5 null-safety issues. (#4)
- Hyvä passkey requests were rejected by CSRF validation. (#7)

## [1.0.0-beta1] - 2026-03-16

First release.

- Customer passkey registration and sign-in on the Luma storefront, built on webauthn-lib 5.
- **My Account > Passkeys** page to add, rename and delete passkeys.
- REST API for registration, sign-in and passkey management.
- Optional enrollment banner after password sign-in or account creation.
- Single-use challenges with a 5-minute lifetime and cron cleanup.
- Rate limits on options requests and failed sign-ins.
- Events for registration, removal, and sign-in success and failure.
- Settings under **Customers > Customer Configuration > Passkey Authentication**.

[1.0.0]: https://github.com/mage-os-lab/module-passkey-auth/compare/1.0.0-beta3...1.0.0
[1.0.0-beta3]: https://github.com/mage-os-lab/module-passkey-auth/compare/1.0.0-beta2...1.0.0-beta3
[1.0.0-beta2]: https://github.com/mage-os-lab/module-passkey-auth/compare/1.0.0-beta1...1.0.0-beta2
[1.0.0-beta1]: https://github.com/mage-os-lab/module-passkey-auth/releases/tag/1.0.0-beta1
