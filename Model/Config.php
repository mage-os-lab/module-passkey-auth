<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;

class Config
{
    public const XML_PATH_ENABLED = 'passkey_auth/general/enabled';
    public const XML_PATH_UI_MODE = 'passkey_auth/general/ui_mode';
    public const XML_PATH_MAX_CREDENTIALS = 'passkey_auth/general/max_credentials';
    public const XML_PATH_USER_VERIFICATION = 'passkey_auth/webauthn/user_verification';
    public const XML_PATH_AUTHENTICATOR_ATTACHMENT = 'passkey_auth/webauthn/authenticator_attachment';
    public const XML_PATH_ATTESTATION_CONVEYANCE = 'passkey_auth/webauthn/attestation_conveyance';
    public const XML_PATH_CEREMONY_TIMEOUT = 'passkey_auth/webauthn/ceremony_timeout';
    public const XML_PATH_PROMPT_AFTER_LOGIN = 'passkey_auth/enrollment/prompt_after_login';
    public const XML_PATH_PROMPT_ON_REGISTRATION = 'passkey_auth/enrollment/prompt_on_registration';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
    }

    public function getUiMode(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_UI_MODE);
    }

    public function getMaxCredentials(): int
    {
        return (int) $this->scopeConfig->getValue(self::XML_PATH_MAX_CREDENTIALS);
    }

    public function getUserVerification(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_USER_VERIFICATION);
    }

    public function getAuthenticatorAttachment(): ?string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_AUTHENTICATOR_ATTACHMENT);
        return $value ?: null;
    }

    public function getAttestationConveyance(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_ATTESTATION_CONVEYANCE);
    }

    public function getCeremonyTimeout(): int
    {
        return (int) $this->scopeConfig->getValue(self::XML_PATH_CEREMONY_TIMEOUT);
    }

    public function isPromptAfterLoginEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_PROMPT_AFTER_LOGIN);
    }

    public function isPromptOnRegistrationEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_PROMPT_ON_REGISTRATION);
    }

    public function getRpId(): string
    {
        $baseUrl = $this->storeManager->getStore()->getBaseUrl();
        $parsed = parse_url($baseUrl);
        return $parsed['host'] ?? 'localhost';
    }

    public function getRpName(): string
    {
        return (string) $this->storeManager->getStore()->getName();
    }

    public function getAllowedOrigins(): array
    {
        $baseUrl = $this->storeManager->getStore()->getBaseUrl();
        $parsed = parse_url($baseUrl);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? 'localhost';
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        return [$scheme . '://' . $host . $port];
    }
}
