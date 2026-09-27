<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\Resolver;

use MageOS\PasskeyAuth\Api\Data\CredentialInterface;
use MageOS\PasskeyAuth\Api\Data\CustomerPasskeyInterface;
use MageOS\PasskeyAuth\Model\CustomerPasskeyMapper;

class CredentialFormatter
{
    public function __construct(
        private readonly CustomerPasskeyMapper $mapper
    ) {
    }

    /**
     * Shape a credential for the CustomerPasskey GraphQL type, with the same fields as the REST response.
     */
    public function format(CredentialInterface $credential): array
    {
        $passkey = $this->mapper->map($credential);

        return [
            CustomerPasskeyInterface::ID => $passkey->getId(),
            CustomerPasskeyInterface::NAME => $passkey->getName(),
            CustomerPasskeyInterface::TRANSPORTS => $passkey->getTransports(),
            CustomerPasskeyInterface::CREATED_AT => $passkey->getCreatedAt(),
            CustomerPasskeyInterface::LAST_USED_AT => $passkey->getLastUsedAt(),
        ];
    }
}
