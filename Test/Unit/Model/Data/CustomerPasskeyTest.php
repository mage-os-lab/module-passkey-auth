<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Model\Data;

use MageOS\PasskeyAuth\Api\Data\CustomerPasskeyExtensionInterface;
use MageOS\PasskeyAuth\Model\Data\CustomerPasskey;
use PHPUnit\Framework\TestCase;

class CustomerPasskeyTest extends TestCase
{
    public function testDefaultsForEmptyPasskey(): void
    {
        $passkey = new CustomerPasskey();

        $this->assertSame(0, $passkey->getId());
        $this->assertNull($passkey->getName());
        $this->assertSame([], $passkey->getTransports());
        $this->assertSame('', $passkey->getCreatedAt());
        $this->assertNull($passkey->getLastUsedAt());
        $this->assertNull($passkey->getExtensionAttributes());
    }

    public function testStoresExtensionAttributes(): void
    {
        $passkey = new CustomerPasskey();
        $extensionAttributes = $this->createStub(CustomerPasskeyExtensionInterface::class);

        $this->assertSame($passkey, $passkey->setExtensionAttributes($extensionAttributes));
        $this->assertSame($extensionAttributes, $passkey->getExtensionAttributes());
    }
}
