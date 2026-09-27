# Passkey Authentication manual

MageOS Passkey Authentication lets customers sign in to a Magento or Mage-OS store with a passkey instead of a password. It also adds a passkey option to admin two-factor authentication.

## Guides

| Guide | For |
|---|---|
| [Installation](installation.md) | Installing, enabling, upgrading, and removing the module |
| [Configuration](configuration.md) | Every admin setting, and how the store domain affects passkeys |
| [Customer guide](customer-guide.md) | Shoppers: signing in and managing passkeys. Adapt it for your store's help pages |
| [Store admin guide](store-admin-guide.md) | Merchants and support staff: the Customer Passkeys grid, emails, common support cases |
| [Admin two-factor authentication](admin-2fa.md) | Using a passkey as the second factor for admin sign-in |
| [Headless API](headless-api.md) | REST and GraphQL, with a full sign-in and registration walkthrough |
| [Developer guide](developer-guide.md) | Events, service contracts, themes, templates, emails, and tests |
| [How it works and security](security.md) | Challenges, domain binding, rate limits, and what is logged |
| [Troubleshooting](troubleshooting.md) | Common problems and fixes |

To report a vulnerability, see [SECURITY.md](../SECURITY.md). Changes between releases are listed in [CHANGELOG.md](../CHANGELOG.md).

## What a passkey is

A passkey is a key pair created by the customer's device, password manager, or security key. The store keeps only the public key. To sign in, the device proves it holds the private key, usually after a fingerprint, face scan, or device PIN.

Passkeys are tied to the store's domain, so a fake site on another domain cannot use them. There is no shared secret for the store to leak.

## Feature summary

- A "Sign in with Passkey" button on the login page, plus passkey suggestions in the email field's autofill list on the login page and Luma checkout.
- A **My Account > Passkeys** page to add, rename, and delete passkeys.
- An optional banner that invites customers to add a passkey.
- Emails to the customer when a passkey is added or removed.
- A **Customers > Customer Passkeys** admin grid to view and revoke passkeys.
- A Passkey provider for Magento's admin two-factor authentication.
- REST and GraphQL APIs for headless storefronts.
- Luma and Hyvä support.
