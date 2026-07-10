<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Integration\Model;

use MageOS\PasskeyAuth\Api\CredentialRepositoryInterface;
use MageOS\PasskeyAuth\Api\Data\CredentialInterface;
use MageOS\PasskeyAuth\Api\Data\CredentialInterfaceFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * @magentoDbIsolation enabled
 */
class CredentialRepositoryTest extends TestCase
{
    private CredentialRepositoryInterface $repository;
    private CredentialInterfaceFactory $factory;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->repository = $objectManager->get(CredentialRepositoryInterface::class);
        $this->factory = $objectManager->get(CredentialInterfaceFactory::class);
    }

    /**
     * @magentoDataFixture MageOS_PasskeyAuth::Test/Integration/_files/customer_with_passkey.php
     */
    public function testGetByCustomerIdReturnsPersistedCredential(): void
    {
        $credentials = $this->repository->getByCustomerId(1);

        $this->assertCount(1, $credentials);
        $credential = reset($credentials);
        $this->assertInstanceOf(CredentialInterface::class, $credential);
        $this->assertSame('Integration Test Passkey', $credential->getFriendlyName());
        $this->assertSame(base64_encode('integration-test-credential-id'), $credential->getCredentialId());
        $this->assertSame(5, $credential->getSignCount());
        $this->assertSame(['internal', 'hybrid'], $credential->getTransportsArray());
    }

    /**
     * @magentoDataFixture MageOS_PasskeyAuth::Test/Integration/_files/customer_with_passkey.php
     */
    public function testGetByCredentialId(): void
    {
        $credential = $this->repository->getByCredentialId(base64_encode('integration-test-credential-id'));

        $this->assertSame(1, $credential->getCustomerId());
    }

    /**
     * @magentoDataFixture MageOS_PasskeyAuth::Test/Integration/_files/customer_with_passkey.php
     */
    public function testCountByCustomerId(): void
    {
        $this->assertSame(1, $this->repository->countByCustomerId(1));
        $this->assertSame(0, $this->repository->countByCustomerId(999999));
    }

    /**
     * @magentoDataFixture Magento/Customer/_files/customer.php
     */
    public function testSaveAndDeleteRoundTrip(): void
    {
        $credential = $this->factory->create();
        $credential->setCustomerId(1);
        $credential->setCredentialId(base64_encode('round-trip-credential'));
        $credential->setPublicKey('{"fixture":"round-trip"}');
        $credential->setUserHandle(base64_encode('round-trip-handle'));
        $credential->setSignCount(0);

        $saved = $this->repository->save($credential);
        $this->assertNotNull($saved->getEntityId());

        $loaded = $this->repository->getById((int) $saved->getEntityId());
        $this->assertSame(base64_encode('round-trip-credential'), $loaded->getCredentialId());

        $this->assertTrue($this->repository->deleteById((int) $saved->getEntityId()));

        $this->expectException(NoSuchEntityException::class);
        $this->repository->getById((int) $saved->getEntityId());
    }

    public function testGetByIdThrowsForUnknownCredential(): void
    {
        $this->expectException(NoSuchEntityException::class);
        $this->repository->getById(999999);
    }

    public function testGetByCredentialIdThrowsForUnknownCredential(): void
    {
        $this->expectException(NoSuchEntityException::class);
        $this->repository->getByCredentialId(base64_encode('does-not-exist'));
    }
}
