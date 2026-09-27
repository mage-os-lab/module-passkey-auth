<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Model;

use MageOS\PasskeyAuth\Api\CredentialRepositoryInterface;
use MageOS\PasskeyAuth\Api\Data\CredentialInterface;
use MageOS\PasskeyAuth\Model\CredentialManagement;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksCredentialRepositoryTrait;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class CredentialManagementTest extends TestCase
{
    use MocksCredentialRepositoryTrait;

    private EventManager&Stub $eventManagerMock;
    private ?CredentialManagement $credentialManagement = null;

    protected function setUp(): void
    {
        $this->createCredentialRepositoryStub();
        $this->eventManagerMock = $this->createStub(EventManager::class);
    }

    private function credentialManagement(): CredentialManagement
    {
        return $this->credentialManagement ??= new CredentialManagement(
            $this->credentialRepositoryMock,
            $this->eventManagerMock
        );
    }

    public function testListCredentialsDelegatesToRepository(): void
    {
        $customerId = 42;
        $credentialA = $this->createStub(CredentialInterface::class);
        $credentialB = $this->createStub(CredentialInterface::class);
        $expected = [$credentialA, $credentialB];

        $this->configureGetByCustomerId($customerId, $expected);

        $result = $this->credentialManagement()->listCredentials($customerId);

        $this->assertSame($expected, $result);
    }

    public function testDeleteCredentialByOwner(): void
    {
        $customerId = 10;
        $entityId = 55;

        $credential = $this->createStub(CredentialInterface::class);
        $credential->method('getCustomerId')->willReturn($customerId);
        $credential->method('getEntityId')->willReturn($entityId);

        $credentialRepository = $this->mockCredentialRepository();
        $credentialRepository->method('getById')
            ->with($entityId)
            ->willReturn($credential);

        $credentialRepository->expects($this->once())
            ->method('delete')
            ->with($credential)
            ->willReturn(true);

        $eventManager = $this->createMock(EventManager::class);
        $this->eventManagerMock = $eventManager;
        $eventManager->expects($this->once())
            ->method('dispatch')
            ->with('passkey_credential_remove_after', [
                'customer_id' => $customerId,
                'credential_id' => $entityId,
                'credential' => $credential,
            ]);

        $result = $this->credentialManagement()->deleteCredential($customerId, $entityId);

        $this->assertTrue($result);
    }

    public function testDeleteCredentialNotOwner(): void
    {
        $customerId = 10;
        $otherCustomerId = 99;
        $entityId = 55;

        $credential = $this->createStub(CredentialInterface::class);
        $credential->method('getCustomerId')->willReturn($otherCustomerId);

        $this->mockCredentialRepository()->method('getById')
            ->with($entityId)
            ->willReturn($credential);

        $this->expectException(AuthorizationException::class);

        $this->credentialManagement()->deleteCredential($customerId, $entityId);
    }

    public function testRenameCredentialSuccess(): void
    {
        $customerId = 10;
        $entityId = 55;
        $friendlyName = 'My YubiKey';

        $credential = $this->createMock(CredentialInterface::class);
        $credential->method('getCustomerId')->willReturn($customerId);
        $credential->expects($this->once())
            ->method('setFriendlyName')
            ->with($friendlyName);

        $credentialRepository = $this->mockCredentialRepository();
        $credentialRepository->method('getById')
            ->with($entityId)
            ->willReturn($credential);

        $savedCredential = $this->createStub(CredentialInterface::class);
        $credentialRepository->expects($this->once())
            ->method('save')
            ->with($credential)
            ->willReturn($savedCredential);

        $result = $this->credentialManagement()->renameCredential($customerId, $entityId, $friendlyName);

        $this->assertSame($savedCredential, $result);
    }

    public function testRenameCredentialNotOwner(): void
    {
        $customerId = 10;
        $otherCustomerId = 99;
        $entityId = 55;
        $friendlyName = 'My YubiKey';

        $credential = $this->createStub(CredentialInterface::class);
        $credential->method('getCustomerId')->willReturn($otherCustomerId);

        $this->mockCredentialRepository()->method('getById')
            ->with($entityId)
            ->willReturn($credential);

        $this->expectException(AuthorizationException::class);

        $this->credentialManagement()->renameCredential($customerId, $entityId, $friendlyName);
    }

    public function testRenameCredentialRejectsEmptyName(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Passkey name cannot be empty.');

        $this->credentialManagement()->renameCredential(10, 55, '   ');
    }

    public function testValidateFriendlyNameEmpty(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Passkey name cannot be empty.');

        $this->credentialManagement()->validateFriendlyName('   ');
    }

    public function testValidateFriendlyNameTooLong(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Passkey name must be 255 characters or fewer.');

        $this->credentialManagement()->validateFriendlyName(str_repeat('a', 256));
    }

    public function testValidateFriendlyNameWithXssChars(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Passkey names can\'t contain <, > or &.');

        $this->credentialManagement()->validateFriendlyName('My <script>Key');
    }
}
