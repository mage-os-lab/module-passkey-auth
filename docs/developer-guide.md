# Developer guide

How to extend or customize the module. For the API used by headless frontends, see [Headless API](headless-api.md).

## Service contracts

All customer passkey logic goes through interfaces in `Api/`. They are all marked `@api`. Replace any of them with a DI preference.

| Interface | Default implementation | Purpose |
|---|---|---|
| `RegistrationOptionsInterface` | `Model\Registration\OptionsGenerator` | Build creation options for a customer |
| `RegistrationVerifierInterface` | `Model\Registration\Verifier` | Check the browser response and save the passkey |
| `AuthenticationOptionsInterface` | `Model\Authentication\OptionsGenerator` | Build sign-in options, optionally for one email |
| `AuthenticationVerifierInterface` | `Model\Authentication\Verifier` | Check the browser response and issue a customer token |
| `CredentialRepositoryInterface` | `Model\CredentialRepository` | Load, save, and delete passkeys. `getList()` takes search criteria. It is not exposed over REST. |
| `CredentialManagementInterface` | `Model\CredentialManagement` | List, rename, and delete with an ownership check. `deleteCredential()` also ends the customer's other storefront sessions. `revokeCredential()` skips the check, for admin use, and doesn't sign the customer out. See [Signing out after a passkey is removed](#signing-out-after-a-passkey-is-removed). |
| `CustomerPasskeyManagementInterface` | `Model\CustomerPasskeyManagement` | List, rename, and register passkeys for the web API. Returns `CustomerPasskeyInterface`, without key material. |
| `WebAuthnConfigInterface` | `Model\Config` | Relying party and WebAuthn settings |
| `Data\CredentialInterface` | `Model\Data\Credential` | Full stored passkey, including the public key record |
| `Data\CustomerPasskeyInterface` | `Model\Data\CustomerPasskey` | Passkey as REST and GraphQL return it: `id`, `name`, `transports`, `created_at`, `last_used_at` |
| `Data\CredentialSearchResultsInterface` | `Model\CredentialSearchResults` | Result of `CredentialRepositoryInterface::getList()` |
| `Data\AuthenticationResultInterface` | `Model\Data\AuthenticationResult` | Customer ID and token |

`Data\CredentialInterface` and `Data\CustomerPasskeyInterface` support extension attributes. Declare yours in your module's `etc/extension_attributes.xml`. Extension attributes on `CustomerPasskeyInterface` appear in REST responses.

PHP code that needs the full record should use `CredentialManagementInterface` or `CredentialRepositoryInterface`. The web API uses `CustomerPasskeyManagementInterface`, so it never returns key material.

`Model\WebAuthn\Ceremony` wraps webauthn-lib for option building, challenge handling, and response validation. Customer and admin flows share it with different `WebAuthnConfigInterface` instances.

```xml
<preference for="MageOS\PasskeyAuth\Api\AuthenticationVerifierInterface"
            type="Vendor\Module\Model\CustomVerifier"/>
```

## Events

| Event | Data | When |
|---|---|---|
| `passkey_credential_register_after` | `customer_id`, `entity_id`, `credential_id`, `credential` | A passkey was saved |
| `passkey_registration_failure` | `customer_id`, `reason` (`verification_failed`), `message` | The browser response for a new passkey failed validation |
| `passkey_authentication_success` | `customer_id`, `entity_id`, `credential_id`, `credential` | A customer signed in with a passkey |
| `passkey_authentication_failure` | `credential_id`, `customer_id` (null when no stored passkey matches), `reason` (`credential_not_found` or `verification_failed`), `message` | A sign-in response named an unknown passkey, or failed validation |
| `passkey_credential_remove_after` | `customer_id`, `entity_id`, `credential_id`, `credential` | A passkey was deleted by the customer, through the API, or revoked by an admin |

Each key means the same thing in every event:

| Key | Type | Meaning |
|---|---|---|
| `customer_id` | int | The customer |
| `entity_id` | int | The `passkey_credential` row ID. This is the `id` in REST and GraphQL. |
| `credential_id` | string | The WebAuthn credential ID, base64 as stored |
| `credential` | `CredentialInterface` | The stored passkey |
| `reason` | string | A fixed code. Match on this. |
| `message` | string | Text from the module or webauthn-lib, for logs. Don't match on it. |

`credential_not_found` also covers a passkey whose customer belongs to another website, when **Share Customer Accounts** is **Per Website**. `customer_id` is set in that case.

Event names and reason codes are constants on `MageOS\PasskeyAuth\Model\PasskeyEvents` (`@api`).

These fire from the service layer, so they cover the storefront, REST, GraphQL, and the admin grid.

After a passkey sign-in, Magento's own `customer_login` event also fires, as for a password sign-in. The storefront fires it through the customer session. For REST, SOAP, and GraphQL, the module's `mageos_passkey_dispatch_customer_login` observer on `passkey_authentication_success` fires it, as core does for password token requests. This updates the customer's last login time.

Passkey sign-in does not fire core `customer_customer_authenticated`. Its observers expect a password: core's `UpgradeCustomerPasswordObserver` would rehash the event's password, and a passkey sign-in has none. `Model\Authentication\AccountGuard` makes the checks that matter itself: account lock, email confirmation, and customer groups excluded from the website. After a successful sign-in it resets the wrong-password count, as core's `CustomerLoginSuccessObserver` does. The captcha and persistent cart observers on that event are not run.

`passkey_credential_remove_after` does not fire when a customer account is deleted. The database removes those passkeys through a foreign key.

The notification emails are observers on the register and remove events (`mageos_passkey_notify_added`, `mageos_passkey_notify_removed`). To stop them in code rather than in config, disable those observers in your module's `events.xml`.

Example observer:

```xml
<!-- etc/events.xml -->
<event name="passkey_authentication_success">
    <observer name="vendor_module_passkey_login" instance="Vendor\Module\Observer\PasskeyLogin"/>
</event>
```

## Signing out after a passkey is removed

`Model\CustomerSignOut` works per customer, because sessions and tokens aren't tied to a passkey:

- `signOutEverywhere($customerId)` ends all storefront sessions and revokes all REST and GraphQL tokens. The admin grid calls it after revoking, once per customer.
- `endOtherSessions($customerId)` ends every storefront session except the current one, as Magento does after a password change. `deleteCredential()` calls it.

Both log a failure and return `false`. They never throw. If you call `revokeCredential()` from your own code for a lost device, call `signOutEverywhere()` afterwards.

## Changing WebAuthn settings

Customer settings come from `MageOS\PasskeyAuth\Model\Config`. Use an `after` plugin to change a value:

```php
namespace Vendor\Module\Plugin;

use MageOS\PasskeyAuth\Model\Config;

class RequireUserVerification
{
    public function afterGetUserVerification(Config $subject, string $result): string
    {
        return 'required';
    }
}
```

```xml
<!-- etc/di.xml -->
<type name="MageOS\PasskeyAuth\Model\Config">
    <plugin name="vendor_module_require_uv" type="Vendor\Module\Plugin\RequireUserVerification"/>
</type>
```

Methods you can change this way:

| Method | Default | Allowed values |
|---|---|---|
| `getUserVerification()` | `preferred` | `required`, `preferred`, `discouraged` |
| `getAuthenticatorAttachment()` | `null` (any) | `platform`, `cross-platform`, `null` |
| `getAttestationConveyance()` | `none` | `none`, `indirect`, `direct`, `enterprise` |
| `getResidentKeyRequirement()` | `preferred` | `required`, `preferred`, `discouraged` |
| `getCeremonyTimeout()` | `60000` | Milliseconds |
| `getMaxCredentials()` | `10` | Any positive integer |

Admin two-factor settings come from `Model\AdminTfa\AdminTfaConfig` and can be changed the same way.

The module accepts every attestation format webauthn-lib supports, but it does not check attestation against FIDO metadata. Attestation does not prove the make or model of an authenticator here.

Rate limits (`Model\RateLimiter`) and the 5-minute challenge lifetime (`Model\ChallengeManager`) are constants. To change them, replace the class with a DI preference.

## Storefront

### Layout

| Block name | Layout handle | Container | Purpose |
|---|---|---|---|
| `customer.login.passkey` | `customer_account_login` | `form.additional.info` | Sign-in button and autofill |
| `passkey.enrollment.prompt` | `customer_account` | `content` | Enrollment banner |
| `customer.account.passkeys` | `passkey_account_index` | `content` | My Account > Passkeys page |
| `customer-account-navigation-passkeys-link` | `customer_account` | `customer_account_navigation` | Account menu link |
| `passkey.checkout.conditional` | `checkout_index_index` | `before.body.end` | Autofill on checkout email fields |
| `passkey.hyva.scripts` | `hyva_default` | `before.body.end` | Loads the Hyvä scripts |

All blocks except `customer.account.passkeys` use `ifconfig="customer/passkey/enabled"`. That page's controller returns 404 instead when passkeys are off. Remove or move blocks with `referenceBlock` as usual.

`Block\Login\PasskeyButton::getVerifyUrl()` adds the login page's `referer` parameter to the verify URL, as core's login form does. The verify reply's `redirect_url` then follows it. `Block\Login\ConditionalLogin` leaves it out on purpose. The autofill it adds reloads the current page after sign-in and ignores `redirect_url`, so a customer at checkout stays at checkout.

### Templates

Override through your theme, for example `app/design/frontend/Vendor/theme/MageOS_PasskeyAuth/templates/login/passkey-buttons.phtml`.

| Luma | Hyvä |
|---|---|
| `login/passkey-buttons.phtml` | `hyva/login/passkey-buttons.phtml` |
| `enrollment-prompt.phtml` | `hyva/enrollment-prompt.phtml` |
| `account/passkeys.phtml` | `hyva/account/passkeys.phtml` |
| `login/passkey-conditional.phtml` | (none) |

Luma styles are in `view/frontend/web/css/source/_module.less` and use Luma's standard classes (`.message`, `.data.table`, `.action.primary`).

### JavaScript

`MageOS_PasskeyAuth/js/passkey-core` (alias `passkeyCore`) holds the shared code: encoding, option conversion, autofill, the enrollment snooze, and name suggestions. It has no dependencies and loads as a RequireJS module or as a plain script (`window.passkeyCore`).

- `passkeyCore.completeSignIn(result)` takes the verify reply and goes to its `redirect_url`: the page a password sign-in would land on. It reloads the page instead when the URL is missing, not http(s), or the current page.
- `passkeyCore.startConditional(config)` starts autofill. `config.onSuccess(result)` gets the verify reply. Without `onSuccess`, it calls `completeSignIn(result)`.
- `passkeyCore.hasCustomerMessage(error)` tells whether a failed request's server message should be shown as is: HTTP 429 (failed sign-in limit) or 403 (the account can't sign in). Show a generic message for other failures.
- `passkeyCore.t(text, ...args)` translates a message on Hyvä. See [Translations](#translations).

Luma jQuery UI widgets, which you can extend with RequireJS mixins:

| Widget | File | Used on |
|---|---|---|
| `mageOS.passkeyLogin` | `js/passkey-login.js` | Login page |
| `mageOS.passkeyManage` | `js/passkey-manage.js` | My Account > Passkeys |
| `mageOS.enrollmentPrompt` | `js/enrollment-prompt.js` | Account pages |

Hyvä Alpine components: `passkeyLogin`, `passkeyEnrollment`, `passkeyManage`, and `passkeyRow`, in `js/hyva/`.

### Adding autofill to another form

Autofill needs a visible email or username field. Add this block to the page's layout:

```xml
<referenceContainer name="before.body.end">
    <block class="MageOS\PasskeyAuth\Block\Login\ConditionalLogin"
           name="vendor.passkey.conditional"
           template="MageOS_PasskeyAuth::login/passkey-conditional.phtml"
           ifconfig="customer/passkey/enabled"/>
</referenceContainer>
```

The default template targets the Luma checkout fields. For other fields, copy the template and change `selectors`, or call the script yourself:

```js
require(['MageOS_PasskeyAuth/js/passkey-conditional'], function (conditional) {
    conditional({
        optionsUrl: '/passkey/authentication/options',
        verifyUrl: '/passkey/authentication/verify',
        selectors: '#my-email-field'
    });
});
```

The block renders nothing for signed-in customers. After sign-in, `passkey-conditional` refreshes customer data and reloads the page. It ignores the verify reply's `redirect_url`. To go somewhere else, call `passkeyCore.startConditional()` with your own `onSuccess(result)`.

Only one WebAuthn request can be active in a page, so call `passkeyCore.abortConditional()` before starting your own ceremony.

### Storefront endpoints

The storefront uses these session-based JSON endpoints. They are not a public API. Use [REST or GraphQL](headless-api.md) for integrations.

| Path | Signed in | Body |
|---|---|---|
| `POST /passkey/authentication/options` | No | `{"email": "…"}` (optional) |
| `POST /passkey/authentication/verify` | No | `{"challengeToken": "…", "credential": {…}}`. Signs the customer in to the session. Success returns `{"errors": false, "message": "…", "redirect_url": "…"}`. |
| `POST /passkey/registration/options` | Yes | `{}` |
| `POST /passkey/registration/verify` | Yes | `{"challengeToken": "…", "credential": {…}, "friendlyName": "…"}` |
| `POST /passkey/account/rename` | Yes | `{"entity_id": 1, "friendly_name": "…"}` |
| `POST /passkey/account/delete` | Yes | Form field `entity_id` |
| `GET /passkey/account` | Yes | The My Account page |

Send `X-Requested-With: XMLHttpRequest` with every POST. Magento's CSRF check requires it. Errors come back as `{"errors": true, "message": "…"}` with HTTP 400 (401 when not signed in). The verify endpoint also replies 429 when the failed sign-in limit is hit, and 403 when the passkey is valid but the account can't sign in (locked, not confirmed, or group excluded from the website).

### Customer data

The `passkey` customer-data section has one key, `show_enrollment_prompt`. It is refreshed after password sign-in, account creation, passkey registration, and passkey deletion.

## Emails

Templates are `view/frontend/email/passkey_added.html` and `passkey_removed.html`. Template IDs are `customer_passkey_added_email_template` and `customer_passkey_removed_email_template`. Override them in a theme or through **Marketing > Email Templates**.

Variables: `customer_name`, `passkey_name` ("Unnamed passkey" when empty), `store_name`.

## Translations

All strings are in `i18n/en_US.csv`. Translate them with a language pack or your theme's `i18n` CSV, as for any module.

Luma scripts use `mage/translate`. Hyvä doesn't load it, so `hyva/scripts.phtml` renders the Hyvä messages with `__()` into a JSON block, `<script type="application/json" id="mageos-passkey-i18n">`, and the scripts read it through `passkeyCore.t()`. A browser never runs a JSON block, so it needs no CSP nonce. Without the block, `passkeyCore.t()` returns the English text.

To add a message to your own Hyvä script, add it to the `$phrases` list in a copy of `hyva/scripts.phtml` and call `passkeyCore.t('Your message')`. `%1`, `%2` and so on are replaced by the extra arguments.

## Database

| Table | Contents |
|---|---|
| `passkey_credential` | One row per passkey: customer ID, credential ID, a SHA-256 hash of the credential ID (unique), the serialized public key record, user handle, signature counter, transports, name, AAGUID, and timestamps. Deleted with the customer. |
| `passkey_challenge` | Pending challenges: token, serialized options, type, and customer ID. Rows are deleted when used, and the `passkey_challenge_cleanup` cron job removes expired ones every 5 minutes. |

A customer's passkeys share one random user handle, created with their first passkey. New passkeys reuse the handle of the customer's oldest passkey that has a valid one. Passkeys registered before 1.0 may keep a different handle of their own. They still work.

Admin two-factor passkeys are stored in Magento_TwoFactorAuth's `tfa_user_config` table, not in `passkey_credential`.

## Tests

**Unit tests** run on their own, without a Magento install:

```bash
composer install
composer test
```

GitHub Actions runs them on pushes to `main` and `feature/*`, and on pull requests to `main`.

**Integration tests** (`Test/Integration`) follow Magento's conventions. Install the module in a Magento instance, then from `dev/tests/integration`:

```bash
../../../vendor/bin/phpunit ../../../vendor/mage-os/module-passkey-auth/Test/Integration
```

**GraphQL API tests** (`Test/Api/GraphQl`) use Magento's api-functional framework. Set up `dev/tests/api-functional` for your instance first (copy `phpunit_graphql.xml.dist` to `phpunit_graphql.xml` and set `TESTS_BASE_URL` and the other values). The default suite doesn't include module tests, so run them by path:

```bash
cd dev/tests/api-functional
../../../vendor/bin/phpunit -c phpunit_graphql.xml ../../../vendor/mage-os/module-passkey-auth/Test/Api/GraphQl
```
