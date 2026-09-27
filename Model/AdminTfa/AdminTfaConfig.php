<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\AdminTfa;

use MageOS\PasskeyAuth\Api\WebAuthnConfigInterface;
use MageOS\PasskeyAuth\Model\WebAuthn\BaseUrlParserTrait;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\PublicKeyCredentialCreationOptions;

/**
 * WebAuthn relying-party settings for admin 2FA: scoped to the admin URL, user verification required.
 */
class AdminTfaConfig implements WebAuthnConfigInterface
{
    use BaseUrlParserTrait;

    private const CEREMONY_TIMEOUT = 60000;

    public function __construct(
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function getRpId(): string
    {
        return $this->parseRpId($this->getAdminBaseUrl());
    }

    public function getRpName(): string
    {
        return (string) $this->storeManager->getStore(Store::ADMIN_CODE)->getName();
    }

    public function getAllowedOrigins(): array
    {
        return [$this->parseOrigin($this->getAdminBaseUrl())];
    }

    public function getUserVerification(): string
    {
        return AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED;
    }

    public function getAuthenticatorAttachment(): ?string
    {
        return null;
    }

    public function getAttestationConveyance(): string
    {
        return PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE;
    }

    public function getResidentKeyRequirement(): string
    {
        return AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_DISCOURAGED;
    }

    public function getCeremonyTimeout(): int
    {
        return self::CEREMONY_TIMEOUT;
    }

    private function getAdminBaseUrl(): string
    {
        return $this->storeManager->getStore(Store::ADMIN_CODE)->getBaseUrl();
    }
}
