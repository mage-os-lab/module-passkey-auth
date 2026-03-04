<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Api\Data;

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

    public function getEntityId(): ?int;

    public function setEntityId(int $entityId): self;

    public function getCustomerId(): int;

    public function setCustomerId(int $customerId): self;

    public function getCredentialId(): string;

    public function setCredentialId(string $credentialId): self;

    public function getPublicKey(): string;

    public function setPublicKey(string $publicKey): self;

    public function getUserHandle(): string;

    public function setUserHandle(string $userHandle): self;

    public function getSignCount(): int;

    public function setSignCount(int $signCount): self;

    public function getTransports(): ?string;

    public function setTransports(?string $transports): self;

    public function getFriendlyName(): ?string;

    public function setFriendlyName(?string $friendlyName): self;

    public function getAaguid(): ?string;

    public function setAaguid(?string $aaguid): self;

    public function getCreatedAt(): ?string;

    public function setCreatedAt(string $createdAt): self;

    public function getLastUsedAt(): ?string;

    public function setLastUsedAt(?string $lastUsedAt): self;
}
