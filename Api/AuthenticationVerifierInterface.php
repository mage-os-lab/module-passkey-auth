<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Api;

use MageOS\PasskeyAuth\Api\Data\AuthenticationResultInterface;

/**
 * @api
 */
interface AuthenticationVerifierInterface
{
    /**
     * Verify a WebAuthn assertion response and generate an access token.
     *
     * @param string $challengeToken
     * @param string $assertionResponseJson
     * @return \MageOS\PasskeyAuth\Api\Data\AuthenticationResultInterface
     */
    public function verify(string $challengeToken, string $assertionResponseJson): AuthenticationResultInterface;
}
