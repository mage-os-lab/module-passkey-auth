# Changelog

All notable changes to this module are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [1.0.0] - Unreleased

### Breaking changes

- **Customer passkeys are off by default on new installs.** `customer/passkey/enabled` now defaults to No. On upgrade, a store that already has customer passkeys and never saved the setting gets it saved as Yes, so passkey sign-in keeps working. A value you saved yourself, at any scope, is kept. (#10)
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
- **Constructor changes in non-API classes.** If you extend these classes or configure their arguments in `di.xml`, update your code.
  - `Controller\Authentication\Verify`: drops `RateLimiter`, adds `PostLoginRedirect`.
  - `Controller\Registration\Options`: drops `RequestInterface`.
  - `Model\Authentication\Verifier`: adds `RateLimiter`, `RemoteAddress`, `Share`, `CustomerRepositoryInterface` and `StoreManagerInterface`.
  - `Model\CredentialRepository`: adds `CollectionProcessorInterface` and `CredentialSearchResultsInterfaceFactory`.
  - `Model\Registration\OptionsGenerator` and `Model\Registration\Verifier`: add `AdminImpersonationGuard`.
  - `Model\Resolver\CredentialFormatter`: new constructor with `CustomerPasskeyMapper`.
  - `Model\Resolver\VerifyAuthentication`: drops `RateLimiter` and `RemoteAddress`.
  - `Ui\Component\Listing\Column\CredentialActions`: adds `Escaper` before `$components`.
  - `Model\UserHandleGenerator::getOrGenerate()` now returns raw bytes, not base64.
- **Requires `web-auth/webauthn-lib` ^5.2** (was ^5.0). `composer.json` also lists the Magento modules the code uses directly. In practice this needs Magento Open Source or Mage-OS 2.4.6 or later.
- **The failed sign-in limit now covers REST.** `POST /V1/passkey/authentication/verify` had no limit. It now shares the per-IP counter with the storefront and GraphQL. A headless frontend that calls REST from one server sends every customer's sign-in from that server's IP, so they all share one counter. (#11)

### Added

- `Api\CustomerPasskeyManagementInterface` (`getPasskeys`, `renamePasskey`, `verifyRegistration`) and `Api\Data\CustomerPasskeyInterface`. The REST list, rename and registration routes use them.
- `Api\Data\CredentialSearchResultsInterface` and `CredentialRepositoryInterface::getList()`. `getList()` is not exposed over REST.
- Extension attributes on `CredentialInterface` and `CustomerPasskeyInterface`.
- `Model\PasskeyEvents` (`@api`) with the event names and reason codes.
- `WebAuthnConfigInterface` is now `@api`.
- REST, SOAP and GraphQL passkey sign-ins fire core `customer_login`, as password token requests do. The customer's last login time is updated.
- The storefront verify endpoint returns `redirect_url`: the page a password sign-in would land on. The login page follows it instead of reloading. `passkeyCore.completeSignIn()` does this, and `startConditional`'s `onSuccess` now receives the verify reply. Checkout autofill still reloads the page.
- A user manual in `docs/`. (#11)
- README screenshots. (#10)
- License headers on source files, and `LICENSE.txt` (OSL-3.0). (#12)
- CI runs on PHP 8.5.

### Changed

- Routine rejections are logged as warnings with a `reason` in `var/log/system.log`. Unexpected errors still go to `var/log/exception.log`. Failed registration was an error and is now a warning.
- Rate-limit cache keys use SHA-256 instead of MD5.
- The unreachable "log a warning and allow" sign-count code was removed. webauthn-lib already rejects a counter that doesn't increase, so behavior is unchanged. (#11)
- Unit tests run on PHPUnit 10 and 12. (#13)
- CI uses a read-only token, a pinned action, and `actions/checkout@v4`.

### Fixed

- New passkeys reused the stored, base64-encoded user handle as the raw handle, so the handle grew with each passkey. The oldest valid handle is now reused. Passkeys from earlier releases may keep their own handle and still work.
- Malformed passkey responses caused HTTP 500 errors. They are now rejected as invalid.
- Looking up a passkey by its credential ID scanned the table and ignored letter case. It now uses the indexed hash of the ID and an exact match.
- Notification emails for customers created in the admin used the default website's store view. They now use the customer's own website's default store view.
- Hyvä: the passkey components could miss Alpine's start and not load. They now register before Alpine starts.
- Hyvä My Account > Passkeys: server errors are shown, the Add button is disabled while busy, the empty state returns after the last passkey is deleted, and Delete removes the whole row.
- Admin two-factor failure messages were not picked up for translation.

### Security

- Challenges are claimed atomically. Two requests with the same token can't both use it.
- With per-website customer accounts, a passkey only signs in on its own customer's website, even when websites share a domain.
- Passkey registration is refused while an admin is signed in as the customer with Login as Customer. This covers the storefront session only. Tokens from `generateCustomerTokenAsAdmin` can't be told apart from the customer's own.
- The sign-in options limit counts the email trimmed and lowercased, so changing the case of the email no longer gets a fresh counter.
- The failed sign-in limit covers REST. (#11)
- The passkey name and customer email are escaped in the admin revoke confirmation, and the passkey name in the Luma delete confirmation.

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

[1.0.0]: https://github.com/mage-os-lab/module-passkey-auth/compare/1.0.0-beta3...HEAD
[1.0.0-beta3]: https://github.com/mage-os-lab/module-passkey-auth/compare/1.0.0-beta2...1.0.0-beta3
[1.0.0-beta2]: https://github.com/mage-os-lab/module-passkey-auth/compare/1.0.0-beta1...1.0.0-beta2
[1.0.0-beta1]: https://github.com/mage-os-lab/module-passkey-auth/releases/tag/1.0.0-beta1
