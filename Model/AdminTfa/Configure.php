<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\AdminTfa;

use MageOS\PasskeyAuth\Api\AdminTfa\ConfigureInterface;
use MageOS\PasskeyAuth\Model\ChallengeManager;
use MageOS\PasskeyAuth\Model\WebAuthn\CeremonyStepManagerProvider;
use MageOS\PasskeyAuth\Model\WebAuthn\SerializerFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\TwoFactorAuth\Api\UserConfigManagerInterface;
use Magento\User\Api\Data\UserInterface;
use Psr\Log\LoggerInterface;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

class Configure implements ConfigureInterface
{
    private const CHALLENGE_TYPE = 'admin_registration';

    public function __construct(
        private readonly AdminTfaConfig $adminTfaConfig,
        private readonly ChallengeManager $challengeManager,
        private readonly CeremonyStepManagerProvider $ceremonyStepManagerProvider,
        private readonly SerializerFactory $serializerFactory,
        private readonly UserConfigManagerInterface $userConfigManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getRegistrationData(UserInterface $user, string $authenticatorPolicy): array
    {
        $rpEntity = PublicKeyCredentialRpEntity::create(
            $this->adminTfaConfig->getRpName(),
            $this->adminTfaConfig->getRpId()
        );

        $userEntity = PublicKeyCredentialUserEntity::create(
            $user->getUserName(),
            hash('sha256', (string) $user->getId()),
            $user->getFirstName() . ' ' . $user->getLastName()
        );

        $challenge = random_bytes(32);

        $credentialParameters = [
            PublicKeyCredentialParameters::create('public-key', -7),  // ES256
            PublicKeyCredentialParameters::create('public-key', -257), // RS256
        ];

        $authenticatorSelection = AuthenticatorSelectionCriteria::create(
            authenticatorAttachment: $this->adminTfaConfig->getAuthenticatorAttachment($authenticatorPolicy),
            userVerification: $this->adminTfaConfig->getUserVerification(),
            residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_DISCOURAGED,
        );

        $excludeCredentials = $this->getExcludeCredentials($user);

        $options = PublicKeyCredentialCreationOptions::create(
            rp: $rpEntity,
            user: $userEntity,
            challenge: $challenge,
            pubKeyCredParams: $credentialParameters,
            authenticatorSelection: $authenticatorSelection,
            attestation: $this->adminTfaConfig->getAttestation($authenticatorPolicy),
            excludeCredentials: $excludeCredentials,
            timeout: 60000,
        );

        $serializer = $this->serializerFactory->get();
        $optionsJson = $serializer->serialize($options, 'json');

        $challengeToken = $this->challengeManager->create(self::CHALLENGE_TYPE, $optionsJson);

        $optionsArray = json_decode($optionsJson, true);
        $optionsArray['challengeToken'] = $challengeToken;

        return $optionsArray;
    }

    public function activate(
        UserInterface $user,
        string $challengeToken,
        string $attestationResponseJson,
        string $providerCode,
        ?string $friendlyName = null
    ): void {
        $userId = (int) $user->getId();

        $optionsJson = $this->challengeManager->consume($challengeToken, self::CHALLENGE_TYPE);

        $serializer = $this->serializerFactory->get();

        $creationOptions = $serializer->deserialize(
            $optionsJson,
            PublicKeyCredentialCreationOptions::class,
            'json'
        );

        $credential = $serializer->deserialize(
            $attestationResponseJson,
            PublicKeyCredential::class,
            'json'
        );

        $response = $credential->response;
        if (!$response instanceof AuthenticatorAttestationResponse) {
            throw new LocalizedException(__('Invalid attestation response.'));
        }

        $ceremonyStepManager = $this->ceremonyStepManagerProvider->getCreationCeremony();
        $validator = AuthenticatorAttestationResponseValidator::create($ceremonyStepManager);

        $credentialSource = $validator->check($response, $creationOptions);

        $credentialSourceJson = $serializer->serialize($credentialSource, 'json');

        $this->userConfigManager->setProviderConfig($userId, $providerCode, [
            'registration' => [
                'credential_source' => $credentialSourceJson,
                'credential_id' => base64_encode($credentialSource->publicKeyCredentialId),
                'rp_id' => $this->adminTfaConfig->getRpId(),
                'friendly_name' => $friendlyName ? mb_substr(trim($friendlyName), 0, 255) : null,
                'aaguid' => $credentialSource->aaguid->toString(),
                'registered_at' => date('c'),
                'last_used_at' => null,
                'sign_count' => $credentialSource->counter,
            ],
        ]);
        $this->userConfigManager->activateProviderConfiguration($userId, $providerCode);

        $this->logger->info('Admin passkey registered', [
            'admin_user_id' => $userId,
            'provider' => $providerCode,
            'aaguid' => $credentialSource->aaguid->toString(),
        ]);
    }

    private function getExcludeCredentials(UserInterface $user): array
    {
        $excludeCredentials = [];
        foreach ([Engine::PROVIDER_CODE_ALL, Engine::PROVIDER_CODE_HARDWARE] as $code) {
            $config = $this->userConfigManager->getProviderConfig((int) $user->getId(), $code);
            if (isset($config['registration']['credential_id'])) {
                $excludeCredentials[] = PublicKeyCredentialDescriptor::create(
                    'public-key',
                    base64_decode($config['registration']['credential_id'])
                );
            }
        }
        return $excludeCredentials;
    }
}
