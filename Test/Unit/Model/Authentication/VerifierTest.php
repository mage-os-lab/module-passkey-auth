<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Model\Authentication;

use MageOS\PasskeyAuth\Api\Data\AuthenticationResultInterface;
use MageOS\PasskeyAuth\Api\Data\AuthenticationResultInterfaceFactory;
use MageOS\PasskeyAuth\Api\Data\CredentialInterface;
use MageOS\PasskeyAuth\Model\Authentication\Verifier;
use MageOS\PasskeyAuth\Model\ChallengeManager;
use MageOS\PasskeyAuth\Model\PasskeyTokenService;
use MageOS\PasskeyAuth\Model\WebAuthn\Ceremony;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksConfigTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksCredentialRepositoryTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksLoggerTrait;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\Exception\AuthenticatorResponseVerificationException;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\TrustPath\EmptyTrustPath;

class VerifierTest extends TestCase
{
    use MocksConfigTrait;
    use MocksCredentialRepositoryTrait;
    use MocksLoggerTrait;

    private const RAW_ID = 'test-credential-raw-id';
    private const CUSTOMER_ID = 42;

    private Ceremony&MockObject $ceremonyMock;
    private PasskeyTokenService&MockObject $tokenServiceMock;
    private AuthenticationResultInterfaceFactory&MockObject $resultFactoryMock;
    private EventManager&MockObject $eventManagerMock;
    private DateTime&MockObject $dateTimeMock;
    private Verifier $verifier;

    private PublicKeyCredential $credential;
    private PublicKeyCredentialRequestOptions $requestOptions;
    private PublicKeyCredentialSource $storedSource;

    protected function setUp(): void
    {
        $this->createConfigMock();
        $this->createCredentialRepositoryMock();
        $this->createLoggerMock();

        $this->ceremonyMock = $this->createMock(Ceremony::class);
        $this->tokenServiceMock = $this->createMock(PasskeyTokenService::class);
        $this->resultFactoryMock = $this->createMock(AuthenticationResultInterfaceFactory::class);
        $this->eventManagerMock = $this->createMock(EventManager::class);
        $this->dateTimeMock = $this->createMock(DateTime::class);

        $this->credential = PublicKeyCredential::create(
            'public-key',
            self::RAW_ID,
            $this->createStub(AuthenticatorAssertionResponse::class)
        );
        $this->requestOptions = PublicKeyCredentialRequestOptions::create('fake-challenge');
        $this->storedSource = $this->createSource(0);

        $this->verifier = new Verifier(
            $this->configMock,
            $this->ceremonyMock,
            $this->credentialRepositoryMock,
            $this->tokenServiceMock,
            $this->resultFactoryMock,
            $this->eventManagerMock,
            $this->loggerMock,
            $this->dateTimeMock
        );
    }

    public function testVerifyThrowsWhenDisabled(): void
    {
        $this->configureEnabled(false);
        $this->ceremonyMock->expects($this->never())->method('loadAssertion');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Passkey authentication is not enabled.');

        $this->verifier->verify('token123', '{"response":"data"}');
    }

    public function testVerifyThrowsOnInvalidChallengeToken(): void
    {
        $this->configureEnabled(true);

        $this->ceremonyMock->expects($this->once())
            ->method('loadAssertion')
            ->with('bad-token', '{"response":"data"}', ChallengeManager::TYPE_AUTHENTICATION)
            ->willThrowException(new LocalizedException(__('Invalid or expired challenge token.')));
        $this->eventManagerMock->expects($this->never())->method('dispatch');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Invalid or expired challenge token.');

        $this->verifier->verify('bad-token', '{"response":"data"}');
    }

    public function testVerifyThrowsOnInvalidResponseType(): void
    {
        $this->configureEnabled(true);

        $this->ceremonyMock->method('loadAssertion')
            ->willThrowException(new LocalizedException(__('Invalid assertion response.')));
        $this->credentialRepositoryMock->expects($this->never())->method('getByCredentialId');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Invalid assertion response.');

        $this->verifier->verify('valid-token', '{"response":"attestation-not-assertion"}');
    }

