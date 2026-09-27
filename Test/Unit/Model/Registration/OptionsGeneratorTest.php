<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Model\Registration;

use MageOS\PasskeyAuth\Api\Data\CredentialInterface;
use MageOS\PasskeyAuth\Model\ChallengeManager;
use MageOS\PasskeyAuth\Model\RateLimiter;
use MageOS\PasskeyAuth\Model\Registration\OptionsGenerator;
use MageOS\PasskeyAuth\Model\UserHandleGenerator;
use MageOS\PasskeyAuth\Model\WebAuthn\Ceremony;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksConfigTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksCredentialRepositoryTrait;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Webauthn\PublicKeyCredentialUserEntity;

class OptionsGeneratorTest extends TestCase
{
    use MocksConfigTrait;
    use MocksCredentialRepositoryTrait;

    private CustomerRepositoryInterface&MockObject $customerRepositoryMock;
    private UserHandleGenerator&MockObject $userHandleGeneratorMock;
    private Ceremony&MockObject $ceremonyMock;
    private RateLimiter&MockObject $rateLimiterMock;
    private OptionsGenerator $optionsGenerator;

    protected function setUp(): void
    {
        $this->createConfigMock();
        $this->createCredentialRepositoryMock();

        $this->customerRepositoryMock = $this->createMock(CustomerRepositoryInterface::class);
        $this->userHandleGeneratorMock = $this->createMock(UserHandleGenerator::class);
        $this->ceremonyMock = $this->createMock(Ceremony::class);
        $this->rateLimiterMock = $this->createMock(RateLimiter::class);

        $this->optionsGenerator = new OptionsGenerator(
            $this->configMock,
            $this->customerRepositoryMock,
            $this->credentialRepositoryMock,
            $this->userHandleGeneratorMock,
            $this->ceremonyMock,
            new Json(),
            $this->rateLimiterMock
        );
    }

    private function configureCustomer(int $customerId): void
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getEmail')->willReturn('test@example.com');
        $customer->method('getFirstname')->willReturn('John');
        $customer->method('getLastname')->willReturn('Doe');
        $this->customerRepositoryMock->method('getById')
            ->with($customerId)
            ->willReturn($customer);
    }

    private function configureHappyPath(int $customerId, int $existingCount = 0, array $existing = []): void
    {
        $this->configureEnabled(true);
        $this->configureMaxCredentials(10);
        $this->configureCountByCustomerId($customerId, $existingCount);
        $this->configureGetByCustomerId($customerId, $existing);
        $this->configureCustomer($customerId);
        $this->userHandleGeneratorMock->method('getOrGenerate')
            ->with($customerId)
            ->willReturn('user-handle-bytes');
    }

    public function testGenerateThrowsWhenDisabled(): void
    {
        $this->configureEnabled(false);
        $this->ceremonyMock->expects($this->never())->method('createRegistrationOptions');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Passkey authentication is not enabled.');

        $this->optionsGenerator->generate(42);
    }

    public function testGenerateThrowsWhenRateLimited(): void
    {
        $this->configureEnabled(true);
        $this->rateLimiterMock->expects($this->once())
            ->method('checkOptionsRate')
            ->with('reg_42')
            ->willThrowException(new LocalizedException(__('Too many passkey requests. Please try again later.')));
        $this->ceremonyMock->expects($this->never())->method('createRegistrationOptions');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Too many passkey requests. Please try again later.');

        $this->optionsGenerator->generate(42);
    }

    public function testGenerateThrowsWhenMaxCredentialsReached(): void
    {
        $this->configureEnabled(true);
        $this->configureMaxCredentials(10);
        $this->configureCountByCustomerId(42, 10);
        $this->ceremonyMock->expects($this->never())->method('createRegistrationOptions');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Maximum number of passkeys (10) reached.');

        $this->optionsGenerator->generate(42);
    }

    public function testGenerateSucceedsUnderMaxCredentials(): void
    {
        $this->configureHappyPath(42, 9);
        $this->ceremonyMock->expects($this->once())
            ->method('createRegistrationOptions')
            ->willReturn(['challenge' => 'abc', 'challengeToken' => 'token123']);

        $result = $this->optionsGenerator->generate(42);

        $this->assertSame('{"challenge":"abc","challengeToken":"token123"}', $result);
    }

    public function testGeneratePassesUserEntityAndChallengeContext(): void
    {
        $customerId = 42;
        $this->configureHappyPath($customerId);

        $this->userHandleGeneratorMock->expects($this->once())
            ->method('getOrGenerate')
            ->with($customerId);

        $this->ceremonyMock->expects($this->once())
            ->method('createRegistrationOptions')
            ->with(
                $this->callback(function (PublicKeyCredentialUserEntity $user) {
                    return $user->name === 'test@example.com'
                        && $user->id === 'user-handle-bytes'
                        && $user->displayName === 'John Doe';
                }),
                [],
                ChallengeManager::TYPE_REGISTRATION,
                $customerId
            )
            ->willReturn(['challengeToken' => 'challenge-token-abc']);

        $this->optionsGenerator->generate($customerId);
    }

    public function testGenerateExcludesExistingCredentials(): void
    {
        $customerId = 42;

        $cred1 = $this->createMock(CredentialInterface::class);
        $cred1->method('getCredentialId')->willReturn(base64_encode('cred-id-1'));
        $cred1->method('getTransportsArray')->willReturn(['usb', 'nfc']);

        $cred2 = $this->createMock(CredentialInterface::class);
        $cred2->method('getCredentialId')->willReturn(base64_encode('cred-id-2'));
        $cred2->method('getTransportsArray')->willReturn(['internal']);

        $this->configureHappyPath($customerId, 2, [$cred1, $cred2]);

        $capturedExclude = null;
        $this->ceremonyMock->expects($this->once())
            ->method('createRegistrationOptions')
            ->willReturnCallback(function ($user, array $exclude) use (&$capturedExclude) {
                $capturedExclude = $exclude;
                return ['challengeToken' => 'token-xyz'];
            });

        $this->optionsGenerator->generate($customerId);

        $this->assertNotNull($capturedExclude);
        $this->assertCount(2, $capturedExclude);
        $this->assertSame('public-key', $capturedExclude[0]->type);
        $this->assertSame('cred-id-1', $capturedExclude[0]->id);
        $this->assertSame(['usb', 'nfc'], $capturedExclude[0]->transports);
        $this->assertSame('cred-id-2', $capturedExclude[1]->id);
        $this->assertSame(['internal'], $capturedExclude[1]->transports);
    }

    public function testGenerateReturnsJsonWithChallengeToken(): void
    {
        $this->configureHappyPath(42);

        $this->ceremonyMock->expects($this->once())
            ->method('createRegistrationOptions')
            ->willReturn([
                'rp' => ['id' => 'example.com', 'name' => 'Test Store'],
                'challengeToken' => 'my-challenge-token',
            ]);

        $decoded = json_decode($this->optionsGenerator->generate(42), true);

        $this->assertSame('my-challenge-token', $decoded['challengeToken']);
        $this->assertSame(['id' => 'example.com', 'name' => 'Test Store'], $decoded['rp']);
    }
}
