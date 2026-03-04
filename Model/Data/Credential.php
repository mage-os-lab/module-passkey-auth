<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\Data;

use MageOS\PasskeyAuth\Api\Data\CredentialInterface;
use Magento\Framework\DataObject;

class Credential extends DataObject implements CredentialInterface
{
    public function getEntityId(): ?int
    {
        $id = $this->getData(self::ENTITY_ID);
        return $id !== null ? (int) $id : null;
    }

    public function setEntityId(int $entityId): CredentialInterface
    {
        return $this->setData(self::ENTITY_ID, $entityId);
    }

    public function getCustomerId(): int
    {
        return (int) $this->getData(self::CUSTOMER_ID);
    }

    public function setCustomerId(int $customerId): CredentialInterface
    {
        return $this->setData(self::CUSTOMER_ID, $customerId);
    }

    public function getCredentialId(): string
    {
        return (string) $this->getData(self::CREDENTIAL_ID);
    }

    public function setCredentialId(string $credentialId): CredentialInterface
    {
        return $this->setData(self::CREDENTIAL_ID, $credentialId);
    }

    public function getPublicKey(): string
    {
        return (string) $this->getData(self::PUBLIC_KEY);
    }

    public function setPublicKey(string $publicKey): CredentialInterface
    {
        return $this->setData(self::PUBLIC_KEY, $publicKey);
    }

    public function getUserHandle(): string
    {
        return (string) $this->getData(self::USER_HANDLE);
    }

    public function setUserHandle(string $userHandle): CredentialInterface
    {
        return $this->setData(self::USER_HANDLE, $userHandle);
    }

    public function getSignCount(): int
    {
        return (int) $this->getData(self::SIGN_COUNT);
    }

    public function setSignCount(int $signCount): CredentialInterface
    {
        return $this->setData(self::SIGN_COUNT, $signCount);
    }

    public function getTransports(): ?string
    {
        return $this->getData(self::TRANSPORTS);
    }

    public function setTransports(?string $transports): CredentialInterface
    {
        return $this->setData(self::TRANSPORTS, $transports);
    }

    public function getFriendlyName(): ?string
    {
        return $this->getData(self::FRIENDLY_NAME);
    }

    public function setFriendlyName(?string $friendlyName): CredentialInterface
    {
        return $this->setData(self::FRIENDLY_NAME, $friendlyName);
    }

    public function getAaguid(): ?string
    {
        return $this->getData(self::AAGUID);
    }

    public function setAaguid(?string $aaguid): CredentialInterface
    {
        return $this->setData(self::AAGUID, $aaguid);
    }

    public function getCreatedAt(): ?string
    {
        return $this->getData(self::CREATED_AT);
    }

    public function setCreatedAt(string $createdAt): CredentialInterface
    {
        return $this->setData(self::CREATED_AT, $createdAt);
    }

    public function getLastUsedAt(): ?string
    {
        return $this->getData(self::LAST_USED_AT);
    }

    public function setLastUsedAt(?string $lastUsedAt): CredentialInterface
    {
        return $this->setData(self::LAST_USED_AT, $lastUsedAt);
    }

    public function getTransportsArray(): array
    {
        $transports = $this->getTransports();
        return $transports ? explode(',', $transports) : [];
    }
}
