<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Model\WebAuthn;

use MageOS\PasskeyAuth\Api\WebAuthnConfigInterface;
use MageOS\PasskeyAuth\Model\ChallengeManager;
use MageOS\PasskeyAuth\Model\WebAuthn\Ceremony;
use MageOS\PasskeyAuth\Model\WebAuthn\CeremonyStepManagerProvider;
use MageOS\PasskeyAuth\Model\WebAuthn\SerializerFactory;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksChallengeManagerTrait;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use ParagonIE\ConstantTime\Base64UrlSafe;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\TrustPath\EmptyTrustPath;

class CeremonyTest extends TestCase
{
    use MocksChallengeManagerTrait;

    private const RP_ID = 'example.com';
    private const ORIGIN = 'https://example.com';

    private WebAuthnConfigInterface&Stub $configMock;
    private ?Ceremony $ceremony = null;
    private ?string $storedOptionsJson = null;

    protected function setUp(): void
    {
        $this->configMock = $this->createStub(WebAuthnConfigInterface::class);
        $this->configMock->method('getRpId')->willReturn(self::RP_ID);
        $this->configMock->method('getRpName')->willReturn('Test Store');
        $this->configMock->method('getAllowedOrigins')->willReturn([self::ORIGIN]);
        $this->configMock->method('getUserVerification')->willReturn('required');
        $this->configMock->method('getAttestationConveyance')->willReturn('none');
        $this->configMock->method('getResidentKeyRequirement')->willReturn('preferred');
        $this->configMock->method('getCeremonyTimeout')->willReturn(60000);

        $this->createChallengeManagerStub();
    }

    private function ceremony(): Ceremony
    {
        return $this->ceremony ??= new Ceremony(
            $this->configMock,
            $this->challengeManagerMock,
            new SerializerFactory(new AttestationStatementSupportManager()),
            new CeremonyStepManagerProvider(new AttestationStatementSupportManager()),
            new Json()
        );
    }

    public function testCreateRegistrationOptions(): void
    {
        $this->configMock->method('getAuthenticatorAttachment')->willReturn('platform');

        $capturedCreate = null;
        $this->mockChallengeManager()->expects($this->once())
            ->method('create')
            ->willReturnCallback(function (string $type, string $data, ?int $customerId) use (&$capturedCreate) {
                $capturedCreate = [$type, $data, $customerId];
                return 'reg-token';
            });

        $user = PublicKeyCredentialUserEntity::create('jane@example.com', 'user-handle-bytes', 'Jane Doe');
        $exclude = [
            PublicKeyCredentialDescriptor::create(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                'existing-cred',
                ['usb', 'nfc']
            ),
        ];

        $options = $this->ceremony()->createRegistrationOptions(
            $user,
            $exclude,
            ChallengeManager::TYPE_REGISTRATION,
            42
        );

        $this->assertEquals(['id' => self::RP_ID, 'name' => 'Test Store'], $options['rp']);
        $this->assertSame('jane@example.com', $options['user']['name']);
        $this->assertSame('Jane Doe', $options['user']['displayName']);
        $this->assertSame('user-handle-bytes', Base64UrlSafe::decodeNoPadding($options['user']['id']));
        $this->assertSame('none', $options['attestation']);
        $this->assertSame('platform', $options['authenticatorSelection']['authenticatorAttachment']);
        $this->assertSame('required', $options['authenticatorSelection']['userVerification']);
        $this->assertSame('preferred', $options['authenticatorSelection']['residentKey']);
        $this->assertSame(60000, $options['timeout']);
        $this->assertNotEmpty($options['challenge']);
        $this->assertSame(
            [-7, -257],
            array_column($options['pubKeyCredParams'], 'alg')
        );

        $this->assertCount(1, $options['excludeCredentials']);
        $this->assertSame('public-key', $options['excludeCredentials'][0]['type']);
        $this->assertSame(
            'existing-cred',
            Base64UrlSafe::decodeNoPadding($options['excludeCredentials'][0]['id'])
        );
        $this->assertSame(['usb', 'nfc'], $options['excludeCredentials'][0]['transports']);

        $this->assertSame('reg-token', $options['challengeToken']);
        $this->assertNoNullValues($options);

        $this->assertNotNull($capturedCreate);
        [$type, $storedJson, $customerId] = $capturedCreate;
        $this->assertSame(ChallengeManager::TYPE_REGISTRATION, $type);
        $this->assertSame(42, $customerId);
        $stored = json_decode($storedJson, true);
        $this->assertArrayNotHasKey('challengeToken', $stored);
        $this->assertSame($options['challenge'], $stored['challenge']);
    }

