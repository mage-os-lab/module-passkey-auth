<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Model\AdminTfa;

use MageOS\PasskeyAuth\Model\AdminTfa\AdminTfaConfig;
use MageOS\PasskeyAuth\Model\AdminTfa\Engine;
use MageOS\PasskeyAuth\Model\WebAuthn\Ceremony;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\TwoFactorAuth\Api\UserConfigManagerInterface;
use Magento\User\Model\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\TrustPath\EmptyTrustPath;

class EngineTest extends TestCase
{
    private const USER_ID = 42;

    private UserConfigManagerInterface&MockObject $userConfigManager;
    private Ceremony&MockObject $ceremony;
    private AdminTfaConfig&MockObject $adminTfaConfig;
    private LoggerInterface&MockObject $logger;
    private User&MockObject $user;
    private Engine $engine;

    protected function setUp(): void
    {
        $this->userConfigManager = $this->createMock(UserConfigManagerInterface::class);
        $this->ceremony = $this->createMock(Ceremony::class);
        $this->adminTfaConfig = $this->createMock(AdminTfaConfig::class);
        $this->adminTfaConfig->method('getRpId')->willReturn('admin.example.com');
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->user = $this->createMock(User::class);
        $this->user->method('getId')->willReturn(self::USER_ID);
        $this->user->method('getUserName')->willReturn('admin');
        $this->user->method('getFirstName')->willReturn('Ada');
        $this->user->method('getLastName')->willReturn('Admin');

        $this->engine = new Engine($this->userConfigManager, $this->ceremony, $this->adminTfaConfig, $this->logger);
    }

    public function testIsEnabled(): void
    {
        $this->assertTrue($this->engine->isEnabled());
    }

    public function testGetRegistrationOptionsDescribesAdminUser(): void
    {
        $this->ceremony->expects($this->once())
            ->method('createRegistrationOptions')
            ->willReturnCallback(function (
                PublicKeyCredentialUserEntity $userEntity,
                array $exclude,
                string $type
            ): array {
                $this->assertSame('admin', $userEntity->name);
                $this->assertSame('Ada Admin', $userEntity->displayName);
                $this->assertSame(hash('sha256', (string) self::USER_ID), $userEntity->id);
                $this->assertSame([], $exclude);
                $this->assertSame('admin_registration', $type);
                return ['challengeToken' => 'tok'];
            });

        $this->assertSame(['challengeToken' => 'tok'], $this->engine->getRegistrationOptions($this->user));
    }

    public function testActivateStoresActiveCredential(): void
    {
        $source = $this->source('new-cred', 3);
        $this->ceremony->method('verifyRegistration')
            ->with('tok', '{"attestation":1}', 'admin_registration')
            ->willReturn($source);
        $this->ceremony->method('serializeSource')->with($source)->willReturn('{"source":1}');

        $this->userConfigManager->expects($this->once())
            ->method('setProviderConfig')
            ->willReturnCallback(function (int $userId, string $code, array $config): bool {
                $this->assertSame(self::USER_ID, $userId);
                $this->assertSame(Engine::CODE, $code);
                $this->assertTrue($config[UserConfigManagerInterface::ACTIVE_CONFIG_KEY]);
                $this->assertSame('{"source":1}', $config['registration']['credential_source']);
                $this->assertSame(base64_encode('new-cred'), $config['registration']['credential_id']);
                $this->assertSame('admin.example.com', $config['registration']['rp_id']);
                $this->assertSame(3, $config['registration']['sign_count']);
                return true;
            });

        $this->engine->activate($this->user, 'tok', '{"attestation":1}');
    }

    public function testActivateHidesLibraryErrorsBehindGenericMessage(): void
    {
        $this->ceremony->method('verifyRegistration')
            ->willThrowException(new \InvalidArgumentException('internal detail'));
        $this->userConfigManager->expects($this->never())->method('setProviderConfig');
        $this->logger->expects($this->once())->method('warning');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Passkey registration failed. Please try again.');

        $this->engine->activate($this->user, 'tok', '{}');
    }

    public function testActivatePassesThroughLocalizedErrors(): void
    {
        $this->ceremony->method('verifyRegistration')
            ->willThrowException(new LocalizedException(__('Challenge has expired.')));

        $this->expectExceptionMessage('Challenge has expired.');

        $this->engine->activate($this->user, 'tok', '{}');
    }

    public function testGetAuthenticationOptionsAllowsOnlyRegisteredCredential(): void
    {
        $this->givenProviderConfigs([Engine::CODE => $this->registration('cred-a')]);

        $this->ceremony->expects($this->once())
            ->method('createAuthenticationOptions')
            ->willReturnCallback(function (array $allow, string $type): array {
                $this->assertCount(1, $allow);
                $this->assertSame('cred-a', $allow[0]->id);
                $this->assertSame('admin_authentication', $type);
                return ['challengeToken' => 'tok'];
            });

        $this->assertSame(['challengeToken' => 'tok'], $this->engine->getAuthenticationOptions($this->user));
    }

