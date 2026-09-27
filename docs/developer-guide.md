# Developer guide

How to extend or customize the module. For the API used by headless frontends, see [Headless API](headless-api.md).

## Service contracts

All customer passkey logic goes through interfaces in `Api/`. Replace any of them with a DI preference.

| Interface | Default implementation | Purpose |
|---|---|---|
| `RegistrationOptionsInterface` | `Model\Registration\OptionsGenerator` | Build creation options for a customer |
| `RegistrationVerifierInterface` | `Model\Registration\Verifier` | Check the browser response and save the passkey |
| `AuthenticationOptionsInterface` | `Model\Authentication\OptionsGenerator` | Build sign-in options, optionally for one email |
| `AuthenticationVerifierInterface` | `Model\Authentication\Verifier` | Check the browser response and issue a customer token |
| `CredentialRepositoryInterface` | `Model\CredentialRepository` | Load, save, and delete passkeys |
| `CredentialManagementInterface` | `Model\CredentialManagement` | List, rename, and delete with an ownership check. `revokeCredential()` skips the check, for admin use. |
| `WebAuthnConfigInterface` | `Model\Config` | Relying party and WebAuthn settings |
| `Data\CredentialInterface` | `Model\Data\Credential` | Passkey data object |
| `Data\AuthenticationResultInterface` | `Model\Data\AuthenticationResult` | Customer ID and token |

`Model\WebAuthn\Ceremony` wraps webauthn-lib for option building, challenge handling, and response validation. Customer and admin flows share it with different `WebAuthnConfigInterface` instances.

```xml
<preference for="MageOS\PasskeyAuth\Api\AuthenticationVerifierInterface"
            type="Vendor\Module\Model\CustomVerifier"/>
```

## Events

| Event | Data | When |
|---|---|---|
| `passkey_credential_register_after` | `customer_id` (int), `credential` (`CredentialInterface`) | A passkey was saved |
| `passkey_registration_failure` | `customer_id` (int), `reason` (string) | The browser response for a new passkey failed validation |
| `passkey_authentication_success` | `customer_id` (int), `credential` (`CredentialInterface`) | A customer signed in with a passkey |
| `passkey_authentication_failure` | `credential_id` (string, base64), `reason` (string) | A sign-in response failed validation, or named an unknown passkey |
| `passkey_credential_remove_after` | `customer_id` (int), `credential_id` (int, the entity ID), `credential` (`CredentialInterface`) | A passkey was deleted by the customer, through the API, or revoked by an admin |

These fire from the service layer, so they cover the storefront, REST, GraphQL, and the admin grid.

`passkey_credential_remove_after` does not fire when a customer account is deleted. The database removes those passkeys through a foreign key.

The notification emails are observers on the register and remove events (`mageos_passkey_notify_added`, `mageos_passkey_notify_removed`). To stop them in code rather than in config, disable those observers in your module's `events.xml`.

Example observer:

```xml
<!-- etc/events.xml -->
<event name="passkey_authentication_success">
    <observer name="vendor_module_passkey_login" instance="Vendor\Module\Observer\PasskeyLogin"/>
</event>
```

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

All blocks use `ifconfig="customer/passkey/enabled"`. Remove or move them with `referenceBlock` as usual.

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

The block renders nothing for signed-in customers. Only one WebAuthn request can be active in a page, so call `passkeyCore.abortConditional()` before starting your own ceremony.

### Storefront endpoints

The storefront uses these session-based JSON endpoints. They are not a public API. Use [REST or GraphQL](headless-api.md) for integrations.

| Path | Signed in | Body |
|---|---|---|
| `POST /passkey/authentication/options` | No | `{"email": "…"}` (optional) |
| `POST /passkey/authentication/verify` | No | `{"challengeToken": "…", "credential": {…}}`. Signs the customer in to the session. |
| `POST /passkey/registration/options` | Yes | `{}` |
| `POST /passkey/registration/verify` | Yes | `{"challengeToken": "…", "credential": {…}, "friendlyName": "…"}` |
| `POST /passkey/account/rename` | Yes | `{"entity_id": 1, "friendly_name": "…"}` |
| `POST /passkey/account/delete` | Yes | Form field `entity_id` |
| `GET /passkey/account` | Yes | The My Account page |

Send `X-Requested-With: XMLHttpRequest` with every POST. Magento's CSRF check requires it. Errors come back as `{"errors": true, "message": "…"}` with HTTP 400 (401 when not signed in).

### Customer data

The `passkey` customer-data section has one key, `show_enrollment_prompt`. It is refreshed after password sign-in, account creation, passkey registration, and passkey deletion.

## Emails

Templates are `view/frontend/email/passkey_added.html` and `passkey_removed.html`. Template IDs are `customer_passkey_added_email_template` and `customer_passkey_removed_email_template`. Override them in a theme or through **Marketing > Email Templates**.

Variables: `customer_name`, `passkey_name` ("Unnamed passkey" when empty), `store_name`.

## Translations

All strings are in `i18n/en_US.csv`. Hyvä JavaScript messages are plain English strings in `js/hyva/*.js` and are not run through Magento's translation system.

## Database

| Table | Contents |
|---|---|
| `passkey_credential` | One row per passkey: customer ID, credential ID, a SHA-256 hash of the credential ID (unique), the serialized public key record, user handle, signature counter, transports, name, AAGUID, and timestamps. Deleted with the customer. |
| `passkey_challenge` | Pending challenges: token, serialized options, type, and customer ID. Rows are deleted when used, and the `passkey_challenge_cleanup` cron job removes expired ones every 5 minutes. |

A customer's passkeys share one random user handle, created with their first passkey.

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

**GraphQL API tests** (`Test/Api/GraphQl`) run from `dev/tests/api-functional` against a configured GraphQL endpoint.
