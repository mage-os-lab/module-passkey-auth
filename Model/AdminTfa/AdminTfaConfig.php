<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\AdminTfa;

use MageOS\PasskeyAuth\Api\WebAuthnConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

class AdminTfaConfig implements WebAuthnConfigInterface
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function getRpId(): string
    {
        $parsed = parse_url($this->getAdminBaseUrl());
        if (!isset($parsed['host'])) {
            throw new LocalizedException(__('Could not determine admin domain from base URL.'));
        }
        return $parsed['host'];
    }

    public function getRpName(): string
    {
        return $this->storeManager->getStore(Store::ADMIN_CODE)->getName();
    }

    public function getAllowedOrigins(): array
    {
        $parsed = parse_url($this->getAdminBaseUrl());
        $origin = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');
        if (isset($parsed['port'])) {
            $origin .= ':' . $parsed['port'];
        }
        return [$origin];
    }

    public function getUserVerification(): string
    {
        return 'required';
    }

    public function getAuthenticatorAttachment(string $policy): ?string
    {
        return $policy === 'hardware' ? 'cross-platform' : null;
    }

    public function getAttestation(string $policy): string
    {
        return $policy === 'hardware' ? 'direct' : 'none';
    }

    private function getAdminBaseUrl(): string
    {
        return $this->storeManager->getStore(Store::ADMIN_CODE)->getBaseUrl();
    }
}
