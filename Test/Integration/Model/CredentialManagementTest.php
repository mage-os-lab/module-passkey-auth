<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Integration\Model;

use MageOS\PasskeyAuth\Api\CredentialManagementInterface;
use MageOS\PasskeyAuth\Api\CredentialRepositoryInterface;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\LocalizedException;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * @magentoDbIsolation enabled
 */
class CredentialManagementTest extends TestCase
{
    private CredentialManagementInterface $management;
    private CredentialRepositoryInterface $repository;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->management = $objectManager->get(CredentialManagementInterface::class);
        $this->repository = $objectManager->get(CredentialRepositoryInterface::class);
    }

    private function getFixtureCredentialId(): int
    {
        $credentials = $this->repository->getByCustomerId(1);
        $credential = reset($credentials);

        return (int) $credential->getEntityId();
    }

    /**
     * @magentoDataFixture MageOS_PasskeyAuth::Test/Integration/_files/customer_with_passkey.php
     */
    public function testListCredentials(): void
    {
        $credentials = $this->management->listCredentials(1);

        $this->assertCount(1, $credentials);
    }

    /**
     * @magentoDataFixture MageOS_PasskeyAuth::Test/Integration/_files/customer_with_passkey.php
     */
    public function testRenamePersistsNewName(): void
    {
        $entityId = $this->getFixtureCredentialId();

        $renamed = $this->management->renameCredential(1, $entityId, 'My Work Laptop');

        $this->assertSame('My Work Laptop', $renamed->getFriendlyName());
        $this->assertSame('My Work Laptop', $this->repository->getById($entityId)->getFriendlyName());
    }

    /**
     * @magentoDataFixture MageOS_PasskeyAuth::Test/Integration/_files/customer_with_passkey.php
     */
    public function testRenameRejectsEmptyName(): void
    {
        $this->expectException(LocalizedException::class);
        $this->management->renameCredential(1, $this->getFixtureCredentialId(), '   ');
    }

    /**
     * @magentoDataFixture MageOS_PasskeyAuth::Test/Integration/_files/customer_with_passkey.php
     */
    public function testRenameRejectsMarkupCharacters(): void
    {
        $this->expectException(LocalizedException::class);
        $this->management->renameCredential(1, $this->getFixtureCredentialId(), '<script>bad</script>');
    }

    /**
     * @magentoDataFixture MageOS_PasskeyAuth::Test/Integration/_files/customer_with_passkey.php
     */
    public function testDeleteRejectsForeignCustomer(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->management->deleteCredential(999999, $this->getFixtureCredentialId());
    }

    /**
     * @magentoDataFixture MageOS_PasskeyAuth::Test/Integration/_files/customer_with_passkey.php
     */
    public function testDeleteRemovesOwnCredential(): void
    {
        $entityId = $this->getFixtureCredentialId();

        $this->assertTrue($this->management->deleteCredential(1, $entityId));
        $this->assertSame(0, $this->repository->countByCustomerId(1));
    }
}
