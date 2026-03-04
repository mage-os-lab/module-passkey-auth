<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Api;

use MageOS\PasskeyAuth\Api\Data\AuthenticationResultInterface;

interface AuthenticationVerifierInterface
{
    /**
     * Verify a WebAuthn assertion response and generate an access token.
     *
     * @param string $challengeToken
     * @param string $assertionResponseJson
     * @return AuthenticationResultInterface
     */
    public function verify(string $challengeToken, string $assertionResponseJson): AuthenticationResultInterface;
}
