<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Api;

use MageOS\PasskeyAuth\Api\Data\CredentialInterface;

/**
 * @api
 */
interface RegistrationVerifierInterface
{
    /**
     * Verify a WebAuthn attestation response and store the credential.
     *
     * @param int $customerId
     * @param string $challengeToken
     * @param string $attestationResponseJson
     * @param string|null $friendlyName
     * @return CredentialInterface
     */
    public function verify(
        int $customerId,
        string $challengeToken,
        string $attestationResponseJson,
        ?string $friendlyName = null
    ): CredentialInterface;
}
