# Frontend UX Review — Passkey Authentication

Date: 2026-07-10 · Scope: Luma + Hyvä storefront flows (login, enrollment,
credential management, checkout), based on `main` + Hyvä support (PR #2) +
admin TFA (PR #3).

## Summary

The core ceremony flows were already solid: both themes had working
register/login/manage flows, loading states in Hyvä, and cancellation
handling. The dominant gap was **discoverability** — a customer had to
notice a button below the fold of the login page to ever use a passkey —
plus a set of smaller friction points listed below.

## Implemented in this pass

1. **Passkey autofill (WebAuthn conditional mediation)** — the single
   biggest UX gap. Saved passkeys now appear directly in the browser's
   email-field autofill dropdown on the login page (both themes) and at
   checkout for guests (guest email field + authentication popup). Email
   fields are tagged `autocomplete="… webauthn"`. The pending conditional
   request is aborted before any modal ceremony starts (browsers allow only
   one active WebAuthn request) and re-armed if the modal flow fails.
   Verification failures from long-idle tabs (expired 5-minute challenge)
   surface a retry message and re-arm, bounded to avoid loops.

2. **Enrollment prompt fatigue** — the "Sign in faster with a passkey"
   banner reappeared every session forever (sessionStorage dismiss). Now:
   dismissing snoozes it for 30 days via localStorage, and after 3
   dismissals it stays gone. It also no longer renders on browsers without
   WebAuthn support, where "Set up" could only ever lead to an error.

3. **Default passkey names** — the name prompt now prefills a recognizable
   suggestion derived from the browser/platform ("Chrome on Windows",
   "Safari on iOS") instead of asking customers to invent a name for an
   empty field. Unnamed passkeys were previously indistinguishable rows
   labelled "Passkey".

4. **Better ceremony errors** — `InvalidStateError` (this authenticator is
   already registered) now explains itself instead of failing generically;
   Luma's login button gets a busy state ("Waiting for your passkey…",
   `aria-busy`, wait cursor) matching what Hyvä already had.

5. **Rename with Enter fixed (Luma)** — the Enter/Escape key handler was
   bound with `.one()`, so it was consumed by the first keystroke of
   typing; pressing Enter after editing did not save. Rebound with a
   namespaced handler that is removed on save/cancel.

6. **Delete confirmations name the passkey** — "Delete 'Chrome on
   Windows'? You will no longer be able to sign in with it." instead of a
   generic "Are you sure?", in both themes and in the new admin grid.

7. **Localized dates** — Created/Last Used were raw UTC database strings
   (`2026-03-12 09:41:22`); now rendered via `formatDate()` in the store
   locale/timezone, with time shown for Last Used.

8. **Responsive management table (Luma)** — added `data-th` attributes so
   the theme's stacked-table pattern labels cells on mobile; name input
   has an `aria-label`.

9. **Passkey icon** on the sign-in buttons in both themes for faster
   recognition (inline SVG, `currentColor`, no external asset).

## Recommended, not implemented here

- **Passkey button inside the checkout authentication popup.** Conditional
  autofill covers the popup's email field, but an explicit button would
  need a knockout template override of
  `Magento_Customer/web/template/authentication-popup.html` via mixin —
  version-drift-prone; revisit if analytics show autofill alone
  underperforms there.
- **AAGUID → provider name mapping** ("Google Password Manager", "iCloud
  Keychain") for the management table and the admin grid, using the
  community AAGUID list from the FIDO MDS. Needs a data-refresh strategy;
  high polish value for support conversations.
- **Hyvä Checkout coverage.** Hyvä Checkout is a separate product with its
  own login components; the conditional driver here only wires Luma
  checkout. A `hyva-themes/hyva-checkout` compat layer is a natural
  follow-up.
- **Enrollment interstitial after first password login** (full-page or
  modal offer with a "don't ask again") tends to convert better than a
  passive banner; consider as an optional mode of `prompt_after_login`.
- **WebAuthn L3 `hints`** (`client-device`, `security-key`, `hybrid`) once
  webauthn-lib and browsers settle, so merchants can steer the first
  dialog the customer sees.
- **Re-authentication before sensitive actions** — deleting a passkey (or
  changing email) could require a fresh ceremony or password confirm;
  today an unattended logged-in session can remove credentials silently
  (the notification email added in this pass mitigates but doesn't
  prevent).
- **Translations** — the Hyvä Alpine components carry English literals in
  JS (pre-existing pattern); moving them into `.phtml`-provided config
  would make them translatable. Luma strings all go through `$t()`.
