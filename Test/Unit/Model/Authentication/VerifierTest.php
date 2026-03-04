<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Model\Authentication;

use MageOS\PasskeyAuth\Api\Data\AuthenticationResultInterface;
use MageOS\PasskeyAuth\Api\Data\AuthenticationResultInterfaceFactory;
use MageOS\PasskeyAuth\Api\Data\CredentialInterface;
use MageOS\PasskeyAuth\Model\Authentication\Verifier;
use MageOS\PasskeyAuth\Model\ChallengeManager;
use MageOS\PasskeyAuth\Model\PasskeyTokenService;
use MageOS\PasskeyAuth\Model\WebAuthn\CeremonyStepManagerProvider;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksChallengeManagerTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksConfigTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksCredentialRepositoryTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksLoggerTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksSerializerFactoryTrait;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorData;
use Webauthn\CeremonyStep\CeremonyStepManager;
use Webauthn\CollectedClientData;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialSource;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * Unit tests for Authentication\Verifier.
 *
 * Note: testVerifySuccessful (full happy-path) is intentionally omitted because it would
 * duplicate the post-validator tests below without adding meaningful coverage. The happy path
 * is best validated at the integration level with real WebAuthn ceremony data.
 *
 * Tests that need to get past AuthenticatorAssertionResponseValidator::check() use a real
 * CeremonyStepManager with no steps, which bypasses all cryptographic validation. This allows
 * exercising the post-validator code paths (sign count, credential update, token creation)
 * while keeping these tests at the unit level.
 */
class VerifierTest extends TestCase
{
    use MocksConfigTrait;
    use MocksChallengeManagerTrait;
    use MocksSerializerFactoryTrait;
    use MocksCredentialRepositoryTrait;
    use MocksLoggerTrait;

    private CeremonyStepManagerProvider&MockObject $ceremonyProviderMock;
    private PasskeyTokenService&MockObject $tokenServiceMock;
    private AuthenticationResultInterfaceFactory&MockObject $resultFactoryMock;
    private EventManager&MockObject $eventManagerMock;
    private DateTime&MockObject $dateTimeMock;
    private Verifier $verifier;

