# MageOS Passkey Authentication

[![Latest Stable Version](https://poser.pugx.org/mage-os/module-passkey-auth/v/stable)](https://packagist.org/packages/mage-os/module-passkey-auth)
[![License](https://poser.pugx.org/mage-os/module-passkey-auth/license)](https://packagist.org/packages/mage-os/module-passkey-auth)
[![Total Downloads](https://poser.pugx.org/mage-os/module-passkey-auth/downloads)](https://packagist.org/packages/mage-os/module-passkey-auth)

Passwordless login for Magento 2 customer accounts using the WebAuthn/FIDO2 standard. Customers register passkeys (biometric, security key, or device PIN) and sign in with a single tap — no passwords to remember, phish, or leak.

Built on [`web-auth/webauthn-lib`](https://github.com/web-auth/webauthn-lib) v5.

## Key Features

### Passwordless Authentication
- **One-tap login**: Customers authenticate with fingerprint, Face ID, Windows Hello, or a hardware security key
- **Token-based sessions**: Successful passkey authentication issues a standard Magento customer token
- **Anti-enumeration**: Authentication options return a valid response even for non-existent emails, preventing account discovery

### Credential Management
- **My Account page**: Customers add, rename, and delete passkeys from their account dashboard
- **Clone detection**: Sign-count tracking detects copied authenticators

### Store Admin Controls
- **Enrollment prompts**: Optional banners on account pages after password login or account creation to encourage passkey adoption
- **Rate limiting**: Built-in cache-based limits on options requests and verification failures

## Requirements

| Component | Version |
|-----------|---------|
| **PHP** | 8.2+ |
| **Magento Open Source / Mage-OS** | 2.4.x |
| **HTTPS** | Required (WebAuthn does not work over plain HTTP) |

## Installation

```bash
composer require mage-os/module-passkey-auth
bin/magento setup:upgrade
```

## Configuration

Navigate to **Stores > Configuration > Customers > Customer Configuration > Passkey Authentication**.

| Setting | Description | Default |
|---------|-------------|---------|
| **Enable Passkey Authentication** | Master on/off switch | Yes |
| **Prompt After Password Login** | Show enrollment banner on account pages after password sign-in | Yes |
| **Prompt After Account Creation** | Show enrollment banner on account pages after registration | No |

The Relying Party (RP) ID and allowed origins are derived automatically from the store's base URL — no manual configuration required.

WebAuthn parameters (user verification, attestation conveyance, ceremony timeout, authenticator attachment, and max credentials per customer) use sane defaults internally and are not exposed as admin settings.

## Architecture

### Service Contracts

All business logic is exposed through `Api` interfaces:

| Interface | Implementation | Purpose |
|-----------|---------------|---------|
| `RegistrationOptionsInterface` | `Registration\OptionsGenerator` | Generate WebAuthn creation options |
| `RegistrationVerifierInterface` | `Registration\Verifier` | Verify attestation and store credential |
| `AuthenticationOptionsInterface` | `Authentication\OptionsGenerator` | Generate WebAuthn request options |
| `AuthenticationVerifierInterface` | `Authentication\Verifier` | Verify assertion and issue token |
| `CredentialRepositoryInterface` | `CredentialRepository` | Credential CRUD |
| `CredentialManagementInterface` | `CredentialManagement` | List, rename, delete credentials |
| `Data\CredentialInterface` | `Data\Credential` | Credential data transfer object |
| `Data\AuthenticationResultInterface` | `Data\AuthenticationResult` | Authentication result DTO |

### REST API

| Method | Endpoint | Auth | Purpose |
|--------|----------|------|---------|
| `POST` | `/V1/passkey/registration/options` | Customer (self) | Get creation options for navigator.credentials.create() |
| `POST` | `/V1/passkey/registration/verify` | Customer (self) | Submit attestation response, receive stored credential |
| `POST` | `/V1/passkey/authentication/options` | Anonymous | Get request options for navigator.credentials.get() |
| `POST` | `/V1/passkey/authentication/verify` | Anonymous | Submit assertion response, receive customer token |
| `GET` | `/V1/passkey/credentials` | Customer (self) | List customer's registered passkeys |
| `PUT` | `/V1/passkey/credentials/:entityId` | Customer (self) | Rename a passkey |
| `DELETE` | `/V1/passkey/credentials/:entityId` | Customer (self) | Delete a passkey |

### Events

| Event | Payload | Fired When |
|-------|---------|------------|
| `passkey_credential_register_after` | `customer_id`, `credential` | New passkey registered |
| `passkey_authentication_success` | `customer_id`, `credential` | Successful passkey login |
| `passkey_authentication_failure` | `credential_id`, `reason` | Failed passkey login |
| `passkey_credential_remove_after` | `customer_id`, `credential_id` | Passkey deleted |

### Database

**`passkey_credential`** — Stores registered WebAuthn credentials. One customer can have multiple credentials (up to 10). Foreign key to `customer_entity` with `CASCADE` delete.

**`passkey_challenge`** — Temporary single-use challenges with a 5-minute TTL. Cleaned up by the `passkey_challenge_cleanup` cron job.

## Extensibility

### Observing Passkey Events

Create an observer in your module's `etc/events.xml`:

```xml
<event name="passkey_authentication_success">
    <observer name="my_module_passkey_login" instance="Vendor\Module\Observer\PasskeyLogin"/>
</event>
```

### Overriding Services

All service contracts can be replaced via DI preferences in `etc/di.xml`:

```xml
<preference for="MageOS\PasskeyAuth\Api\AuthenticationVerifierInterface"
            type="Vendor\Module\Model\CustomVerifier"/>
```

### Frontend Customization

The module provides three jQuery UI widgets that can be extended via RequireJS mixins:

- `passkeyLogin` — Login page authentication flow
- `passkeyManage` — My Account credential management (add/rename/delete)
- `enrollmentPrompt` — Enrollment banner after password login

Templates are in `view/frontend/templates/` and can be overridden via theme fallback. Styles use Luma/blank theme variables and patterns (`.message.info`, `.data.table`, `.action.primary`) for native theme consistency.

## Security

- **HTTPS required**: WebAuthn ceremonies are rejected by browsers on non-secure origins. The module detects non-secure contexts and displays a specific error message.
- **Single-use challenges**: Each challenge token is consumed on verification and cannot be reused.
- **Rate limiting**: Options generation (10 requests/60s) and verification failures (5 failures/900s) are rate-limited per customer.
- **Sign-count validation**: Detects cloned authenticators by tracking the signature counter.
- **Anti-enumeration**: Authentication options return a valid (but unusable) response for non-existent email addresses.
- **Ownership enforcement**: All credential operations validate that the credential belongs to the requesting customer.

## Contributing

Issues and pull requests welcome on GitHub.

## License

This module is licensed under the [Open Software License 3.0](https://opensource.org/licenses/OSL-3.0).

## Support

- **Issues**: [GitHub Issues](https://github.com/mage-os-lab/module-passkey-auth/issues)
- **Community**: [Mage-OS Discord](http://chat.mage-os.org)
