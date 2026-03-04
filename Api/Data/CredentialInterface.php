<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Api\Data;

/**
 * @api
 */
interface CredentialInterface
{
    public const ENTITY_ID = 'entity_id';
    public const CUSTOMER_ID = 'customer_id';
    public const CREDENTIAL_ID = 'credential_id';
    public const PUBLIC_KEY = 'public_key';
    public const USER_HANDLE = 'user_handle';
    public const SIGN_COUNT = 'sign_count';
    public const TRANSPORTS = 'transports';
    public const FRIENDLY_NAME = 'friendly_name';
    public const AAGUID = 'aaguid';
    public const CREATED_AT = 'created_at';
    public const LAST_USED_AT = 'last_used_at';

    /**
     * @return int|null
     */
    public function getEntityId(): ?int;

    /**
     * @param int $entityId
     * @return self
     */
    public function setEntityId(int $entityId): self;

    /**
     * @return int
     */
    public function getCustomerId(): int;

    /**
     * @param int $customerId
     * @return self
     */
    public function setCustomerId(int $customerId): self;

    /**
     * @return string
     */
    public function getCredentialId(): string;

    /**
     * @param string $credentialId
     * @return self
     */
    public function setCredentialId(string $credentialId): self;

    /**
     * @return string
     */
    public function getPublicKey(): string;

    /**
     * @param string $publicKey
     * @return self
     */
    public function setPublicKey(string $publicKey): self;

    /**
     * @return string
     */
    public function getUserHandle(): string;

    /**
     * @param string $userHandle
     * @return self
     */
    public function setUserHandle(string $userHandle): self;

    /**
     * @return int
     */
    public function getSignCount(): int;

    /**
     * @param int $signCount
     * @return self
     */
    public function setSignCount(int $signCount): self;

    /**
     * @return string|null
     */
    public function getTransports(): ?string;

    /**
     * @param string|null $transports
     * @return self
     */
    public function setTransports(?string $transports): self;

    /**
     * @return string|null
     */
    public function getFriendlyName(): ?string;

    /**
     * @param string|null $friendlyName
     * @return self
     */
    public function setFriendlyName(?string $friendlyName): self;

    /**
     * @return string|null
     */
    public function getAaguid(): ?string;

    /**
     * @param string|null $aaguid
     * @return self
     */
    public function setAaguid(?string $aaguid): self;

    /**
     * @return string|null
     */
    public function getCreatedAt(): ?string;

    /**
     * @param string $createdAt
     * @return self
     */
    public function setCreatedAt(string $createdAt): self;

    /**
     * @return string|null
     */
    public function getLastUsedAt(): ?string;

    /**
     * @param string|null $lastUsedAt
     * @return self
     */
    public function setLastUsedAt(?string $lastUsedAt): self;

    /**
     * @return string[]
     */
    public function getTransportsArray(): array;
}
