<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\Registration;

use MageOS\PasskeyAuth\Api\CredentialRepositoryInterface;
use MageOS\PasskeyAuth\Api\RegistrationOptionsInterface;
use MageOS\PasskeyAuth\Model\ChallengeManager;
use MageOS\PasskeyAuth\Model\Config;
use MageOS\PasskeyAuth\Model\RateLimiter;
use MageOS\PasskeyAuth\Model\UserHandleGenerator;
use MageOS\PasskeyAuth\Model\WebAuthn\SerializerFactory;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

class OptionsGenerator implements RegistrationOptionsInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly CredentialRepositoryInterface $credentialRepository,
        private readonly UserHandleGenerator $userHandleGenerator,
        private readonly ChallengeManager $challengeManager,
        private readonly SerializerFactory $serializerFactory,
        private readonly Json $json,
        private readonly RateLimiter $rateLimiter
    ) {
    }

    public function generate(int $customerId): string
    {
        if (!$this->config->isEnabled()) {
            throw new LocalizedException(__('Passkey authentication is not enabled.'));
        }

        $this->rateLimiter->checkOptionsRate('reg_' . $customerId);

        $maxCredentials = $this->config->getMaxCredentials();
        if ($this->credentialRepository->countByCustomerId($customerId) >= $maxCredentials) {
            throw new LocalizedException(__('Maximum number of passkeys (%1) reached.', $maxCredentials));
        }

        $customer = $this->customerRepository->getById($customerId);
        $userHandle = $this->userHandleGenerator->getOrGenerate($customerId);

        $rpEntity = PublicKeyCredentialRpEntity::create(
            $this->config->getRpName(),
            $this->config->getRpId()
        );

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

        $authenticatorSelection = AuthenticatorSelectionCriteria::create(
            authenticatorAttachment: $this->config->getAuthenticatorAttachment(),
            userVerification: $this->config->getUserVerification(),
            residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED,
        );

        $options = PublicKeyCredentialCreationOptions::create(
            rp: $rpEntity,
            user: $userEntity,
            challenge: random_bytes(32),
            pubKeyCredParams: [
                PublicKeyCredentialParameters::create('public-key', -7),  // ES256
                PublicKeyCredentialParameters::create('public-key', -257), // RS256
            ],
            authenticatorSelection: $authenticatorSelection,
            attestation: $this->config->getAttestationConveyance(),
            excludeCredentials: $excludeCredentials,
            timeout: $this->config->getCeremonyTimeout(),
        );

        $serializer = $this->serializerFactory->get();
        $serializedOptions = $serializer->serialize($options, 'json', [
            AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
        ]);

        $challengeToken = $this->challengeManager->create(
            ChallengeManager::TYPE_REGISTRATION,
            $serializedOptions,
            $customerId
        );

        $optionsArray = $this->json->unserialize($serializedOptions);
        $optionsArray['challengeToken'] = $challengeToken;

        return $this->json->serialize($optionsArray);
    }
}
