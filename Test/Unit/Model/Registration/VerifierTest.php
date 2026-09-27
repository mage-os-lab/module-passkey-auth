<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Model\Registration;

use MageOS\PasskeyAuth\Api\Data\CredentialInterface;
use MageOS\PasskeyAuth\Api\Data\CredentialInterfaceFactory;
use MageOS\PasskeyAuth\Model\ChallengeManager;
use MageOS\PasskeyAuth\Model\Registration\Verifier;
use MageOS\PasskeyAuth\Model\WebAuthn\Ceremony;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksConfigTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksCredentialRepositoryTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksLoggerTrait;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Webauthn\Exception\AuthenticatorResponseVerificationException;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\TrustPath\EmptyTrustPath;

class VerifierTest extends TestCase
{
    use MocksConfigTrait;
    use MocksCredentialRepositoryTrait;
    use MocksLoggerTrait;

    private const AAGUID = '6028b017-b1d4-4c02-b4b3-afcdafc96bb2';

    private Ceremony&MockObject $ceremonyMock;
    private CredentialInterfaceFactory&MockObject $credentialFactoryMock;
    private EventManager&MockObject $eventManagerMock;
    private Verifier $verifier;

    protected function setUp(): void
    {
        $this->createConfigMock();
        $this->createCredentialRepositoryMock();
        $this->createLoggerMock();

        $this->ceremonyMock = $this->createMock(Ceremony::class);
        $this->credentialFactoryMock = $this->createMock(CredentialInterfaceFactory::class);
        $this->eventManagerMock = $this->createMock(EventManager::class);

        $this->verifier = new Verifier(
            $this->configMock,
            $this->ceremonyMock,
            $this->credentialRepositoryMock,
            $this->credentialFactoryMock,
            $this->eventManagerMock,
            $this->loggerMock
        );
    }

    public function testVerifyThrowsWhenDisabled(): void
    {
        $this->configureEnabled(false);
        $this->ceremonyMock->expects($this->never())->method('verifyRegistration');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Passkey authentication is not enabled.');

        $this->verifier->verify(42, 'token', '{}');
    }

    public function testVerifyThrowsOnFriendlyNameTooLong(): void
    {
        $this->configureEnabled(true);
        $this->ceremonyMock->expects($this->never())->method('verifyRegistration');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Invalid passkey name.');

        $this->verifier->verify(42, 'token', '{}', str_repeat('A', 256));
    }

    public function testVerifyThrowsOnFriendlyNameWithXss(): void
    {
        $this->configureEnabled(true);
        $this->ceremonyMock->expects($this->never())->method('verifyRegistration');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Invalid passkey name.');

        $this->verifier->verify(42, 'token', '{}', '<script>alert(1)</script>');
    }

    public function testVerifyAcceptsNullFriendlyName(): void
    {
        $this->configureSuccessfulCeremony();
        $credential = $this->expectCredentialCreated();
        $credential->expects($this->once())->method('setFriendlyName')->with(null);

        $this->verifier->verify(42, 'token', '{}', null);
    }

    public function testVerifyConvertsBlankFriendlyNameToNull(): void
    {
        $this->configureSuccessfulCeremony();
        $credential = $this->expectCredentialCreated();
        $credential->expects($this->once())->method('setFriendlyName')->with(null);

        $this->verifier->verify(42, 'token', '{}', '   ');
    }

    public function testVerifyAcceptsFriendlyNameOfExactly255Chars(): void
    {
        $name255 = str_repeat('B', 255);
        $this->configureSuccessfulCeremony();
        $credential = $this->expectCredentialCreated();
        $credential->expects($this->once())->method('setFriendlyName')->with($name255);

        $this->verifier->verify(42, 'token', '{}', $name255);
    }

    /**
     * Challenge errors and wrong-response-type errors from the ceremony pass through unchanged,
     * without the verification-failure log or event.
     */
    public function testVerifyPassesThroughCeremonyLocalizedExceptions(): void
    {
        $this->configureEnabled(true);

        $this->ceremonyMock->expects($this->once())
            ->method('verifyRegistration')
            ->with('bad-token', '{"response":"x"}', ChallengeManager::TYPE_REGISTRATION, 42)
            ->willThrowException(new LocalizedException(__('Invalid or expired challenge token.')));

        $this->eventManagerMock->expects($this->never())->method('dispatch');
        $this->loggerMock->expects($this->never())->method('error');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Invalid or expired challenge token.');

        $this->verifier->verify(42, 'bad-token', '{"response":"x"}', 'My Passkey');
    }

