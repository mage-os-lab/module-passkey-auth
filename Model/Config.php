<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;

class Config
{
    public const XML_PATH_ENABLED = 'customer/passkey/enabled';
    public const XML_PATH_PROMPT_AFTER_LOGIN = 'customer/passkey/prompt_after_login';
    public const XML_PATH_PROMPT_ON_REGISTRATION = 'customer/passkey/prompt_on_registration';

    private const MAX_CREDENTIALS = 10;
    private const USER_VERIFICATION = 'preferred';
    private const ATTESTATION_CONVEYANCE = 'none';
    private const CEREMONY_TIMEOUT = 60000;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
    }

    public function getMaxCredentials(): int
    {
        return self::MAX_CREDENTIALS;
    }

    public function getUserVerification(): string
    {
        return self::USER_VERIFICATION;
    }

    public function getAuthenticatorAttachment(): ?string
    {
        return null;
    }

    public function getAttestationConveyance(): string
    {
        return self::ATTESTATION_CONVEYANCE;
    }

    public function getCeremonyTimeout(): int
    {
        return self::CEREMONY_TIMEOUT;
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
        $host = $parsed['host'] ?? null;
        if ($host === null) {
            throw new \RuntimeException(
                'Cannot determine RP ID: store base URL has no host component.'
            );
        }
        return $host;
    }

    public function getRpName(): string
    {
        return (string) $this->storeManager->getStore()->getName();
    }

    public function getAllowedOrigins(): array
    {
        $baseUrl = $this->storeManager->getStore()->getBaseUrl();
        $parsed = parse_url($baseUrl);
        $scheme = $parsed['scheme'] ?? null;
        $host = $parsed['host'] ?? null;
        if ($scheme === null || $host === null) {
            throw new \RuntimeException(
                'Cannot determine allowed origins: store base URL is missing scheme or host.'
            );
        }
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        return [$scheme . '://' . $host . $port];
    }
}