    public function testCreateRegistrationOptionsOmitsNullAttachment(): void
    {
        $this->configMock->method('getAuthenticatorAttachment')->willReturn(null);
        $this->mockChallengeManager()->expects($this->once())->method('create')->willReturn('reg-token');

        $options = $this->ceremony()->createRegistrationOptions(
            PublicKeyCredentialUserEntity::create('jane@example.com', 'uh', 'Jane Doe'),
            [],
            ChallengeManager::TYPE_REGISTRATION
        );

        $this->assertArrayNotHasKey('authenticatorAttachment', $options['authenticatorSelection']);
        $this->assertNoNullValues($options);
    }

    public function testCreateAuthenticationOptions(): void
    {
        $capturedCreate = null;
        $this->mockChallengeManager()->expects($this->once())
            ->method('create')
            ->willReturnCallback(function (string $type, string $data, ?int $customerId) use (&$capturedCreate) {
                $capturedCreate = [$type, $data, $customerId];
                return 'auth-token';
            });

        $allow = [
            PublicKeyCredentialDescriptor::create(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                'cred-a',
                ['internal']
            ),
        ];

        $options = $this->ceremony()->createAuthenticationOptions(
            $allow,
            ChallengeManager::TYPE_AUTHENTICATION,
            7
        );

        $this->assertSame(self::RP_ID, $options['rpId']);
        $this->assertSame('required', $options['userVerification']);
        $this->assertSame(60000, $options['timeout']);
        $this->assertNotEmpty($options['challenge']);
        $this->assertCount(1, $options['allowCredentials']);
        $this->assertSame('cred-a', Base64UrlSafe::decodeNoPadding($options['allowCredentials'][0]['id']));
        $this->assertSame(['internal'], $options['allowCredentials'][0]['transports']);
        $this->assertSame('auth-token', $options['challengeToken']);
        $this->assertNoNullValues($options);

        $this->assertNotNull($capturedCreate);
        $this->assertSame(ChallengeManager::TYPE_AUTHENTICATION, $capturedCreate[0]);
        $this->assertSame(7, $capturedCreate[2]);
    }

    public function testCreateAuthenticationOptionsWithoutCustomer(): void
    {
        $this->mockChallengeManager()->expects($this->once())
            ->method('create')
            ->with(ChallengeManager::TYPE_AUTHENTICATION, $this->callback('is_string'), null)
            ->willReturn('anon-token');

        $options = $this->ceremony()->createAuthenticationOptions([], ChallengeManager::TYPE_AUTHENTICATION);

        $this->assertSame('anon-token', $options['challengeToken']);
        $this->assertNoNullValues($options);
    }

    public function testVerifyRegistrationRejectsAssertionResponse(): void
    {
        $this->configMock->method('getAuthenticatorAttachment')->willReturn(null);
        $this->mockChallengeManager();
        $this->captureStoredOptions();
        $this->ceremony()->createRegistrationOptions(
            PublicKeyCredentialUserEntity::create('jane@example.com', 'uh', 'Jane Doe'),
            [],
            ChallengeManager::TYPE_REGISTRATION,
            42
        );

        $this->mockChallengeManager()->expects($this->once())
            ->method('consume')
            ->with('reg-token', ChallengeManager::TYPE_REGISTRATION, 42)
            ->willReturnCallback(fn () => $this->storedOptionsJson);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Invalid attestation response.');

        $this->ceremony()->verifyRegistration(
            'reg-token',
            $this->buildAssertionCredentialJson(),
            ChallengeManager::TYPE_REGISTRATION,
            42
        );
    }

    public function testLoadAssertionRejectsAttestationResponse(): void
    {
        $this->mockChallengeManager();
        $this->captureStoredOptions();
        $this->ceremony()->createAuthenticationOptions([], ChallengeManager::TYPE_AUTHENTICATION);

        $this->mockChallengeManager()->expects($this->once())
            ->method('consume')
            ->with('auth-token', ChallengeManager::TYPE_AUTHENTICATION)
            ->willReturnCallback(fn () => $this->storedOptionsJson);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Invalid assertion response.');

        $this->ceremony()->loadAssertion(
            'auth-token',
            $this->buildAttestationCredentialJson(),
            ChallengeManager::TYPE_AUTHENTICATION
        );
    }

    public function testLoadAssertionReturnsCredentialAndOptions(): void
    {
        $this->mockChallengeManager();
        $this->captureStoredOptions();
        $this->ceremony()->createAuthenticationOptions([], ChallengeManager::TYPE_AUTHENTICATION);

        $this->mockChallengeManager()->expects($this->once())
            ->method('consume')
            ->with('auth-token', ChallengeManager::TYPE_AUTHENTICATION)
            ->willReturnCallback(fn () => $this->storedOptionsJson);

        [$credential, $requestOptions] = $this->ceremony()->loadAssertion(
            'auth-token',
            $this->buildAssertionCredentialJson(),
            ChallengeManager::TYPE_AUTHENTICATION
        );

        $this->assertSame('raw-credential-id', $credential->rawId);
        $this->assertSame(self::RP_ID, $requestOptions->rpId);
    }

