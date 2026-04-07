<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Api\AdminTfa;

use Magento\Framework\Exception\LocalizedException;
use Magento\User\Api\Data\UserInterface;

interface ConfigureInterface
{
    /**
     * Generate WebAuthn registration options for the admin user.
     *
     * @param UserInterface $user
     * @param string $authenticatorPolicy 'all' or 'hardware'
     * @return array{publicKey: array, challengeToken: string}
     * @throws LocalizedException
     */
    public function getRegistrationData(UserInterface $user, string $authenticatorPolicy): array;

    /**
     * Validate attestation response and store credential.
     *
     * @param UserInterface $user
     * @param string $challengeToken
     * @param string $attestationResponseJson
     * @param string $providerCode
     * @param string|null $friendlyName
     * @throws LocalizedException
     */
    public function activate(
        UserInterface $user,
        string $challengeToken,
        string $attestationResponseJson,
        string $providerCode,
        ?string $friendlyName = null
    ): void;
}