    public function testVerifyThrowsWhenCredentialNotFound(): void
    {
        $this->configureEnabled(true);
        $this->configureLoadAssertion();

        $expectedCredentialId = base64_encode(self::RAW_ID);

        $this->credentialRepositoryMock->method('getByCredentialId')
            ->with($expectedCredentialId)
            ->willThrowException(new NoSuchEntityException(__('No such entity.')));
        $this->ceremonyMock->expects($this->never())->method('verifyAssertion');

        $this->eventManagerMock->expects($this->once())
            ->method('dispatch')
            ->with('passkey_authentication_failure', [
                'credential_id' => $expectedCredentialId,
                'reason' => 'credential_not_found',
            ]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Passkey verification failed. Please try again.');

        $this->verifier->verify('valid-token', '{"response":"assertion"}');
    }

    public function testVerifyThrowsWhenValidatorFails(): void
    {
        $this->configureEnabled(true);
        $this->configureLoadAssertion();
        $this->configureStoredCredential(0);

        $this->ceremonyMock->expects($this->once())
            ->method('verifyAssertion')
            ->with($this->credential, $this->requestOptions, $this->storedSource)
            ->willThrowException(
                AuthenticatorResponseVerificationException::create('Signature verification failed')
            );

        $this->eventManagerMock->expects($this->once())
            ->method('dispatch')
            ->with('passkey_authentication_failure', [
                'credential_id' => base64_encode(self::RAW_ID),
                'reason' => 'Signature verification failed',
            ]);
        $this->tokenServiceMock->expects($this->never())->method('createTokenForCustomer');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Passkey verification failed. Please try again.');

        $this->verifier->verify('valid-token', '{"response":"assertion"}');
    }

    public function testVerifySucceeds(): void
    {
        $this->configureEnabled(true);
        $this->configureLoadAssertion();
        $storedCredential = $this->configureStoredCredential(5);
        $updatedSource = $this->configureVerifiedAssertion(6);

        $this->ceremonyMock->expects($this->once())
            ->method('serializeSource')
            ->with($updatedSource)
            ->willReturn('{"updated":"source"}');
        $this->dateTimeMock->method('gmtDate')->willReturn('2026-03-04 12:00:00');

        $storedCredential->expects($this->once())->method('setSignCount')->with(6);
        $storedCredential->expects($this->once())->method('setPublicKey')->with('{"updated":"source"}');
        $storedCredential->expects($this->once())->method('setLastUsedAt')->with('2026-03-04 12:00:00');
        $this->credentialRepositoryMock->expects($this->once())->method('save')->with($storedCredential);

        $this->tokenServiceMock->expects($this->once())
            ->method('createTokenForCustomer')
            ->with(self::CUSTOMER_ID)
            ->willReturn('test-token-value');

        $result = $this->createMock(AuthenticationResultInterface::class);
        $this->resultFactoryMock->expects($this->once())
            ->method('create')
            ->with(['data' => ['customer_id' => self::CUSTOMER_ID, 'token' => 'test-token-value']])
            ->willReturn($result);

        $this->eventManagerMock->expects($this->once())
            ->method('dispatch')
            ->with('passkey_authentication_success', [
                'customer_id' => self::CUSTOMER_ID,
                'credential' => $storedCredential,
            ]);
        $this->loggerMock->expects($this->never())->method('warning');

        $this->assertSame($result, $this->verifier->verify('valid-token', '{"response":"assertion"}'));
    }

    public function testVerifyWarnsOnSignCountDecrease(): void
    {
        $this->configureEnabled(true);
        $this->configureLoadAssertion();
        $this->configureStoredCredential(5);
        $this->configureVerifiedAssertion(1);
        $this->configureTokenAndResult();

        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with('Passkey sign count decreased — possible cloned authenticator', [
                'credential_id' => base64_encode(self::RAW_ID),
                'customer_id' => self::CUSTOMER_ID,
                'stored_count' => 5,
                'received_count' => 1,
            ]);

        $this->verifier->verify('valid-token', '{"response":"assertion"}');
    }

    public function testVerifyNoWarningWhenBothSignCountsZero(): void
    {
        $this->configureEnabled(true);
        $this->configureLoadAssertion();
        $this->configureStoredCredential(0);
        $this->configureVerifiedAssertion(0);
        $result = $this->configureTokenAndResult();

        $this->loggerMock->expects($this->never())->method('warning');

        $this->assertSame($result, $this->verifier->verify('valid-token', '{"response":"assertion"}'));
    }

    public function testVerifySucceedsWhenCredentialUpdateFails(): void
    {
        $this->configureEnabled(true);
        $this->configureLoadAssertion();
        $this->configureStoredCredential(5);
        $this->configureVerifiedAssertion(10);
        $result = $this->configureTokenAndResult();

        $this->credentialRepositoryMock->method('save')
            ->willThrowException(new \RuntimeException('Database connection lost'));

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with('Failed to update passkey credential after authentication', [
                'exception' => 'Database connection lost',
                'credential_id' => base64_encode(self::RAW_ID),
            ]);

        $this->assertSame($result, $this->verifier->verify('valid-token', '{"response":"assertion"}'));
    }

    public function testVerifyThrowsWhenTokenCreationFails(): void
    {
        $this->configureEnabled(true);
        $this->configureLoadAssertion();
        $this->configureStoredCredential(5);
        $this->configureVerifiedAssertion(10);
        $this->dateTimeMock->method('gmtDate')->willReturn('2026-03-04 12:00:00');

        $this->tokenServiceMock->method('createTokenForCustomer')
            ->with(self::CUSTOMER_ID)
            ->willThrowException(new \RuntimeException('Token service unavailable'));

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with('Failed to create token for passkey customer', [
                'exception' => 'Token service unavailable',
                'customer_id' => self::CUSTOMER_ID,
            ]);
        $this->eventManagerMock->expects($this->never())->method('dispatch');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Authentication succeeded but token creation failed.');

        $this->verifier->verify('valid-token', '{"response":"assertion"}');
    }

    private function configureLoadAssertion(): void
    {
        $this->ceremonyMock->expects($this->once())
            ->method('loadAssertion')
            ->with('valid-token', '{"response":"assertion"}', ChallengeManager::TYPE_AUTHENTICATION)
            ->willReturn([$this->credential, $this->requestOptions]);
    }

    private function configureStoredCredential(int $storedSignCount): CredentialInterface&MockObject
    {
        $storedCredential = $this->createMock(CredentialInterface::class);
        $storedCredential->method('getPublicKey')->willReturn('{"serialized":"credential-source"}');
        $storedCredential->method('getCustomerId')->willReturn(self::CUSTOMER_ID);
        $storedCredential->method('getSignCount')->willReturn($storedSignCount);

        $this->credentialRepositoryMock->method('getByCredentialId')
            ->with(base64_encode(self::RAW_ID))
            ->willReturn($storedCredential);

        $this->ceremonyMock->method('deserializeSource')
            ->with('{"serialized":"credential-source"}')
            ->willReturn($this->storedSource);

        return $storedCredential;
    }

    private function configureVerifiedAssertion(int $newCounter): PublicKeyCredentialSource
    {
        $updatedSource = $this->createSource($newCounter);
        $this->ceremonyMock->expects($this->once())
            ->method('verifyAssertion')
            ->with($this->credential, $this->requestOptions, $this->storedSource)
            ->willReturn($updatedSource);

        return $updatedSource;
    }

    private function configureTokenAndResult(): AuthenticationResultInterface
    {
        $this->dateTimeMock->method('gmtDate')->willReturn('2026-03-04 12:00:00');
        $this->tokenServiceMock->method('createTokenForCustomer')
            ->with(self::CUSTOMER_ID)
            ->willReturn('test-token-value');

        $result = $this->createStub(AuthenticationResultInterface::class);
        $this->resultFactoryMock->method('create')->willReturn($result);

        return $result;
    }

    private function createSource(int $counter): PublicKeyCredentialSource
    {
        return new PublicKeyCredentialSource(
            self::RAW_ID,
            'public-key',
            [],
            'none',
            EmptyTrustPath::create(),
            Uuid::fromString('00000000-0000-0000-0000-000000000000'),
            'fake-credential-public-key',
            'user-handle-bytes',
            $counter
        );
    }
}
