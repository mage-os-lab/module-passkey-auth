# Installation

## Requirements

| Component | Version |
|---|---|
| PHP | 8.2 or later |
| Magento Open Source or Mage-OS | 2.4.6 or later |
| Magento_TwoFactorAuth | Installed and enabled (the module depends on it) |
| HTTPS | Required. Browsers only allow passkeys on secure pages. `localhost` also counts as secure for local development. |

The module installs [`web-auth/webauthn-lib`](https://github.com/web-auth/webauthn-lib) 5.2 or later through Composer.

GraphQL support needs `Magento_GraphQl`. It is optional.

## Install

```bash
composer require mage-os/module-passkey-auth
bin/magento setup:upgrade
bin/magento cache:flush
```

In production mode, also run `setup:di:compile` and `setup:static-content:deploy` as part of your normal deployment.

Cron must be running. The `passkey_challenge_cleanup` job runs every 5 minutes and removes expired sign-in challenges.

## Turn on customer passkeys

Customer passkeys are off after install. Turn them on in the admin:

**Stores > Configuration > Customers > Customer Configuration > Passkey Authentication > Enable Passkey Authentication = Yes**

Or from the command line:

```bash
bin/magento config:set customer/passkey/enabled 1
bin/magento cache:flush
```

To turn it on for one website only, see [Scope](configuration.md#scope).

The admin two-factor provider does not use this setting. See [Admin two-factor authentication](admin-2fa.md).

## Check that it works

1. Open the storefront over HTTPS and sign in with a password.
2. Go to **My Account > Passkeys** and click **Add a Passkey**.
3. Sign out. On the login page, click **Sign in with Passkey**, or click the email field and pick the passkey from the autofill list.

If the passkey section does not appear, see [Troubleshooting](troubleshooting.md).

## Themes

**Luma and Blank-based themes** work without extra steps.

**Hyvä** works without a compatibility module. The module ships `hyva_*` layout files that swap in Alpine.js templates. The templates use Tailwind classes. If a style looks missing, make sure your theme's Tailwind build scans this module's `view/frontend/templates/hyva` directory, then rebuild your theme CSS.

**Hyvä Checkout** and other custom checkouts do not get passkey autofill. The checkout integration targets the Luma checkout fields.

## Upgrade

```bash
composer update mage-os/module-passkey-auth
bin/magento setup:upgrade
bin/magento cache:flush
```

Registered passkeys are kept across upgrades.

### Upgrading from a 1.0.0 beta

Before 1.0, customer passkeys were on by default. They are now off by default. If your store already has customer passkeys and never saved **Enable Passkey Authentication** in Default Config, `setup:upgrade` saves it as **Yes** in Default Config so passkey sign-in keeps working. If you saved it in Default Config yourself, your value is kept. Values saved for a website or store view are always kept.

1.0 also changes the REST responses, the event data, and some PHP classes. Read the breaking changes in [CHANGELOG.md](../CHANGELOG.md) before you upgrade a store with custom code or a headless frontend.

## Remove

1. If admins use passkey two-factor authentication, first remove **Passkey** from the list of allowed providers and reset admin passkeys, so nobody is left without a working second factor:

   ```bash
   bin/magento security:tfa:passkey:reset-all
   ```

2. Remove the module:

   ```bash
   bin/magento module:disable MageOS_PasskeyAuth
   composer remove mage-os/module-passkey-auth
   bin/magento setup:upgrade
   ```

The `passkey_credential` and `passkey_challenge` tables may stay in the database. Back up and drop them yourself if you want the data gone.
