<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Api;

/**
 * @api
 */
interface AuthenticationOptionsInterface
{
    /**
     * Generate WebAuthn authentication options.
     *
     * @param string|null $email Customer email (optional for discoverable credentials)
     * @return string JSON-encoded PublicKeyCredentialRequestOptions with challengeToken
     */
    public function generate(?string $email = null): string;
}