    protected function setUp(): void
    {
        $this->createConfigMock();
        $this->createChallengeManagerMock();
        $this->createSerializerFactoryMock();
        $this->createCredentialRepositoryMock();
        $this->createLoggerMock();

        $this->ceremonyProviderMock = $this->createMock(CeremonyStepManagerProvider::class);
        $this->tokenServiceMock = $this->createMock(PasskeyTokenService::class);
        $this->resultFactoryMock = $this->createMock(AuthenticationResultInterfaceFactory::class);
        $this->eventManagerMock = $this->createMock(EventManager::class);
        $this->dateTimeMock = $this->createMock(DateTime::class);

        $this->verifier = new Verifier(
            $this->configMock,
            $this->challengeManagerMock,
            $this->serializerFactoryMock,
            $this->ceremonyProviderMock,
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

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Passkey authentication is not enabled.');

        $this->verifier->verify('token123', '{"response":"data"}');
    }

    public function testVerifyThrowsOnInvalidChallengeToken(): void
    {
        $this->configureEnabled(true);

        $this->challengeManagerMock->method('consume')
            ->willThrowException(new LocalizedException(__('Invalid or expired challenge token.')));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Invalid or expired challenge token.');

        $this->verifier->verify('bad-token', '{"response":"data"}');
    }

    public function testVerifyThrowsOnInvalidResponseType(): void
    {
        $this->configureEnabled(true);

        $this->challengeManagerMock->method('consume')
            ->willReturn('{"challenge":"stored-options"}');

        $requestOptions = PublicKeyCredentialRequestOptions::create('fake-challenge');

        $attestationResponse = $this->createMock(AuthenticatorAttestationResponse::class);
        $publicKeyCredential = PublicKeyCredential::create(
            'public-key',
            'raw-id-bytes',
            $attestationResponse
        );

        $this->serializerMock->method('deserialize')
            ->willReturnCallback(function (string $data, string $type) use ($requestOptions, $publicKeyCredential) {
                if ($type === PublicKeyCredentialRequestOptions::class) {
                    return $requestOptions;
                }
                if ($type === PublicKeyCredential::class) {
                    return $publicKeyCredential;
                }
                return null;
            });

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Invalid assertion response.');

        $this->verifier->verify('valid-token', '{"response":"attestation-not-assertion"}');
    }

    public function testVerifyThrowsWhenCredentialNotFound(): void
    {
        $this->configureEnabled(true);

        $this->challengeManagerMock->method('consume')
            ->willReturn('{"challenge":"stored-options"}');

        $rawId = 'test-credential-raw-id';
        $this->configureDeserializerWithAssertionResponse($rawId, 0);

        $expectedCredentialId = base64_encode($rawId);

        $this->credentialRepositoryMock->method('getByCredentialId')
            ->with($expectedCredentialId)
            ->willThrowException(new NoSuchEntityException(__('No such entity.')));

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

    /**
     * Tests that when the validator throws (using a CeremonyStepManager with a failing step),
     * the failure event is dispatched and a LocalizedException is thrown.
     */
    public function testVerifyThrowsWhenValidatorFails(): void
    {
        $this->configureEnabled(true);

        $this->challengeManagerMock->method('consume')
            ->willReturn('{"challenge":"stored-options"}');

        $rawId = 'test-credential-raw-id';
        $credentialSource = $this->configureDeserializerWithAssertionResponse($rawId, 0);

        $expectedCredentialId = base64_encode($rawId);

        $storedCredential = $this->createMock(CredentialInterface::class);
        $storedCredential->method('getPublicKey')->willReturn('{"serialized":"credential-source"}');

        $this->credentialRepositoryMock->method('getByCredentialId')
            ->with($expectedCredentialId)
            ->willReturn($storedCredential);

        $this->configMock->method('getRpId')->willReturn('example.com');

        // Use a CeremonyStepManager with a step that always throws
        $failingStep = new class implements \Webauthn\CeremonyStep\CeremonyStep {
            public function process(
                PublicKeyCredentialSource $publicKeyCredentialSource,
                \Webauthn\AuthenticatorAssertionResponse|\Webauthn\AuthenticatorAttestationResponse $authenticatorResponse,
                \Webauthn\PublicKeyCredentialRequestOptions|\Webauthn\PublicKeyCredentialCreationOptions $publicKeyCredentialOptions,
                ?string $userHandle,
                string $host
            ): void {
                throw \Webauthn\Exception\AuthenticatorResponseVerificationException::create('Signature verification failed');
            }
        };
        $failingCSM = new CeremonyStepManager([$failingStep]);
        $this->ceremonyProviderMock->method('getRequestCeremony')->willReturn($failingCSM);

        $this->eventManagerMock->expects($this->once())
            ->method('dispatch')
            ->with(
                'passkey_authentication_failure',
                $this->callback(function (array $params) use ($expectedCredentialId) {
                    return $params['credential_id'] === $expectedCredentialId
                        && isset($params['reason']);
                })
            );

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Passkey verification failed. Please try again.');

        $this->verifier->verify('valid-token', '{"response":"assertion"}');
    }

    public function testVerifyWarnsOnSignCountDecrease(): void
    {
        $this->configureEnabled(true);

        $this->challengeManagerMock->method('consume')
            ->willReturn('{"challenge":"stored-options"}');

        $rawId = 'test-credential-raw-id';
        // authenticatorData signCount = 1 (will be set on the credential source by the validator)
        $this->configureDeserializerWithAssertionResponse($rawId, 1);

        $expectedCredentialId = base64_encode($rawId);
        $customerId = 42;

        $storedCredential = $this->createMock(CredentialInterface::class);
        $storedCredential->method('getPublicKey')->willReturn('{"serialized":"credential-source"}');
        $storedCredential->method('getCustomerId')->willReturn($customerId);
        $storedCredential->method('getSignCount')->willReturn(5); // Stored count > received count

        $this->credentialRepositoryMock->method('getByCredentialId')
            ->with($expectedCredentialId)
            ->willReturn($storedCredential);

        $this->configMock->method('getRpId')->willReturn('example.com');
        $this->configureNoStepCeremony();

        $this->tokenServiceMock->method('createTokenForCustomer')
            ->with($customerId)
            ->willReturn('test-token-value');

        $result = $this->createMock(AuthenticationResultInterface::class);
        $this->resultFactoryMock->method('create')->willReturn($result);
        $this->dateTimeMock->method('gmtDate')->willReturn('2026-03-04 12:00:00');

        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                'Passkey sign count decreased — possible cloned authenticator',
                $this->callback(function (array $context) use ($expectedCredentialId, $customerId) {
                    return $context['credential_id'] === $expectedCredentialId
                        && $context['customer_id'] === $customerId
                        && $context['stored_count'] === 5
                        && $context['received_count'] === 1;
                })
            );

        $this->verifier->verify('valid-token', '{"response":"assertion"}');
    }

    public function testVerifySucceedsWhenCredentialUpdateFails(): void
    {
        $this->configureEnabled(true);

        $this->challengeManagerMock->method('consume')
            ->willReturn('{"challenge":"stored-options"}');

        $rawId = 'test-credential-raw-id';
        $this->configureDeserializerWithAssertionResponse($rawId, 10);

        $expectedCredentialId = base64_encode($rawId);
        $customerId = 42;

        $storedCredential = $this->createMock(CredentialInterface::class);
        $storedCredential->method('getPublicKey')->willReturn('{"serialized":"credential-source"}');
        $storedCredential->method('getCustomerId')->willReturn($customerId);
        $storedCredential->method('getSignCount')->willReturn(5);

        $this->credentialRepositoryMock->method('getByCredentialId')
            ->with($expectedCredentialId)
            ->willReturn($storedCredential);

        $this->credentialRepositoryMock->method('save')
            ->willThrowException(new \RuntimeException('Database connection lost'));

        $this->configMock->method('getRpId')->willReturn('example.com');
        $this->configureNoStepCeremony();

        $this->tokenServiceMock->method('createTokenForCustomer')
            ->with($customerId)
            ->willReturn('test-token-value');

        $result = $this->createMock(AuthenticationResultInterface::class);
        $this->resultFactoryMock->method('create')->willReturn($result);
        $this->dateTimeMock->method('gmtDate')->willReturn('2026-03-04 12:00:00');

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with(
                'Failed to update passkey credential after authentication',
                $this->callback(function (array $context) use ($expectedCredentialId) {
                    return $context['exception'] === 'Database connection lost'
                        && $context['credential_id'] === $expectedCredentialId;
                })
            );

        $returnedResult = $this->verifier->verify('valid-token', '{"response":"assertion"}');

        $this->assertSame($result, $returnedResult);
    }

    public function testVerifyThrowsWhenTokenCreationFails(): void
    {
        $this->configureEnabled(true);

        $this->challengeManagerMock->method('consume')
            ->willReturn('{"challenge":"stored-options"}');

        $rawId = 'test-credential-raw-id';
        $this->configureDeserializerWithAssertionResponse($rawId, 10);

        $expectedCredentialId = base64_encode($rawId);
        $customerId = 42;

        $storedCredential = $this->createMock(CredentialInterface::class);
        $storedCredential->method('getPublicKey')->willReturn('{"serialized":"credential-source"}');
        $storedCredential->method('getCustomerId')->willReturn($customerId);
        $storedCredential->method('getSignCount')->willReturn(5);

        $this->credentialRepositoryMock->method('getByCredentialId')
            ->with($expectedCredentialId)
            ->willReturn($storedCredential);

        $this->configMock->method('getRpId')->willReturn('example.com');
        $this->configureNoStepCeremony();
        $this->dateTimeMock->method('gmtDate')->willReturn('2026-03-04 12:00:00');

        $this->tokenServiceMock->method('createTokenForCustomer')
            ->with($customerId)
            ->willThrowException(new \RuntimeException('Token service unavailable'));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Authentication succeeded but token creation failed.');

        $this->verifier->verify('valid-token', '{"response":"assertion"}');
    }

    public function testVerifyNoWarningWhenBothSignCountsZero(): void
    {
        $this->configureEnabled(true);

        $this->challengeManagerMock->method('consume')
            ->willReturn('{"challenge":"stored-options"}');

        $rawId = 'test-credential-raw-id';
        // authenticatorData signCount = 0
        $this->configureDeserializerWithAssertionResponse($rawId, 0);

        $expectedCredentialId = base64_encode($rawId);
        $customerId = 42;

        $storedCredential = $this->createMock(CredentialInterface::class);
        $storedCredential->method('getPublicKey')->willReturn('{"serialized":"credential-source"}');
        $storedCredential->method('getCustomerId')->willReturn($customerId);
        $storedCredential->method('getSignCount')->willReturn(0); // Both zero

        $this->credentialRepositoryMock->method('getByCredentialId')
            ->with($expectedCredentialId)
            ->willReturn($storedCredential);

        $this->configMock->method('getRpId')->willReturn('example.com');
        $this->configureNoStepCeremony();

        $this->tokenServiceMock->method('createTokenForCustomer')
            ->with($customerId)
            ->willReturn('test-token-value');

        $result = $this->createMock(AuthenticationResultInterface::class);
        $this->resultFactoryMock->method('create')->willReturn($result);
        $this->dateTimeMock->method('gmtDate')->willReturn('2026-03-04 12:00:00');

        $this->loggerMock->expects($this->never())->method('warning');

        $returnedResult = $this->verifier->verify('valid-token', '{"response":"assertion"}');

        $this->assertSame($result, $returnedResult);
    }

    /**
     * Configure the ceremony provider to return a CeremonyStepManager with no steps,
     * effectively bypassing all cryptographic validation.
     */
    private function configureNoStepCeremony(): void
    {
        $noOpCSM = new CeremonyStepManager([]);
        $this->ceremonyProviderMock->method('getRequestCeremony')->willReturn($noOpCSM);
    }

    /**
     * Create real WebAuthn objects and configure the serializer mock to return them.
     *
     * Uses real (non-mock) AuthenticatorAssertionResponse, AuthenticatorData,
     * CollectedClientData, PublicKeyCredential, and PublicKeyCredentialSource instances
     * because the WebAuthn validator accesses their public readonly properties directly.
     *
     * @return PublicKeyCredentialSource The credential source that the validator will mutate
     */
    private function configureDeserializerWithAssertionResponse(
        string $rawId,
        int $signCount
    ): PublicKeyCredentialSource {
        $requestOptions = PublicKeyCredentialRequestOptions::create('fake-challenge');

        $clientData = CollectedClientData::create('raw-client-data', [
            'type' => 'webauthn.get',
            'challenge' => Base64UrlSafe::encodeUnpadded('fake-challenge'),
            'origin' => 'https://example.com',
        ]);

        $authenticatorData = AuthenticatorData::create(
            'auth-data-bytes',
            hash('sha256', 'example.com', true),
            "\x01", // flags: UP
            $signCount
        );

        $assertionResponse = AuthenticatorAssertionResponse::create(
            $clientData,
            $authenticatorData,
            'fake-signature',
            'user-handle-bytes'
        );

        $publicKeyCredential = PublicKeyCredential::create(
            'public-key',
            $rawId,
            $assertionResponse
        );

        $credentialSource = PublicKeyCredentialSource::create(
            $rawId,
            'public-key',
            [],
            'none',
            EmptyTrustPath::create(),
            Uuid::fromString('00000000-0000-0000-0000-000000000000'),
            'fake-credential-public-key',
            'user-handle-bytes',
            0 // initial counter
        );

        $this->serializerMock->method('deserialize')
            ->willReturnCallback(
                function (string $data, string $type) use (
                    $requestOptions,
                    $publicKeyCredential,
                    $credentialSource
                ) {
                    if ($type === PublicKeyCredentialRequestOptions::class) {
                        return $requestOptions;
                    }
                    if ($type === PublicKeyCredential::class) {
                        return $publicKeyCredential;
                    }
                    if ($type === PublicKeyCredentialSource::class) {
                        return $credentialSource;
                    }
                    return null;
                }
            );

        return $credentialSource;
    }
}