    public function testVerifyPassesThroughInvalidResponseType(): void
    {
        $this->configureEnabled(true);

        $this->ceremonyMock->method('verifyRegistration')
            ->willThrowException(new LocalizedException(__('Invalid attestation response.')));
        $this->eventManagerMock->expects($this->never())->method('dispatch');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Invalid attestation response.');

        $this->verifier->verify(42, 'token', '{"response":"bad"}', 'My Key');
    }

    public function testVerifyLogsAndDispatchesEventOnValidationFailure(): void
    {
        $this->configureEnabled(true);

        $this->ceremonyMock->method('verifyRegistration')
            ->willThrowException(AuthenticatorResponseVerificationException::create('Invalid origin'));

        $this->eventManagerMock->expects($this->once())
            ->method('dispatch')
            ->with('passkey_registration_failure', [
                'customer_id' => 42,
                'reason' => 'Invalid origin',
            ]);

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with('Passkey registration verification failed', [
                'exception' => 'Invalid origin',
                'customer_id' => 42,
            ]);

        $this->credentialRepositoryMock->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Passkey registration verification failed. Please try again.');

        $this->verifier->verify(42, 'token', '{"response":"invalid"}', 'My Key');
    }

    public function testVerifyThrowsOnMaxCredentialsRaceCondition(): void
    {
        $this->configureEnabled(true);
        $this->configureMaxCredentials(5);
        $this->configureCountByCustomerId(42, 5);
        $this->ceremonyMock->method('verifyRegistration')->willReturn($this->createSource());

        $this->credentialFactoryMock->expects($this->never())->method('create');
        $this->credentialRepositoryMock->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Maximum number of passkeys (5) reached.');

        $this->verifier->verify(42, 'token', '{}', 'My Key');
    }

    public function testVerifySavesCredentialAndDispatchesEvent(): void
    {
        $source = $this->configureSuccessfulCeremony();

        $this->ceremonyMock->expects($this->once())
            ->method('serializeSource')
            ->with($source)
            ->willReturn('{"serialized":"source"}');

        $credential = $this->createMock(CredentialInterface::class);
        $credential->expects($this->once())->method('setCustomerId')->with(42);
        $credential->expects($this->once())->method('setCredentialId')->with(base64_encode('credential-id'));
        $credential->expects($this->once())->method('setPublicKey')->with('{"serialized":"source"}');
        $credential->expects($this->once())->method('setUserHandle')->with(base64_encode('user-handle'));
        $credential->expects($this->once())->method('setSignCount')->with(3);
        $credential->expects($this->once())->method('setTransports')->with('usb,internal');
        $credential->expects($this->once())->method('setFriendlyName')->with('My Key');
        $credential->expects($this->once())->method('setAaguid')->with(self::AAGUID);
        $this->credentialFactoryMock->method('create')->willReturn($credential);

        $saved = $this->createMock(CredentialInterface::class);
        $this->credentialRepositoryMock->expects($this->once())
            ->method('save')
            ->with($credential)
            ->willReturn($saved);

        $this->eventManagerMock->expects($this->once())
            ->method('dispatch')
            ->with('passkey_credential_register_after', [
                'customer_id' => 42,
                'credential' => $saved,
            ]);

        $this->assertSame($saved, $this->verifier->verify(42, 'token', '{}', '  My Key  '));
    }

    public function testVerifyStoresNullTransportsWhenNoneReported(): void
    {
        $this->configureSuccessfulCeremony([]);
        $credential = $this->expectCredentialCreated();
        $credential->expects($this->once())->method('setTransports')->with(null);

        $this->verifier->verify(42, 'token', '{}');
    }

    private function configureSuccessfulCeremony(array $transports = ['usb', 'internal']): PublicKeyCredentialSource
    {
        $this->configureEnabled(true);
        $this->configureMaxCredentials(10);
        $this->configureCountByCustomerId(42, 0);

        $source = $this->createSource($transports);
        $this->ceremonyMock->expects($this->once())
            ->method('verifyRegistration')
            ->with('token', $this->callback('is_string'), ChallengeManager::TYPE_REGISTRATION, 42)
            ->willReturn($source);

        return $source;
    }

    private function expectCredentialCreated(): CredentialInterface&MockObject
    {
        $credential = $this->createMock(CredentialInterface::class);
        $this->credentialFactoryMock->expects($this->once())->method('create')->willReturn($credential);
        $this->credentialRepositoryMock->method('save')->willReturnArgument(0);

        return $credential;
    }

    private function createSource(array $transports = ['usb', 'internal']): PublicKeyCredentialSource
    {
        return new PublicKeyCredentialSource(
            'credential-id',
            'public-key',
            $transports,
            'none',
            EmptyTrustPath::create(),
            Uuid::fromString(self::AAGUID),
            'credential-public-key',
            'user-handle',
            3
        );
    }
}
