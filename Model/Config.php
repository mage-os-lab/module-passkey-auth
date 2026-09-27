<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model;

use MageOS\PasskeyAuth\Api\WebAuthnConfigInterface;
use MageOS\PasskeyAuth\Model\WebAuthn\BaseUrlParserTrait;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class Config implements WebAuthnConfigInterface
{
    use BaseUrlParserTrait;

    public const XML_PATH_ENABLED = 'customer/passkey/enabled';
    public const XML_PATH_PROMPT_AFTER_LOGIN = 'customer/passkey/prompt_after_login';
    public const XML_PATH_PROMPT_ON_REGISTRATION = 'customer/passkey/prompt_on_registration';
    public const XML_PATH_NOTIFY_CREDENTIAL_CHANGES = 'customer/passkey/notify_credential_changes';
    public const XML_PATH_NOTIFICATION_EMAIL_IDENTITY = 'customer/passkey/notification_email_identity';
    public const XML_PATH_ADDED_EMAIL_TEMPLATE = 'customer/passkey/added_email_template';
    public const XML_PATH_REMOVED_EMAIL_TEMPLATE = 'customer/passkey/removed_email_template';

    private const MAX_CREDENTIALS = 10;
    private const USER_VERIFICATION = 'preferred';
    private const ATTESTATION_CONVEYANCE = 'none';
    private const RESIDENT_KEY = 'preferred';
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

    public function getResidentKeyRequirement(): string
    {
        return self::RESIDENT_KEY;
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

    public function isCredentialNotificationEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_NOTIFY_CREDENTIAL_CHANGES,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getNotificationIdentity(?int $storeId = null): string
    {
        $identity = $this->scopeConfig->getValue(
            self::XML_PATH_NOTIFICATION_EMAIL_IDENTITY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return (string) ($identity ?: 'general');
    }

    public function getAddedEmailTemplate(?int $storeId = null): string
    {
        $template = $this->scopeConfig->getValue(
            self::XML_PATH_ADDED_EMAIL_TEMPLATE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return (string) ($template ?: 'customer_passkey_added_email_template');
    }

    public function getRemovedEmailTemplate(?int $storeId = null): string
    {
        $template = $this->scopeConfig->getValue(
            self::XML_PATH_REMOVED_EMAIL_TEMPLATE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return (string) ($template ?: 'customer_passkey_removed_email_template');
    }

    public function getRpId(): string
    {
        return $this->parseRpId($this->storeManager->getStore()->getBaseUrl());
    }

    public function getRpName(): string
    {
        return (string) $this->storeManager->getStore()->getName();
    }

    public function getAllowedOrigins(): array
    {
        return [$this->parseOrigin($this->storeManager->getStore()->getBaseUrl())];
    }
}
