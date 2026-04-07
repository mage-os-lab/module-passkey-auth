<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\AdminTfa;

use MageOS\PasskeyAuth\Api\AdminTfa\AuthenticateInterface;
use MageOS\PasskeyAuth\Model\ChallengeManager;
use MageOS\PasskeyAuth\Model\WebAuthn\CeremonyStepManagerProvider;
use MageOS\PasskeyAuth\Model\WebAuthn\SerializerFactory;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\TwoFactorAuth\Api\UserConfigManagerInterface;
use Magento\User\Api\Data\UserInterface;
use Psr\Log\LoggerInterface;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialSource;

class Authenticate implements AuthenticateInterface
{
    private const CHALLENGE_TYPE = 'admin_authentication';

    public function __construct(
        private readonly AdminTfaConfig $adminTfaConfig,
        private readonly OriginValidator $originValidator,
        private readonly ChallengeManager $challengeManager,
        private readonly CeremonyStepManagerProvider $ceremonyStepManagerProvider,
        private readonly SerializerFactory $serializerFactory,
        private readonly UserConfigManagerInterface $userConfigManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getAuthenticationData(UserInterface $user, string $providerCode): array
    {
        $userId = (int) $user->getId();
        $config = $this->userConfigManager->getProviderConfig($userId, $providerCode);

        if (empty($config) || !isset($config['registration']['credential_id'])) {
            throw new LocalizedException(__('Passkey is not configured for this user.'));
        }

        $this->originValidator->validate($config['registration']);

        $credentialId = base64_decode($config['registration']['credential_id']);

        $allowCredentials = [
            PublicKeyCredentialDescriptor::create('public-key', $credentialId),
        ];

        $options = PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: $this->adminTfaConfig->getRpId(),
            allowCredentials: $allowCredentials,
            userVerification: $this->adminTfaConfig->getUserVerification(),
            timeout: 60000,
        );

        $serializer = $this->serializerFactory->get();
        $optionsJson = $serializer->serialize($options, 'json');

        $challengeToken = $this->challengeManager->create(self::CHALLENGE_TYPE, $optionsJson);

        $optionsArray = json_decode($optionsJson, true);
        $optionsArray['challengeToken'] = $challengeToken;

        return $optionsArray;
    }

    public function verifyAssertion(UserInterface $user, DataObject $request): bool
    {
        $challengeToken = $request->getData('challenge_token');
        $credentialJson = $request->getData('credential');

        if (!$challengeToken || !$credentialJson) {
            throw new LocalizedException(__('Missing challenge token or credential data.'));
        }

        $userId = (int) $user->getId();
        $providerCode = $this->resolveProviderCode($userId);

        $config = $this->userConfigManager->getProviderConfig($userId, $providerCode);
        if (empty($config) || !isset($config['registration'])) {
            throw new LocalizedException(__('Passkey is not configured for this user.'));
        }

        $this->originValidator->validate($config['registration']);

        $optionsJson = $this->challengeManager->consume($challengeToken, self::CHALLENGE_TYPE);

        $serializer = $this->serializerFactory->get();

        $requestOptions = $serializer->deserialize(
            $optionsJson,
            PublicKeyCredentialRequestOptions::class,
            'json'
        );

        $credential = $serializer->deserialize(
            $credentialJson,
            PublicKeyCredential::class,
            'json'
        );

        $response = $credential->response;
        if (!$response instanceof \Webauthn\AuthenticatorAssertionResponse) {
            throw new LocalizedException(__('Invalid assertion response.'));
        }

        $storedSource = $serializer->deserialize(
            $config['registration']['credential_source'],
            PublicKeyCredentialSource::class,
            'json'
        );

        $ceremonyStepManager = $this->ceremonyStepManagerProvider->getRequestCeremony();
        $validator = AuthenticatorAssertionResponseValidator::create($ceremonyStepManager);

        $updatedSource = $validator->check($storedSource, $response, $requestOptions);

        // Clone detection: warn on counter regression
        $storedCount = (int) ($config['registration']['sign_count'] ?? 0);
        $newCount = $updatedSource->counter;
        if ($newCount > 0 && $newCount <= $storedCount) {
            $this->logger->warning('Passkey sign counter regression detected (possible clone)', [
                'admin_user_id' => $userId,
                'stored_count' => $storedCount,
                'new_count' => $newCount,
            ]);
        }

        // Update stored credential
        $config['registration']['credential_source'] = $serializer->serialize($updatedSource, 'json');
        $config['registration']['sign_count'] = $updatedSource->counter;
        $config['registration']['last_used_at'] = date('c');

        $this->userConfigManager->setProviderConfig($userId, $providerCode, $config);

        $this->logger->info('Admin passkey authentication successful', [
            'admin_user_id' => $userId,
            'provider' => $providerCode,
        ]);

        return true;
    }

    /**
     * Determine which passkey provider code is active for this user.
     */
    private function resolveProviderCode(int $userId): string
    {
        foreach ([Engine::PROVIDER_CODE_ALL, Engine::PROVIDER_CODE_HARDWARE] as $code) {
            $config = $this->userConfigManager->getProviderConfig($userId, $code);
            if (!empty($config) && isset($config['registration'])) {
                return $code;
            }
        }
        throw new LocalizedException(__('No passkey provider configured for this user.'));
    }
}
