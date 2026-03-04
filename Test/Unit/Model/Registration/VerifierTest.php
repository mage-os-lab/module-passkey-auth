<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Model\Registration;

use MageOS\PasskeyAuth\Api\Data\CredentialInterface;
use MageOS\PasskeyAuth\Api\Data\CredentialInterfaceFactory;
use MageOS\PasskeyAuth\Model\ChallengeManager;
use MageOS\PasskeyAuth\Model\Registration\Verifier;
use MageOS\PasskeyAuth\Model\WebAuthn\CeremonyStepManagerProvider;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksChallengeManagerTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksConfigTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksCredentialRepositoryTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksLoggerTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksSerializerFactoryTrait;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Webauthn\AttestationStatement\AttestationObject;
use Webauthn\AttestationStatement\AttestationStatement;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorData;
use Webauthn\AuthenticatorResponse;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CollectedClientData;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\TrustPath\EmptyTrustPath;

class VerifierTest extends TestCase
{
    use MocksConfigTrait;
    use MocksChallengeManagerTrait;
    use MocksSerializerFactoryTrait;
    use MocksCredentialRepositoryTrait;
    use MocksLoggerTrait;

    private CeremonyStepManagerProvider&MockObject $ceremonyProviderMock;
    private CredentialInterfaceFactory&MockObject $credentialFactoryMock;
    private EventManager&MockObject $eventManagerMock;
    private Verifier $verifier;

    protected function setUp(): void
    {
        $this->createConfigMock();
        $this->createChallengeManagerMock();
        $this->createSerializerFactoryMock();
        $this->createCredentialRepositoryMock();
        $this->createLoggerMock();

        $this->ceremonyProviderMock = $this->createMock(CeremonyStepManagerProvider::class);
        $this->credentialFactoryMock = $this->createMock(CredentialInterfaceFactory::class);
        $this->eventManagerMock = $this->createMock(EventManager::class);

        $this->verifier = new Verifier(
            $this->configMock,
            $this->challengeManagerMock,
            $this->serializerFactoryMock,
            $this->ceremonyProviderMock,
            $this->credentialRepositoryMock,
            $this->credentialFactoryMock,
            $this->eventManagerMock,
            $this->loggerMock
        );
    }

    public function testVerifyThrowsWhenDisabled(): void
    {
        $this->configureEnabled(false);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Passkey authentication is not enabled.');

        $this->verifier->verify(42, 'token', '{}');
    }

    public function testVerifyAcceptsNullFriendlyName(): void
    {
        $this->configureEnabled(true);

        // null friendlyName should skip all validation and proceed to challenge consume.
        // We let consume throw to stop execution after the friendlyName check passes.
        $this->challengeManagerMock->method('consume')
            ->willThrowException(new LocalizedException(__('Invalid or expired challenge token.')));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Invalid or expired challenge token.');

        $this->verifier->verify(42, 'token', '{}', null);
    }

    public function testVerifyConvertsEmptyFriendlyNameToNull(): void
    {
        $this->configureEnabled(true);

        // '  ' (spaces only) should be trimmed to '', then set to null — no validation exception.
        // Let consume throw to stop after friendlyName processing.
        $this->challengeManagerMock->method('consume')
            ->willThrowException(new LocalizedException(__('Invalid or expired challenge token.')));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Invalid or expired challenge token.');

        // If friendlyName='  ' caused a validation error, we'd see 'Invalid passkey name.' instead.
        $this->verifier->verify(42, 'token', '{}', '  ');
    }

    public function testVerifyThrowsOnFriendlyNameTooLong(): void
    {
        $this->configureEnabled(true);

        $longName = str_repeat('A', 256);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Invalid passkey name.');

        $this->verifier->verify(42, 'token', '{}', $longName);
    }

    public function testVerifyThrowsOnFriendlyNameWithXss(): void
    {
        $this->configureEnabled(true);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Invalid passkey name.');

        $this->verifier->verify(42, 'token', '{}', '<script>alert(1)</script>');
    }

    public function testVerifyThrowsOnInvalidChallengeToken(): void
    {
        $this->configureEnabled(true);

        $this->challengeManagerMock->method('consume')
            ->with('bad-token', ChallengeManager::TYPE_REGISTRATION, 42)
            ->willThrowException(new LocalizedException(__('Invalid or expired challenge token.')));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Invalid or expired challenge token.');

        $this->verifier->verify(42, 'bad-token', '{}', 'My Passkey');
    }

    private function createRealCreationOptions(): PublicKeyCredentialCreationOptions
    {
        $rp = PublicKeyCredentialRpEntity::create('Test', 'example.com');
        $user = PublicKeyCredentialUserEntity::create('test@example.com', 'user-handle', 'Test User');
        return PublicKeyCredentialCreationOptions::create($rp, $user, random_bytes(32));
    }

    public function testVerifyThrowsOnInvalidResponseType(): void
    {
        $this->configureEnabled(true);

        $storedOptionsJson = '{"rp":{"name":"Test"},"challenge":"abc"}';
        $this->challengeManagerMock->method('consume')
            ->willReturn($storedOptionsJson);

        $creationOptions = $this->createRealCreationOptions();
        $this->serializerMock->method('deserialize')
            ->willReturnCallback(function (string $data, string $type) use ($creationOptions) {
                if ($type === PublicKeyCredentialCreationOptions::class) {
                    return $creationOptions;
                }
                if ($type === PublicKeyCredential::class) {
                    // Return a PublicKeyCredential whose response is NOT AuthenticatorAttestationResponse
                    $nonAttestationResponse = $this->createMock(AuthenticatorResponse::class);
                    return new PublicKeyCredential('public-key', 'rawId', $nonAttestationResponse);
                }
                return null;
            });

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Invalid attestation response.');

        $this->verifier->verify(42, 'token', '{"response":"bad"}', 'My Key');
    }

