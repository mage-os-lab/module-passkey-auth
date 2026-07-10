<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Integration\Model\ResourceModel\Credential\Grid;

use MageOS\PasskeyAuth\Model\ResourceModel\Credential\Grid\Collection;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * @magentoDbIsolation enabled
 */
class CollectionTest extends TestCase
{
    /**
     * @magentoDataFixture MageOS_PasskeyAuth::Test/Integration/_files/customer_with_passkey.php
     */
    public function testGridRowsIncludeCustomerIdentity(): void
    {
        /** @var Collection $collection */
        $collection = Bootstrap::getObjectManager()->create(Collection::class);
        $collection->addFieldToFilter('customer_email', 'customer@example.com');

        $this->assertSame(1, $collection->getSize());

        $item = $collection->getFirstItem();
        $this->assertSame('customer@example.com', $item->getData('customer_email'));
        $this->assertSame('Integration Test Passkey', $item->getData('friendly_name'));
        $this->assertNotEmpty($item->getData('customer_firstname'));
    }
}
