<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Api\AdminTfa;

use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\User\Api\Data\UserInterface;

interface AuthenticateInterface
{
    /**
     * Generate WebAuthn assertion options for the admin user.
     *
     * @param UserInterface $user
     * @param string $providerCode
     * @return array{credentialRequestOptions: array, challengeToken: string}
     * @throws LocalizedException
     */
    public function getAuthenticationData(UserInterface $user, string $providerCode): array;

    /**
     * Verify WebAuthn assertion response.
     *
     * Called by Engine::verify() and by AuthPost controller.
     *
     * @param UserInterface $user
     * @param DataObject $request Must contain 'challenge_token' and 'credential' keys
     * @return bool
     * @throws LocalizedException
     */
    public function verifyAssertion(UserInterface $user, DataObject $request): bool;
}