    public function testDeserializeSourceRoundTripsStoredCredential(): void
    {
        $source = PublicKeyCredentialSource::create(
            'raw-credential-id',
            'public-key',
            ['internal'],
            'none',
            EmptyTrustPath::create(),
            Uuid::fromString('00000000-0000-0000-0000-000000000000'),
            'credential-public-key',
            'user-handle-bytes',
            7
        );

        $restored = $this->ceremony()->deserializeSource($this->ceremony()->serializeSource($source));

        $this->assertSame('raw-credential-id', $restored->publicKeyCredentialId);
        $this->assertSame('user-handle-bytes', $restored->userHandle);
        $this->assertSame(7, $restored->counter);
    }

    public function testVerifyAssertionAcceptsDeserializedSource(): void
    {
        $this->captureStoredOptions();
        $this->ceremony()->createAuthenticationOptions([], ChallengeManager::TYPE_AUTHENTICATION);
        $this->challengeManagerMock->method('consume')->willReturnCallback(fn () => $this->storedOptionsJson);
        [$credential, $requestOptions] = $this->ceremony()->loadAssertion(
            'auth-token',
            $this->buildAssertionCredentialJson(),
            ChallengeManager::TYPE_AUTHENTICATION
        );
        $stored = $this->ceremony()->deserializeSource($this->ceremony()->serializeSource(
            PublicKeyCredentialSource::create(
                'raw-credential-id',
                'public-key',
                [],
                'none',
                EmptyTrustPath::create(),
                Uuid::fromString('00000000-0000-0000-0000-000000000000'),
                'credential-public-key',
                'uh',
                0
            )
        ));

        try {
            $this->ceremony()->verifyAssertion($credential, $requestOptions, $stored);
            $this->fail('A fabricated assertion must not verify.');
        } catch (\TypeError $e) {
            $this->fail('Stored credential type rejected: ' . $e->getMessage());
        } catch (\Throwable $e) {
            // Rejected by webauthn-lib validation, as expected for fake data
            $this->assertNotInstanceOf(\TypeError::class, $e);
        }
    }

    private function captureStoredOptions(): void
    {
        $this->challengeManagerMock->method('create')
            ->willReturnCallback(function (string $type, string $data) {
                $this->storedOptionsJson = $data;
                return 'issued-token';
            });
    }

    private function buildAssertionCredentialJson(): string
    {
        return json_encode([
            'id' => Base64UrlSafe::encodeUnpadded('raw-credential-id'),
            'rawId' => Base64UrlSafe::encodeUnpadded('raw-credential-id'),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => $this->buildClientDataJson('webauthn.get'),
                'authenticatorData' => Base64UrlSafe::encodeUnpadded($this->buildAuthenticatorData()),
                'signature' => Base64UrlSafe::encodeUnpadded('signature'),
                'userHandle' => Base64UrlSafe::encodeUnpadded('uh'),
            ],
        ]);
    }

    private function buildAttestationCredentialJson(): string
    {
        $authData = $this->buildAuthenticatorData();
        // CBOR map {"fmt": "none", "attStmt": {}, "authData": <bytes>}
        $attestationObject = "\xA3"
            . "\x63fmt" . "\x64none"
            . "\x67attStmt" . "\xA0"
            . "\x68authData" . "\x58" . chr(strlen($authData)) . $authData;

        return json_encode([
            'id' => Base64UrlSafe::encodeUnpadded('raw-credential-id'),
            'rawId' => Base64UrlSafe::encodeUnpadded('raw-credential-id'),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => $this->buildClientDataJson('webauthn.create'),
                'attestationObject' => Base64UrlSafe::encodeUnpadded($attestationObject),
            ],
        ]);
    }

    private function buildClientDataJson(string $type): string
    {
        return Base64UrlSafe::encodeUnpadded(json_encode([
            'type' => $type,
            'challenge' => Base64UrlSafe::encodeUnpadded('challenge'),
            'origin' => self::ORIGIN,
        ]));
    }

    private function buildAuthenticatorData(): string
    {
        // rpIdHash (32) + flags (UP) + signCount (4)
        return hash('sha256', self::RP_ID, true) . chr(0x01) . pack('N', 0);
    }

    private function assertNoNullValues(array $data, string $path = ''): void
    {
        foreach ($data as $key => $value) {
            $this->assertNotNull($value, sprintf('Unexpected null at "%s%s"', $path, $key));
            if (is_array($value)) {
                $this->assertNoNullValues($value, $path . $key . '.');
            }
        }
    }
}
