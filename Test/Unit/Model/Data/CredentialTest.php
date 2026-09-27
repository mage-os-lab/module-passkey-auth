<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Model\Data;

use MageOS\PasskeyAuth\Api\Data\CredentialExtensionInterface;
use MageOS\PasskeyAuth\Model\Data\Credential;
use Magento\Framework\Api\ExtensibleDataInterface;
use PHPUnit\Framework\TestCase;

class CredentialTest extends TestCase
{
    public function testExtensionAttributesDefaultToNull(): void
    {
        $this->assertNull((new Credential())->getExtensionAttributes());
    }

    public function testStoresExtensionAttributes(): void
    {
        $credential = new Credential();
        $extensionAttributes = $this->createStub(CredentialExtensionInterface::class);

        $this->assertSame($credential, $credential->setExtensionAttributes($extensionAttributes));
        $this->assertSame($extensionAttributes, $credential->getExtensionAttributes());
        $this->assertSame(
            $extensionAttributes,
            $credential->getData(ExtensibleDataInterface::EXTENSION_ATTRIBUTES_KEY)
        );
    }
}
