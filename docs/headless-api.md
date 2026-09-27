# Headless API

Everything the storefront does is also available over REST and GraphQL. Both require **Enable Passkey Authentication** to be on.

## How a passkey ceremony works

Both sign-in and registration take two calls:

1. **Get options.** The server returns WebAuthn options and a `challengeToken`.
2. **Call the browser.** Pass the options to `navigator.credentials.get()` (sign-in) or `navigator.credentials.create()` (registration).
3. **Verify.** Send the browser's response and the `challengeToken` back. Sign-in returns a customer token. Registration returns the new passkey.

A `challengeToken` works once and expires after 5 minutes.

The page that calls the browser must be served from the store's domain. Passkeys are bound to the domain of the store's base URL, and the server checks the origin of each response. A headless frontend on a different domain from the Magento base URL will not work. See [Configuration](configuration.md#the-store-domain).

## Data format

Options come back as JSON in the standard WebAuthn layout, plus one extra field, `challengeToken`. Binary values (`challenge`, `user.id`, and the `id` of each entry in `allowCredentials` and `excludeCredentials`) are base64url strings. Convert them to `ArrayBuffer` before calling the browser.

The browser's response must be sent back as JSON, with binary values base64url-encoded.

Sign-in response:

```json
{
  "id": "…",
  "rawId": "<base64url>",
  "type": "public-key",
  "response": {
    "clientDataJSON": "<base64url>",
    "authenticatorData": "<base64url>",
    "signature": "<base64url>",
    "userHandle": "<base64url or null>"
  }
}
```

Registration response:

```json
{
  "id": "…",
  "rawId": "<base64url>",
  "type": "public-key",
  "response": {
    "clientDataJSON": "<base64url>",
    "attestationObject": "<base64url>",
    "transports": ["internal", "hybrid"]
  }
}
```

`view/base/web/js/passkey-core.js` in this module is a dependency-free reference for these conversions. It works as a RequireJS module or as a plain script (`window.passkeyCore`).

## GraphQL

Send the customer token as `Authorization: Bearer <token>` for everything except sign-in.

| Operation | Auth | Purpose |
|---|---|---|
| `mutation createPasskeyAuthenticationOptions(email: String)` | Guest | Start sign-in |
| `mutation verifyPasskeyAuthentication(input)` | Guest | Finish sign-in, get a customer token |
| `mutation createPasskeyRegistrationOptions` | Customer | Start adding a passkey |
| `mutation verifyPasskeyRegistration(input)` | Customer | Finish adding a passkey |
| `query customerPasskeys` | Customer | List passkeys |
| `mutation renameCustomerPasskey(passkeyId, name)` | Customer | Rename a passkey |
| `mutation deleteCustomerPasskey(passkeyId)` | Customer | Delete a passkey |

Options are returned in `options_json` as a JSON string. Browser responses are sent as JSON strings too.

### Sign in

```graphql
mutation {
  createPasskeyAuthenticationOptions(email: "jane@example.com") {
    options_json
  }
}
```

`email` is optional. Leave it out to let the customer pick any passkey saved for your site (a discoverable passkey). With an email, the browser only offers that account's passkeys.

```graphql
mutation {
  verifyPasskeyAuthentication(input: {
    challenge_token: "…"
    assertion_response: "{\"id\":\"…\",\"rawId\":\"…\",…}"
  }) {
    customer_token
  }
}
```

Use `customer_token` like a token from `generateCustomerToken`. Its lifetime follows **Stores > Configuration > Services > OAuth > Access Token Expiration**.

Any failure returns the same message, "Passkey verification failed. Please try again.", so callers can't tell which accounts exist.

### Register

```graphql
mutation {
  createPasskeyRegistrationOptions {
    options_json
  }
}
```

```graphql
mutation {
  verifyPasskeyRegistration(input: {
    challenge_token: "…"
    attestation_response: "{\"id\":\"…\",\"rawId\":\"…\",…}"
    name: "Chrome on Windows"
  }) {
    id
    name
  }
}
```

`name` is optional. It can be up to 255 characters and can't contain `<`, `>`, or `&`.

### Manage

```graphql
query {
  customerPasskeys {
    id
    name
    transports
    created_at
    last_used_at
  }
}

mutation {
  renameCustomerPasskey(passkeyId: 1, name: "Work laptop") { id name }
}

mutation {
  deleteCustomerPasskey(passkeyId: 1) { success }
}
```

Dates are UTC. `customerPasskeys` is never cached.

### Browser example

```js
const b64urlToBuf = (s) =>
  Uint8Array.from(atob(s.replace(/-/g, '+').replace(/_/g, '/')), (c) => c.charCodeAt(0)).buffer;
const bufToB64url = (b) =>
  btoa(String.fromCharCode(...new Uint8Array(b))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

async function signInWithPasskey(gql, email) {
  const { createPasskeyAuthenticationOptions: o } = await gql(
    `mutation($email: String) { createPasskeyAuthenticationOptions(email: $email) { options_json } }`,
    { email }
  );
  const options = JSON.parse(o.options_json);

  const credential = await navigator.credentials.get({
    publicKey: {
      challenge: b64urlToBuf(options.challenge),
      rpId: options.rpId,
      userVerification: options.userVerification,
      timeout: options.timeout,
      allowCredentials: (options.allowCredentials || []).map((c) => ({ ...c, id: b64urlToBuf(c.id) })),
    },
  });

  const r = credential.response;
  const assertion = JSON.stringify({
    id: credential.id,
    rawId: bufToB64url(credential.rawId),
    type: credential.type,
    response: {
      clientDataJSON: bufToB64url(r.clientDataJSON),
      authenticatorData: bufToB64url(r.authenticatorData),
      signature: bufToB64url(r.signature),
      userHandle: r.userHandle ? bufToB64url(r.userHandle) : null,
    },
  });

  const { verifyPasskeyAuthentication: v } = await gql(
    `mutation($t: String!, $a: String!) {
       verifyPasskeyAuthentication(input: { challenge_token: $t, assertion_response: $a }) { customer_token }
     }`,
    { t: options.challengeToken, a: assertion }
  );
  return v.customer_token;
}
```

Registration follows the same pattern with `navigator.credentials.create()`. Decode `challenge`, `user.id`, and each `excludeCredentials[].id`, and send `attestationObject` and `transports` instead of `authenticatorData` and `signature`.

## REST

Customer endpoints need a customer bearer token. The customer ID always comes from the token.

| Method | Endpoint | Auth | Body | Returns |
|---|---|---|---|---|
| POST | `/V1/passkey/authentication/options` | Guest | `{"email": "…"}` (optional) | Options as a JSON string |
| POST | `/V1/passkey/authentication/verify` | Guest | `{"challengeToken": "…", "assertionResponseJson": "…"}` | `{"customer_id": 5, "token": "…"}` |
| POST | `/V1/passkey/registration/options` | Customer | `{}` | Options as a JSON string |
| POST | `/V1/passkey/registration/verify` | Customer | `{"challengeToken": "…", "attestationResponseJson": "…", "friendlyName": "…"}` | The new passkey |
| GET | `/V1/passkey/credentials` | Customer | | List of passkeys |
| PUT | `/V1/passkey/credentials/:entityId` | Customer | `{"friendlyName": "…"}` | The renamed passkey |
| DELETE | `/V1/passkey/credentials/:entityId` | Customer | | `true` |

The two options endpoints return a JSON **string**, so the response body is JSON inside a JSON string. Decode it twice:

```bash
curl -s -X POST https://shop.example.com/rest/V1/passkey/authentication/options \
  -H 'Content-Type: application/json' \
  -d '{"email":"jane@example.com"}' | jq -r . | jq .
```

`assertionResponseJson` and `attestationResponseJson` are the browser responses as JSON strings, the same as in GraphQL.

Passkey objects contain `entity_id`, `friendly_name`, `transports`, `created_at`, `last_used_at`, and some technical fields (`credential_id`, `aaguid`, `sign_count`, and the stored public key record).

## Errors and limits

- Requests with passkeys turned off fail with "Passkey authentication is not enabled."
- Options requests and failed sign-ins are rate-limited. See [Rate limits](security.md#rate-limits).
- A customer can have at most 10 passkeys. The options call fails with "Maximum number of passkeys (10) reached." after that.
- A browser that already holds a passkey for the account refuses to create another one. The browser raises `InvalidStateError`.