    /**
     * Tests that the race condition check after successful validation prevents
     * exceeding max credentials.
     *
     * NOTE: This test cannot be fully executed in a unit test because
     * AuthenticatorAttestationResponseValidator::create() is a static factory
     * that returns a real validator which requires valid cryptographic data.
     * The race condition check occurs AFTER the validator succeeds, making it
     * unreachable without real WebAuthn ceremony data.
     *
     * Integration test coverage is needed for this path.
     */
    public function testVerifyThrowsOnMaxCredentialsRaceCondition(): void
    {
        $this->markTestSkipped(
            'Race condition check occurs after AuthenticatorAttestationResponseValidator::create() '
            . 'static call which cannot be mocked. Requires integration test with real WebAuthn data.'
        );
    }

    /**
     * Creates a real AuthenticatorAttestationResponse with valid structure but
     * invalid attestation data, causing the real validator to throw an Exception.
     */
    private function createRealInvalidAttestationResponse(): AuthenticatorAttestationResponse
    {
        $challenge = rtrim(Base64UrlSafe::encode(random_bytes(32)), '=');
        $clientDataJson = json_encode([
            'type' => 'webauthn.create',
            'challenge' => $challenge,
            'origin' => 'https://example.com',
        ]);
        $clientData = new CollectedClientData($clientDataJson, json_decode($clientDataJson, true));

        $rpIdHash = hash('sha256', 'example.com', true);
        $flags = chr(0x41); // UP + AT flags
        $signCount = pack('N', 0);
        $authDataBin = $rpIdHash . $flags . $signCount;
        $authData = new AuthenticatorData($authDataBin, $rpIdHash, $flags, 0, null, null);

        $attStmt = new AttestationStatement(
            'none',
            [],
            AttestationStatement::TYPE_NONE,
            EmptyTrustPath::create()
        );
        $attObj = new AttestationObject($authDataBin, $attStmt, $authData);

        return new AuthenticatorAttestationResponse($clientData, $attObj);
    }

    public function testVerifyDispatchesEventOnValidationFailure(): void
    {
        $this->configureEnabled(true);

        $storedOptionsJson = '{"rp":{"name":"Test"},"challenge":"abc"}';
        $this->challengeManagerMock->method('consume')
            ->willReturn($storedOptionsJson);

        $creationOptions = $this->createRealCreationOptions();

        // Create a real AuthenticatorAttestationResponse with valid structure
        // but no attested credential data, so the validator throws a proper Exception
        $attestationResponse = $this->createRealInvalidAttestationResponse();
        $publicKeyCredential = new PublicKeyCredential('public-key', 'rawId', $attestationResponse);

        $this->serializerMock->method('deserialize')
            ->willReturnCallback(
                function (string $data, string $type) use ($creationOptions, $publicKeyCredential) {
                    if ($type === PublicKeyCredentialCreationOptions::class) {
                        return $creationOptions;
                    }
                    if ($type === PublicKeyCredential::class) {
                        return $publicKeyCredential;
                    }
                    return null;
                }
            );

        $this->configMock->method('getRpId')->willReturn('example.com');
        $this->configMock->method('getAllowedOrigins')->willReturn(['https://example.com']);

        // Provide a real CeremonyStepManager since the class is final and cannot be mocked
        $asm = AttestationStatementSupportManager::create();
        $factory = new CeremonyStepManagerFactory();
        $factory->setAllowedOrigins(['https://example.com']);
        $factory->setAttestationStatementSupportManager($asm);
        $this->ceremonyProviderMock->method('getCreationCeremony')
            ->willReturn($factory->creationCeremony());

        // The real validator will throw because the response has no attested credential data.
        // Verify the event is dispatched on failure.
        $this->eventManagerMock->expects($this->once())
            ->method('dispatch')
            ->with(
                'passkey_registration_failure',
                $this->callback(function (array $data) {
                    return $data['customer_id'] === 42
                        && isset($data['reason'])
                        && is_string($data['reason']);
                })
            );

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with(
                'Passkey registration verification failed',
                $this->callback(function (array $context) {
                    return $context['customer_id'] === 42
                        && isset($context['exception']);
                })
            );

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Passkey registration verification failed. Please try again.');

        $this->verifier->verify(42, 'token', '{"response":"invalid"}', 'My Key');
    }

    public function testVerifyFriendlyNameExactly255Chars(): void
    {
        $this->configureEnabled(true);

        $name255 = str_repeat('B', 255);

        // 255 chars should pass validation and proceed to challenge consume.
        // Let consume throw to stop execution after the friendlyName check passes.
        $this->challengeManagerMock->method('consume')
            ->willThrowException(new LocalizedException(__('Invalid or expired challenge token.')));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Invalid or expired challenge token.');

        // If 255 chars caused a validation error, we'd see 'Invalid passkey name.' instead.
        $this->verifier->verify(42, 'token', '{}', $name255);
    }
}
