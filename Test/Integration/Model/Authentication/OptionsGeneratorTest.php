<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Integration\Model\Authentication;

use MageOS\PasskeyAuth\Api\AuthenticationOptionsInterface;
use MageOS\PasskeyAuth\Model\ResourceModel\Challenge\CollectionFactory as ChallengeCollectionFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * @magentoDbIsolation enabled
 */
class OptionsGeneratorTest extends TestCase
{
    private AuthenticationOptionsInterface $optionsGenerator;
    private Json $json;
    private ChallengeCollectionFactory $challengeCollectionFactory;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->optionsGenerator = $objectManager->get(AuthenticationOptionsInterface::class);
        $this->json = $objectManager->get(Json::class);
        $this->challengeCollectionFactory = $objectManager->get(ChallengeCollectionFactory::class);
    }

    /**
     * @magentoConfigFixture current_store customer/passkey/enabled 1
     */
    public function testAnonymousOptionsUseDiscoverableCredentials(): void
    {
        $options = $this->json->unserialize($this->optionsGenerator->generate(null));

        $this->assertIsArray($options);
        $this->assertArrayHasKey('challenge', $options);
        $this->assertArrayHasKey('challengeToken', $options);
        $this->assertTrue(
            empty($options['allowCredentials']),
            'Anonymous requests must not enumerate credentials.'
        );
    }

    /**
     * @magentoDataFixture MageOS_PasskeyAuth::Test/Integration/_files/customer_with_passkey.php
     * @magentoConfigFixture current_store customer/passkey/enabled 1
     */
    public function testKnownEmailProducesAllowCredentials(): void
    {
        $options = $this->json->unserialize($this->optionsGenerator->generate('customer@example.com'));

        $this->assertArrayHasKey('allowCredentials', $options);
        $this->assertCount(1, $options['allowCredentials']);
        $this->assertSame('public-key', $options['allowCredentials'][0]['type']);
    }

    /**
     * @magentoConfigFixture current_store customer/passkey/enabled 1
     */
    public function testUnknownEmailIsIndistinguishableFromNoPasskeys(): void
    {
        $options = $this->json->unserialize(
            $this->optionsGenerator->generate('no-such-customer-' . uniqid() . '@example.com')
        );

        // Anti-enumeration: response shape matches a customer without passkeys.
        $this->assertArrayHasKey('challenge', $options);
        $this->assertArrayHasKey('challengeToken', $options);
        $this->assertTrue(empty($options['allowCredentials']));
    }

    /**
     * @magentoConfigFixture current_store customer/passkey/enabled 1
     */
    public function testChallengeIsPersistedWithAuthenticationType(): void
    {
        $options = $this->json->unserialize($this->optionsGenerator->generate(null));

        $collection = $this->challengeCollectionFactory->create();
        $collection->addFieldToFilter('token', $options['challengeToken']);

        $this->assertSame(1, $collection->getSize());
        $this->assertSame('authentication', $collection->getFirstItem()->getData('type'));
    }
}
