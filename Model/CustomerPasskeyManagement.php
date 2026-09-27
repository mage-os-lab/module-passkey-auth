<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model;

use MageOS\PasskeyAuth\Api\CredentialManagementInterface;
use MageOS\PasskeyAuth\Api\CustomerPasskeyManagementInterface;
use MageOS\PasskeyAuth\Api\Data\CustomerPasskeyInterface;
use MageOS\PasskeyAuth\Api\RegistrationVerifierInterface;

class CustomerPasskeyManagement implements CustomerPasskeyManagementInterface
{
    public function __construct(
        private readonly CredentialManagementInterface $credentialManagement,
        private readonly RegistrationVerifierInterface $registrationVerifier,
        private readonly CustomerPasskeyMapper $mapper
    ) {
    }

    public function getPasskeys(int $customerId): array
    {
        return array_map(
            $this->mapper->map(...),
            $this->credentialManagement->listCredentials($customerId)
        );
    }

    public function renamePasskey(int $customerId, int $entityId, string $friendlyName): CustomerPasskeyInterface
    {
        return $this->mapper->map(
            $this->credentialManagement->renameCredential($customerId, $entityId, $friendlyName)
        );
    }

    public function verifyRegistration(
        int $customerId,
        string $challengeToken,
        string $attestationResponseJson,
        ?string $friendlyName = null
    ): CustomerPasskeyInterface {
        return $this->mapper->map(
            $this->registrationVerifier->verify($customerId, $challengeToken, $attestationResponseJson, $friendlyName)
        );
    }
}
