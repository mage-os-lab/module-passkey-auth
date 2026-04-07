<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Model\AdminTfa;

use MageOS\PasskeyAuth\Api\AdminTfa\AuthenticateInterface;
use MageOS\PasskeyAuth\Model\AdminTfa\Engine;
use MageOS\PasskeyAuth\Model\AdminTfa\OriginValidator;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\TwoFactorAuth\Api\UserConfigManagerInterface;
use Magento\User\Api\Data\UserInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class EngineTest extends TestCase
{
    private UserConfigManagerInterface&MockObject $userConfigManager;
    private OriginValidator&MockObject $originValidator;
    private AuthenticateInterface&MockObject $authenticate;
    private Engine $engine;

    protected function setUp(): void
    {
        $this->userConfigManager = $this->createMock(UserConfigManagerInterface::class);
        $this->originValidator = $this->createMock(OriginValidator::class);
        $this->authenticate = $this->createMock(AuthenticateInterface::class);

        $this->engine = new Engine(
            $this->userConfigManager,
            $this->originValidator,
            $this->authenticate,
            'all'
        );
    }

    public function testIsEnabledReturnsTrue(): void
    {
        $this->assertTrue($this->engine->isEnabled());
    }

    public function testVerifyDelegatesToAuthenticate(): void
    {
        $user = $this->createMock(UserInterface::class);
        $user->method('getId')->willReturn('42');
        $request = new DataObject(['credential' => '{"id":"abc"}', 'challenge_token' => 'tok123']);

        $this->userConfigManager->method('getProviderConfig')
            ->with(42, 'passkey')
            ->willReturn(['registration' => ['rp_id' => 'admin.example.com']]);

        $this->originValidator->expects($this->once())
            ->method('validate')
            ->with(['rp_id' => 'admin.example.com']);

        $this->authenticate->expects($this->once())
            ->method('verifyAssertion')
            ->with($user, $request)
            ->willReturn(true);

        $this->assertTrue($this->engine->verify($user, $request));
    }

    public function testVerifyThrowsOnDomainMismatch(): void
    {
        $user = $this->createMock(UserInterface::class);
        $user->method('getId')->willReturn('42');
        $request = new DataObject();

        $this->userConfigManager->method('getProviderConfig')
            ->willReturn(['registration' => ['rp_id' => 'old.example.com']]);

        $this->originValidator->method('validate')
            ->willThrowException(new LocalizedException(__('domain has changed')));

        $this->expectException(LocalizedException::class);
        $this->engine->verify($user, $request);
    }

    public function testVerifyThrowsWhenNotConfigured(): void
    {
        $user = $this->createMock(UserInterface::class);
        $user->method('getId')->willReturn('42');
        $request = new DataObject();

        $this->userConfigManager->method('getProviderConfig')
            ->willReturn(null);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('not configured');
        $this->engine->verify($user, $request);
    }

    public function testGetAuthenticatorPolicyReturnsConstructorValue(): void
    {
        $this->assertSame('all', $this->engine->getAuthenticatorPolicy());
    }
}
