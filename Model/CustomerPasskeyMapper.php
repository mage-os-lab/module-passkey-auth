<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model;

use MageOS\PasskeyAuth\Api\Data\CredentialInterface;
use MageOS\PasskeyAuth\Api\Data\CustomerPasskeyInterface;
use MageOS\PasskeyAuth\Api\Data\CustomerPasskeyInterfaceFactory;

/**
 * Maps a stored credential to the customer-facing passkey shape used by REST and GraphQL.
 */
class CustomerPasskeyMapper
{
    public function __construct(
        private readonly CustomerPasskeyInterfaceFactory $customerPasskeyFactory
    ) {
    }

    public function map(CredentialInterface $credential): CustomerPasskeyInterface
    {
        /** @var CustomerPasskeyInterface $passkey */
        $passkey = $this->customerPasskeyFactory->create();
        $passkey->setId((int) $credential->getEntityId());
        $passkey->setName($credential->getFriendlyName());
        $passkey->setTransports($credential->getTransportsArray());
        $passkey->setCreatedAt((string) $credential->getCreatedAt());
        $passkey->setLastUsedAt($credential->getLastUsedAt());

        return $passkey;
    }
}
