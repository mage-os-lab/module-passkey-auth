<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\Data;

use MageOS\PasskeyAuth\Api\Data\CustomerPasskeyExtensionInterface;
use MageOS\PasskeyAuth\Api\Data\CustomerPasskeyInterface;
use Magento\Framework\DataObject;

class CustomerPasskey extends DataObject implements CustomerPasskeyInterface
{
    public function getId(): int
    {
        return (int) $this->getData(self::ID);
    }

    public function setId(int $id): CustomerPasskeyInterface
    {
        return $this->setData(self::ID, $id);
    }

    public function getName(): ?string
    {
        return $this->getData(self::NAME);
    }

    public function setName(?string $name): CustomerPasskeyInterface
    {
        return $this->setData(self::NAME, $name);
    }

    public function getTransports(): array
    {
        return $this->getData(self::TRANSPORTS) ?? [];
    }

    public function setTransports(array $transports): CustomerPasskeyInterface
    {
        return $this->setData(self::TRANSPORTS, $transports);
    }

    public function getCreatedAt(): string
    {
        return (string) $this->getData(self::CREATED_AT);
    }

    public function setCreatedAt(string $createdAt): CustomerPasskeyInterface
    {
        return $this->setData(self::CREATED_AT, $createdAt);
    }

    public function getLastUsedAt(): ?string
    {
        return $this->getData(self::LAST_USED_AT);
    }

    public function setLastUsedAt(?string $lastUsedAt): CustomerPasskeyInterface
    {
        return $this->setData(self::LAST_USED_AT, $lastUsedAt);
    }

    public function getExtensionAttributes(): ?CustomerPasskeyExtensionInterface
    {
        return $this->getData(self::EXTENSION_ATTRIBUTES_KEY);
    }

    public function setExtensionAttributes(
        CustomerPasskeyExtensionInterface $extensionAttributes
    ): CustomerPasskeyInterface {
        return $this->setData(self::EXTENSION_ATTRIBUTES_KEY, $extensionAttributes);
    }
}
