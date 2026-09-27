<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Integration\Model;

use MageOS\PasskeyAuth\Api\CustomerPasskeyManagementInterface;
use MageOS\PasskeyAuth\Api\Data\CustomerPasskeyInterface;
use Magento\Framework\Webapi\ServiceOutputProcessor;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * @magentoDbIsolation enabled
 */
class CustomerPasskeyManagementTest extends TestCase
{
    private CustomerPasskeyManagementInterface $management;

    protected function setUp(): void
    {
        $this->management = Bootstrap::getObjectManager()->get(CustomerPasskeyManagementInterface::class);
    }

    /**
     * @magentoDataFixture MageOS_PasskeyAuth::Test/Integration/_files/customer_with_passkey.php
     */
    public function testGetPasskeysReturnsDisplayFields(): void
    {
        $passkeys = $this->management->getPasskeys(1);

        $this->assertCount(1, $passkeys);
        $passkey = reset($passkeys);
        $this->assertInstanceOf(CustomerPasskeyInterface::class, $passkey);
        $this->assertGreaterThan(0, $passkey->getId());
        $this->assertSame('Integration Test Passkey', $passkey->getName());
        $this->assertSame(['internal', 'hybrid'], $passkey->getTransports());
        $this->assertNotSame('', $passkey->getCreatedAt());
        $this->assertNull($passkey->getLastUsedAt());
    }

    /**
     * @magentoDataFixture MageOS_PasskeyAuth::Test/Integration/_files/customer_with_passkey.php
     */
    public function testRestOutputLeavesOutKeyMaterial(): void
    {
        /** @var ServiceOutputProcessor $outputProcessor */
        $outputProcessor = Bootstrap::getObjectManager()->get(ServiceOutputProcessor::class);

        $output = $outputProcessor->process(
            $this->management->getPasskeys(1),
            CustomerPasskeyManagementInterface::class,
            'getPasskeys'
        );

        $this->assertCount(1, $output);
        $this->assertSame(['id', 'name', 'transports', 'created_at'], array_keys($output[0]));
    }

    /**
     * @magentoDataFixture MageOS_PasskeyAuth::Test/Integration/_files/customer_with_passkey.php
     */
    public function testRenamePasskeyReturnsRenamedPasskey(): void
    {
        $passkeys = $this->management->getPasskeys(1);
        $passkeyId = reset($passkeys)->getId();

        $renamed = $this->management->renamePasskey(1, $passkeyId, 'My Work Laptop');

        $this->assertSame($passkeyId, $renamed->getId());
        $this->assertSame('My Work Laptop', $renamed->getName());
    }
}