    public function testGetAuthenticationOptionsThrowsWhenNotConfigured(): void
    {
        $this->givenProviderConfigs([]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('not configured');

        $this->engine->getAuthenticationOptions($this->user);
    }

    public function testGetAuthenticationOptionsThrowsWhenAdminDomainChanged(): void
    {
        $this->givenProviderConfigs([
            Engine::CODE => $this->registration('cred-a', 'old.example.com'),
        ]);
        $this->ceremony->expects($this->never())->method('createAuthenticationOptions');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('admin domain has changed');

        $this->engine->getAuthenticationOptions($this->user);
    }

    public function testVerifyUpdatesCounterAndKeepsActiveFlag(): void
    {
        $config = $this->registration('cred-a') + [UserConfigManagerInterface::ACTIVE_CONFIG_KEY => true];
        $this->givenProviderConfigs([Engine::CODE => $config]);
        $this->givenAssertionVerifies($this->source('cred-a', 8));
        $this->logger->expects($this->never())->method('warning');

        $this->userConfigManager->expects($this->once())
            ->method('setProviderConfig')
            ->willReturnCallback(function (int $userId, string $code, array $saved): bool {
                $this->assertSame(Engine::CODE, $code);
                $this->assertTrue($saved[UserConfigManagerInterface::ACTIVE_CONFIG_KEY]);
                $this->assertSame(8, $saved['registration']['sign_count']);
                $this->assertSame('{"updated":1}', $saved['registration']['credential_source']);
                $this->assertNotNull($saved['registration']['last_used_at']);
                return true;
            });

        $this->assertTrue($this->engine->verify($this->user, $this->assertionRequest()));
    }

    public function testVerifyWarnsOnCounterRegression(): void
    {
        $this->givenProviderConfigs([Engine::CODE => $this->registration('cred-a')]);
        $this->givenAssertionVerifies($this->source('cred-a', 4));

        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('sign count decreased'));

        $this->engine->verify($this->user, $this->assertionRequest());
    }

    public function testVerifyHidesLibraryErrorsBehindGenericMessage(): void
    {
        $this->givenProviderConfigs([Engine::CODE => $this->registration('cred-a')]);
        $this->ceremony->method('loadAssertion')->willThrowException(new \RuntimeException('bad signature'));
        $this->userConfigManager->expects($this->never())->method('setProviderConfig');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Passkey verification failed. Please try again.');

        $this->engine->verify($this->user, $this->assertionRequest());
    }

    public function testVerifyThrowsWhenNotConfigured(): void
    {
        $this->givenProviderConfigs([]);
        $this->ceremony->expects($this->never())->method('loadAssertion');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('not configured');

        $this->engine->verify($this->user, $this->assertionRequest());
    }

    private function givenProviderConfigs(array $configs): void
    {
        $this->userConfigManager->method('getProviderConfig')
            ->willReturnCallback(fn (int $userId, string $code): ?array => $configs[$code] ?? null);
    }

    private function givenAssertionVerifies(PublicKeyCredentialSource $updated): void
    {
        $credential = $this->createMock(PublicKeyCredential::class);
        $options = PublicKeyCredentialRequestOptions::create('challenge');
        $stored = $this->source('cred-a', 5);

        $this->ceremony->method('loadAssertion')
            ->with('tok', '{"assertion":1}', 'admin_authentication')
            ->willReturn([$credential, $options]);
        $this->ceremony->method('deserializeSource')->with('{"stored":1}')->willReturn($stored);
        $this->ceremony->method('verifyAssertion')->with($credential, $options, $stored)->willReturn($updated);
        $this->ceremony->method('serializeSource')->with($updated)->willReturn('{"updated":1}');
    }

    private function registration(string $credentialId, string $rpId = 'admin.example.com'): array
    {
        return ['registration' => [
            'credential_id' => base64_encode($credentialId),
            'credential_source' => '{"stored":1}',
            'rp_id' => $rpId,
            'sign_count' => 5,
        ]];
    }

    private function source(string $credentialId, int $counter): PublicKeyCredentialSource
    {
        return new PublicKeyCredentialSource(
            $credentialId,
            PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
            [],
            'none',
            EmptyTrustPath::create(),
            Uuid::fromString('00000000-0000-0000-0000-000000000000'),
            'public-key',
            'user-handle',
            $counter
        );
    }

    private function assertionRequest(): DataObject
    {
        return new DataObject(['challenge_token' => 'tok', 'credential' => '{"assertion":1}']);
    }
}
