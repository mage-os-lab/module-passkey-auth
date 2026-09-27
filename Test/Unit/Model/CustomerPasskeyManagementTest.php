<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Model;

use MageOS\PasskeyAuth\Api\CredentialManagementInterface;
use MageOS\PasskeyAuth\Api\Data\CredentialInterface;
use MageOS\PasskeyAuth\Api\Data\CustomerPasskeyInterfaceFactory;
use MageOS\PasskeyAuth\Api\RegistrationVerifierInterface;
use MageOS\PasskeyAuth\Model\CustomerPasskeyManagement;
use MageOS\PasskeyAuth\Model\CustomerPasskeyMapper;
use MageOS\PasskeyAuth\Model\Data\CustomerPasskey;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class CustomerPasskeyManagementTest extends TestCase
{
    private CredentialManagementInterface&Stub $credentialManagementMock;
    private RegistrationVerifierInterface&Stub $registrationVerifierMock;
    private ?CustomerPasskeyManagement $management = null;

    protected function setUp(): void
    {
        $this->credentialManagementMock = $this->createStub(CredentialManagementInterface::class);
        $this->registrationVerifierMock = $this->createStub(RegistrationVerifierInterface::class);
    }

    private function management(): CustomerPasskeyManagement
    {
        return $this->management ??= new CustomerPasskeyManagement(
            $this->credentialManagementMock,
            $this->registrationVerifierMock,
            new CustomerPasskeyMapper($this->createPasskeyFactory())
        );
    }

    private function createPasskeyFactory(): CustomerPasskeyInterfaceFactory&Stub
    {
        $factory = $this->createStub(CustomerPasskeyInterfaceFactory::class);
        $factory->method('create')->willReturnCallback(static fn () => new CustomerPasskey());

        return $factory;
    }

    public function testGetPasskeysMapsEachCredential(): void
    {
        $credentialManagement = $this->createMock(CredentialManagementInterface::class);
        $this->credentialManagementMock = $credentialManagement;
        $credentialManagement->expects($this->once())
            ->method('listCredentials')
            ->with(42)
            ->willReturn([$this->createCredential(2, 'Laptop'), $this->createCredential(1, 'Phone')]);

        $passkeys = $this->management()->getPasskeys(42);

        $this->assertCount(2, $passkeys);
        $this->assertSame(2, $passkeys[0]->getId());
        $this->assertSame('Laptop', $passkeys[0]->getName());
        $this->assertSame(1, $passkeys[1]->getId());
        $this->assertSame('Phone', $passkeys[1]->getName());
    }

    public function testGetPasskeysReturnsEmptyList(): void
    {
        $this->credentialManagementMock->method('listCredentials')->willReturn([]);

        $this->assertSame([], $this->management()->getPasskeys(42));
    }

    public function testRenamePasskeyReturnsRenamedPasskey(): void
    {
        $credentialManagement = $this->createMock(CredentialManagementInterface::class);
        $this->credentialManagementMock = $credentialManagement;
        $credentialManagement->expects($this->once())
            ->method('renameCredential')
            ->with(42, 7, 'Work laptop')
            ->willReturn($this->createCredential(7, 'Work laptop'));

        $passkey = $this->management()->renamePasskey(42, 7, 'Work laptop');

        $this->assertSame(7, $passkey->getId());
        $this->assertSame('Work laptop', $passkey->getName());
    }

    public function testRenamePasskeyPassesOwnershipErrorsThrough(): void
    {
        $this->credentialManagementMock->method('renameCredential')
            ->willThrowException(new AuthorizationException(__('You are not authorized to manage this passkey.')));

        $this->expectException(AuthorizationException::class);

        $this->management()->renamePasskey(42, 7, 'Work laptop');
    }

    public function testVerifyRegistrationReturnsNewPasskey(): void
    {
        $registrationVerifier = $this->createMock(RegistrationVerifierInterface::class);
        $this->registrationVerifierMock = $registrationVerifier;
        $registrationVerifier->expects($this->once())
            ->method('verify')
            ->with(42, 'challenge-token', '{"id":"x"}', 'YubiKey')
            ->willReturn($this->createCredential(9, 'YubiKey', ['usb', 'nfc']));

        $passkey = $this->management()->verifyRegistration(42, 'challenge-token', '{"id":"x"}', 'YubiKey');

        $this->assertSame(9, $passkey->getId());
        $this->assertSame('YubiKey', $passkey->getName());
        $this->assertSame(['usb', 'nfc'], $passkey->getTransports());
        $this->assertSame('2026-03-01 10:00:00', $passkey->getCreatedAt());
    }

    public function testVerifyRegistrationPassesNullName(): void
    {
        $registrationVerifier = $this->createMock(RegistrationVerifierInterface::class);
        $this->registrationVerifierMock = $registrationVerifier;
        $registrationVerifier->expects($this->once())
            ->method('verify')
            ->with(42, 'challenge-token', '{"id":"x"}', null)
            ->willReturn($this->createCredential(9, null));

        $this->assertNull($this->management()->verifyRegistration(42, 'challenge-token', '{"id":"x"}')->getName());
    }

    public function testVerifyRegistrationPassesFailuresThrough(): void
    {
        $this->registrationVerifierMock->method('verify')
            ->willThrowException(new LocalizedException(__('Invalid or expired challenge token.')));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Invalid or expired challenge token.');

        $this->management()->verifyRegistration(42, 'challenge-token', '{"id":"x"}');
    }

    private function createCredential(int $entityId, ?string $name, array $transports = []): CredentialInterface&Stub
    {
        $credential = $this->createStub(CredentialInterface::class);
        $credential->method('getEntityId')->willReturn($entityId);
        $credential->method('getFriendlyName')->willReturn($name);
        $credential->method('getTransportsArray')->willReturn($transports);
        $credential->method('getCreatedAt')->willReturn('2026-03-01 10:00:00');
        $credential->method('getLastUsedAt')->willReturn(null);

        return $credential;
    }
}
