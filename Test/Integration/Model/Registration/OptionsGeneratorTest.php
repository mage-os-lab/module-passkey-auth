<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Integration\Model\Registration;

use MageOS\PasskeyAuth\Api\RegistrationOptionsInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * @magentoDbIsolation enabled
 */
class OptionsGeneratorTest extends TestCase
{
    private RegistrationOptionsInterface $optionsGenerator;
    private Json $json;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->optionsGenerator = $objectManager->get(RegistrationOptionsInterface::class);
        $this->json = $objectManager->get(Json::class);
    }

    /**
     * @magentoDataFixture Magento/Customer/_files/customer.php
     * @magentoConfigFixture current_store customer/passkey/enabled 1
     */
    public function testGenerateProducesCreationOptionsWithChallengeToken(): void
    {
        $options = $this->json->unserialize($this->optionsGenerator->generate(1));

        $this->assertIsArray($options);
        $this->assertArrayHasKey('challenge', $options);
        $this->assertArrayHasKey('challengeToken', $options);
        $this->assertSame(64, strlen($options['challengeToken']));
        $this->assertArrayHasKey('rp', $options);
        $this->assertArrayHasKey('user', $options);
        $this->assertSame('customer@example.com', $options['user']['name']);
        $this->assertNotEmpty($options['pubKeyCredParams']);
    }

    /**
     * @magentoDataFixture MageOS_PasskeyAuth::Test/Integration/_files/customer_with_passkey.php
     * @magentoConfigFixture current_store customer/passkey/enabled 1
     */
    public function testGenerateExcludesExistingCredentials(): void
    {
        $options = $this->json->unserialize($this->optionsGenerator->generate(1));

        $this->assertArrayHasKey('excludeCredentials', $options);
        $this->assertCount(1, $options['excludeCredentials']);
        $this->assertSame('public-key', $options['excludeCredentials'][0]['type']);
    }

    /**
     * @magentoDataFixture Magento/Customer/_files/customer.php
     * @magentoConfigFixture current_store customer/passkey/enabled 0
     */
    public function testGenerateThrowsWhenDisabled(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Passkey authentication is not enabled.');
        $this->optionsGenerator->generate(1);
    }
}
