<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Api;

/**
 * @api
 */
interface RegistrationOptionsInterface
{
    /**
     * Generate WebAuthn registration options for a customer.
     *
     * @param int $customerId
     * @return string JSON-encoded PublicKeyCredentialCreationOptions with challengeToken
     */
    public function generate(int $customerId): string;
}
