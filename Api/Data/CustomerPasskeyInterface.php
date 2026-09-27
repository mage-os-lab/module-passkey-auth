<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Api\Data;

use Magento\Framework\Api\ExtensibleDataInterface;

/**
 * A customer's passkey as returned by the web API: display fields only, no key material or WebAuthn identifiers.
 *
 * @api
 */
interface CustomerPasskeyInterface extends ExtensibleDataInterface
{
    public const ID = 'id';
    public const NAME = 'name';
    public const TRANSPORTS = 'transports';
    public const CREATED_AT = 'created_at';
    public const LAST_USED_AT = 'last_used_at';

    /**
     * Passkey ID (the passkey_credential entity ID), used to rename or delete it.
     *
     * @return int
     */
    public function getId(): int;

    /**
     * @param int $id
     * @return self
     */
    public function setId(int $id): self;

    /**
     * Friendly name the customer gave the passkey.
     *
     * @return string|null
     */
    public function getName(): ?string;

    /**
     * @param string|null $name
     * @return self
     */
    public function setName(?string $name): self;

    /**
     * Authenticator transports reported at registration, e.g. internal, hybrid, usb.
     *
     * @return string[]
     */
    public function getTransports(): array;

    /**
     * @param string[] $transports
     * @return self
     */
    public function setTransports(array $transports): self;

    /**
     * @return string
     */
    public function getCreatedAt(): string;

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
     * @return \MageOS\PasskeyAuth\Api\Data\CustomerPasskeyExtensionInterface|null
     */
    public function getExtensionAttributes(): ?CustomerPasskeyExtensionInterface;

    /**
     * @param \MageOS\PasskeyAuth\Api\Data\CustomerPasskeyExtensionInterface $extensionAttributes
     * @return self
     */
    public function setExtensionAttributes(CustomerPasskeyExtensionInterface $extensionAttributes): self;
}
