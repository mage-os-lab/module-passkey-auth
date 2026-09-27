<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\Authentication;

use MageOS\PasskeyAuth\Api\AuthenticationOptionsInterface;
use MageOS\PasskeyAuth\Api\CredentialRepositoryInterface;
use MageOS\PasskeyAuth\Model\ChallengeManager;
use MageOS\PasskeyAuth\Model\Config;
use MageOS\PasskeyAuth\Model\RateLimiter;
use MageOS\PasskeyAuth\Model\WebAuthn\Ceremony;
use Magento\Customer\Api\CustomerRepositoryInterface;
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
        private readonly RemoteAddress $remoteAddress
    ) {
    }

    public function generate(?string $email = null): string
    {
        if (!$this->config->isEnabled()) {
            throw new LocalizedException(__('Passkey authentication is not enabled.'));
        }

        $ip = $this->remoteAddress->getRemoteAddress() ?: 'unknown';
        $this->rateLimiter->checkOptionsRate('auth_' . ($email ?? 'anonymous') . '_' . $ip);

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
                // Anti-enumeration: return valid-looking response with empty allowCredentials
            }
        }

        $optionsArray = $this->ceremony->createAuthenticationOptions(
            $allowCredentials,
            ChallengeManager::TYPE_AUTHENTICATION,
            $customerId
        );

        return $this->json->serialize($optionsArray);
    }
}
