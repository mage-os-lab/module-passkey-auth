<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\AdminTfa;

use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use MageOS\PasskeyAuth\Api\AdminTfa\AuthenticateInterface;
use Magento\TwoFactorAuth\Api\EngineInterface;
use Magento\TwoFactorAuth\Api\UserConfigManagerInterface;
use Magento\User\Api\Data\UserInterface;

class Engine implements EngineInterface
{
    public const PROVIDER_CODE_ALL = 'passkey';
    public const PROVIDER_CODE_HARDWARE = 'passkey_hardware';

    public function __construct(
        private readonly UserConfigManagerInterface $userConfigManager,
        private readonly OriginValidator $originValidator,
        private readonly AuthenticateInterface $authenticate,
        private readonly string $authenticatorPolicy = 'all'
    ) {
    }

    public function isEnabled(): bool
    {
        return true;
    }

    /**
     * @throws LocalizedException
     */
    public function verify(UserInterface $user, DataObject $request): bool
    {
        $userId = (int) $user->getId();
        $providerCode = $this->getProviderCode();

        $config = $this->userConfigManager->getProviderConfig($userId, $providerCode);
        if (empty($config) || !isset($config['registration'])) {
            throw new LocalizedException(__(
                'Passkey is not configured for this user.'
            ));
        }

        $this->originValidator->validate($config['registration']);

        return $this->authenticate->verifyAssertion($user, $request);
    }

    public function getAuthenticatorPolicy(): string
    {
        return $this->authenticatorPolicy;
    }

    public function getProviderCode(): string
    {
        return $this->authenticatorPolicy === 'hardware'
            ? self::PROVIDER_CODE_HARDWARE
            : self::PROVIDER_CODE_ALL;
    }
}
