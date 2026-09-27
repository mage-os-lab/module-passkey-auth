<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\AdminTfa;

use MageOS\PasskeyAuth\Model\WebAuthn\Ceremony;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\TwoFactorAuth\Api\EngineInterface;
use Magento\TwoFactorAuth\Api\UserConfigManagerInterface;
use Magento\User\Api\Data\UserInterface;
use Psr\Log\LoggerInterface;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Passkey 2FA engine for Magento_TwoFactorAuth. One passkey per admin user, stored in tfa_user_config.
 */
class Engine implements EngineInterface
{
    public const CODE = 'passkey';

    private const CHALLENGE_REGISTRATION = 'admin_registration';
    private const CHALLENGE_AUTHENTICATION = 'admin_authentication';

    public function __construct(
        private readonly UserConfigManagerInterface $userConfigManager,
        private readonly Ceremony $ceremony,
        private readonly AdminTfaConfig $adminTfaConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    public function isEnabled(): bool
    {
        return true;
    }

    /**
     * Build WebAuthn creation options for registering this user's passkey.
     *
     * @throws LocalizedException
     */
    public function getRegistrationOptions(UserInterface $user): array
    {
        $userEntity = PublicKeyCredentialUserEntity::create(
            $user->getUserName(),
            hash('sha256', (string) $user->getId()),
            trim($user->getFirstName() . ' ' . $user->getLastName())
        );

        return $this->ceremony->createRegistrationOptions($userEntity, [], self::CHALLENGE_REGISTRATION);
    }

    /**
     * Validate the attestation response, store the credential and activate the provider.
     *
     * @throws LocalizedException
     */
    public function activate(UserInterface $user, string $challengeToken, string $attestationJson): void
    {
        $userId = (int) $user->getId();

        try {
            $source = $this->ceremony->verifyRegistration(
                $challengeToken,
                $attestationJson,
                self::CHALLENGE_REGISTRATION
            );
        } catch (\Throwable $e) {
            throw $this->failure($e, $userId, __('Passkey registration failed. Please try again.'));
        }

        $this->userConfigManager->setProviderConfig($userId, self::CODE, [
            UserConfigManagerInterface::ACTIVE_CONFIG_KEY => true,
            'registration' => [
                'credential_source' => $this->ceremony->serializeSource($source),
                'credential_id' => base64_encode($source->publicKeyCredentialId),
                'rp_id' => $this->adminTfaConfig->getRpId(),
                'aaguid' => $source->aaguid->toString(),
                'registered_at' => date('c'),
                'last_used_at' => null,
                'sign_count' => $source->counter,
            ],
        ]);

        $this->logger->info('Admin passkey registered', [
            'admin_user_id' => $userId,
            'provider' => self::CODE,
            'aaguid' => $source->aaguid->toString(),
        ]);
    }

    /**
     * Build WebAuthn request options for this user's registered passkey.
     *
     * @throws LocalizedException
     */
    public function getAuthenticationOptions(UserInterface $user): array
    {
        $registration = $this->getRegistration($this->getConfig((int) $user->getId()));
        $this->assertSameDomain($registration);

        return $this->ceremony->createAuthenticationOptions(
            [$this->toDescriptor($registration)],
            self::CHALLENGE_AUTHENTICATION
        );
    }

    /**
     * Verify an assertion. $request must contain 'challenge_token' and 'credential'.
     *
     * @throws LocalizedException
     */
    public function verify(UserInterface $user, DataObject $request): bool
    {
        $userId = (int) $user->getId();
        $config = $this->getConfig($userId);
        $registration = $this->getRegistration($config);

        try {
            [$credential, $requestOptions] = $this->ceremony->loadAssertion(
                (string) $request->getData('challenge_token'),
                (string) $request->getData('credential'),
                self::CHALLENGE_AUTHENTICATION
            );
            // Also rejects a signature counter that did not increase (possible cloned authenticator)
            $source = $this->ceremony->verifyAssertion(
                $credential,
                $requestOptions,
                $this->ceremony->deserializeSource($registration['credential_source'])
            );
        } catch (\Throwable $e) {
            throw $this->failure($e, $userId, __('Passkey verification failed. Please try again.'));
        }

        $config['registration']['credential_source'] = $this->ceremony->serializeSource($source);
        $config['registration']['sign_count'] = $source->counter;
        $config['registration']['last_used_at'] = date('c');
        $this->userConfigManager->setProviderConfig($userId, self::CODE, $config);

        return true;
    }

    private function getConfig(int $userId): ?array
    {
        return $this->userConfigManager->getProviderConfig($userId, self::CODE);
    }

    /**
     * @throws LocalizedException
     */
    private function getRegistration(?array $config): array
    {
        if (!isset($config['registration']['credential_id'], $config['registration']['credential_source'])) {
            throw new LocalizedException(__('Passkey is not configured for this user.'));
        }
        return $config['registration'];
    }

    /**
     * Credentials are bound to the RP ID; explain a domain change before the browser prompt fails opaquely.
     *
     * @throws LocalizedException
     */
    private function assertSameDomain(array $registration): void
    {
        $storedRpId = $registration['rp_id'] ?? null;
        $currentRpId = $this->adminTfaConfig->getRpId();
        if ($storedRpId !== null && $storedRpId !== $currentRpId) {
            throw new LocalizedException(__(
                'The admin domain has changed since your passkey was registered '
                . '(was "%1", now "%2"). Please ask an administrator to reset your '
                . 'passkey configuration.',
                $storedRpId,
                $currentRpId
            ));
        }
    }

    private function toDescriptor(array $registration): PublicKeyCredentialDescriptor
    {
        return PublicKeyCredentialDescriptor::create(
            PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
            base64_decode($registration['credential_id'])
        );
    }

    /**
     * Keep our own messages; log and hide library internals behind a generic one.
     */
    private function failure(\Throwable $e, int $userId, Phrase $genericMessage): LocalizedException
    {
        if ($e instanceof LocalizedException) {
            return $e;
        }
        $this->logger->warning('Admin passkey ceremony failed', [
            'admin_user_id' => $userId,
            'provider' => self::CODE,
            'exception' => $e->getMessage(),
        ]);
        return new LocalizedException($genericMessage, $e instanceof \Exception ? $e : null);
    }
}
