<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\Registration;

use MageOS\PasskeyAuth\Api\CredentialRepositoryInterface;
use MageOS\PasskeyAuth\Api\RegistrationOptionsInterface;
use MageOS\PasskeyAuth\Model\ChallengeManager;
use MageOS\PasskeyAuth\Model\Config;
use MageOS\PasskeyAuth\Model\RateLimiter;
use MageOS\PasskeyAuth\Model\UserHandleGenerator;
use MageOS\PasskeyAuth\Model\WebAuthn\Ceremony;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialUserEntity;

class OptionsGenerator implements RegistrationOptionsInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly CredentialRepositoryInterface $credentialRepository,
        private readonly UserHandleGenerator $userHandleGenerator,
        private readonly Ceremony $ceremony,
        private readonly Json $json,
        private readonly RateLimiter $rateLimiter,
        private readonly AdminImpersonationGuard $adminImpersonationGuard
    ) {
    }

    public function generate(int $customerId): string
    {
        if (!$this->config->isEnabled()) {
            throw new LocalizedException(__('Passkey authentication is not enabled.'));
        }

        $this->adminImpersonationGuard->assertNotImpersonated();
        $this->rateLimiter->checkOptionsRate('reg_' . $customerId);

        $maxCredentials = $this->config->getMaxCredentials();
        if ($this->credentialRepository->countByCustomerId($customerId) >= $maxCredentials) {
            throw new LocalizedException(__('Maximum number of passkeys (%1) reached.', $maxCredentials));
        }

        $customer = $this->customerRepository->getById($customerId);
        $userHandle = $this->userHandleGenerator->getOrGenerate($customerId);

        $userEntity = PublicKeyCredentialUserEntity::create(
            $customer->getEmail(),
            $userHandle,
            $customer->getFirstname() . ' ' . $customer->getLastname()
        );

        $excludeCredentials = [];
        foreach ($this->credentialRepository->getByCustomerId($customerId) as $credential) {
            $transports = $credential->getTransportsArray();
            $excludeCredentials[] = PublicKeyCredentialDescriptor::create(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                base64_decode($credential->getCredentialId()),
                $transports
            );
        }

        $optionsArray = $this->ceremony->createRegistrationOptions(
            $userEntity,
            $excludeCredentials,
            ChallengeManager::TYPE_REGISTRATION,
            $customerId
        );

        return $this->json->serialize($optionsArray);
    }
}
