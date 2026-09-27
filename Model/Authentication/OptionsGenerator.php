<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\Authentication;

use MageOS\PasskeyAuth\Api\AuthenticationOptionsInterface;
use MageOS\PasskeyAuth\Api\CredentialRepositoryInterface;
use MageOS\PasskeyAuth\Model\ChallengeManager;
use MageOS\PasskeyAuth\Model\Config;
use MageOS\PasskeyAuth\Model\RateLimiter;
use MageOS\PasskeyAuth\Model\WebAuthn\Ceremony;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\StoreManagerInterface;
use Webauthn\PublicKeyCredentialDescriptor;

class OptionsGenerator implements AuthenticationOptionsInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly CredentialRepositoryInterface $credentialRepository,
        private readonly Ceremony $ceremony,
        private readonly StoreManagerInterface $storeManager,
        private readonly Json $json,
        private readonly RateLimiter $rateLimiter,
        private readonly RemoteAddress $remoteAddress,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function generate(?string $email = null): string
    {
        if (!$this->config->isEnabled()) {
            throw new LocalizedException(__('Passkey authentication is not enabled.'));
        }

        $ip = $this->remoteAddress->getRemoteAddress() ?: 'unknown';
        $emailKey = $email !== null ? mb_strtolower(trim($email)) : 'anonymous';
        $this->rateLimiter->checkOptionsRate('auth_' . $emailKey . '_' . $ip);

        $allowCredentials = [];
        $customerId = null;

        if ($email) {
            try {
                $websiteId = (int) $this->storeManager->getStore()->getWebsiteId();
                $customer = $this->customerRepository->get($email, $websiteId);
                $customerId = (int) $customer->getId();

                foreach ($this->credentialRepository->getByCustomerId($customerId) as $credential) {
                    $transports = $credential->getTransportsArray();
                    $allowCredentials[] = PublicKeyCredentialDescriptor::create(
                        PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                        base64_decode($credential->getCredentialId()),
                        $transports
                    );
                }
            } catch (NoSuchEntityException) {
                // Handled below, indistinguishable from an account without passkeys
            }

            if (!$allowCredentials) {
                $allowCredentials[] = $this->createDecoyDescriptor($email);
            }
        }

        $optionsArray = $this->ceremony->createAuthenticationOptions(
            $allowCredentials,
            ChallengeManager::TYPE_AUTHENTICATION,
            $customerId
        );

        return $this->json->serialize($optionsArray);
    }

    /**
     * Anti-enumeration: an email with no passkeys (or no account) gets a stable, secret-derived
     * descriptor, so its options look like those of an account with one passkey. It also stops the
     * browser from offering an unrelated passkey saved on the device for this site.
     */
    private function createDecoyDescriptor(string $email): PublicKeyCredentialDescriptor
    {
        return PublicKeyCredentialDescriptor::create(
            PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
            (string) hex2bin($this->encryptor->hash('passkey-decoy|' . mb_strtolower(trim($email)))),
            ['hybrid', 'internal']
        );
    }
}
