<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Model\Authentication;

use MageOS\PasskeyAuth\Api\Data\CredentialInterface;
use MageOS\PasskeyAuth\Model\Authentication\OptionsGenerator;
use MageOS\PasskeyAuth\Model\ChallengeManager;
use MageOS\PasskeyAuth\Model\RateLimiter;
use MageOS\PasskeyAuth\Model\WebAuthn\Ceremony;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksConfigTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksCredentialRepositoryTrait;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class OptionsGeneratorTest extends TestCase
{
    use MocksConfigTrait;
    use MocksCredentialRepositoryTrait;

    private CustomerRepositoryInterface&Stub $customerRepositoryMock;
    private Ceremony&Stub $ceremonyMock;
    private RateLimiter&Stub $rateLimiterMock;
    private bool $customerRepositoryIsMock = false;
    private bool $ceremonyIsMock = false;
    private bool $rateLimiterIsMock = false;
    private StoreManagerInterface&Stub $storeManagerStub;
    private RemoteAddress&Stub $remoteAddressStub;
    private EncryptorInterface&Stub $encryptorStub;
    private ?OptionsGenerator $optionsGenerator = null;

    protected function setUp(): void
    {
        $this->createConfigStub();
        $this->createCredentialRepositoryStub();

        $this->customerRepositoryMock = $this->createStub(CustomerRepositoryInterface::class);
        $this->ceremonyMock = $this->createStub(Ceremony::class);
        $this->rateLimiterMock = $this->createStub(RateLimiter::class);

        $storeStub = $this->createStub(StoreInterface::class);
        $storeStub->method('getWebsiteId')->willReturn('1');
        $this->storeManagerStub = $this->createStub(StoreManagerInterface::class);
        $this->storeManagerStub->method('getStore')->willReturn($storeStub);

        $this->remoteAddressStub = $this->createStub(RemoteAddress::class);
        $this->remoteAddressStub->method('getRemoteAddress')->willReturn('127.0.0.1');

        $this->encryptorStub = $this->createStub(EncryptorInterface::class);
        $this->encryptorStub->method('hash')
            ->willReturnCallback(fn (string $data) => hash_hmac('sha256', $data, 'key'));
    }

    /**
     * Build the subject lazily, so tests can first replace stubs with mocks.
     */
    private function optionsGenerator(): OptionsGenerator
    {
        return $this->optionsGenerator ??= new OptionsGenerator(
            $this->configMock,
            $this->customerRepositoryMock,
            $this->credentialRepositoryMock,
            $this->ceremonyMock,
            $this->storeManagerStub,
            new Json(),
            $this->rateLimiterMock,
            $this->remoteAddressStub,
            $this->encryptorStub
        );
    }

    private function mockCustomerRepository(): CustomerRepositoryInterface&MockObject
    {
        if (!$this->customerRepositoryIsMock) {
            $this->customerRepositoryMock = $this->createMock(CustomerRepositoryInterface::class);
            $this->customerRepositoryIsMock = true;
        }
        return $this->customerRepositoryMock;
    }

    private function mockCeremony(): Ceremony&MockObject
    {
        if (!$this->ceremonyIsMock) {
            $this->ceremonyMock = $this->createMock(Ceremony::class);
            $this->ceremonyIsMock = true;
        }
        return $this->ceremonyMock;
    }

    private function mockRateLimiter(): RateLimiter&MockObject
    {
        if (!$this->rateLimiterIsMock) {
            $this->rateLimiterMock = $this->createMock(RateLimiter::class);
            $this->rateLimiterIsMock = true;
        }
        return $this->rateLimiterMock;
    }

    public function testGenerateThrowsWhenDisabled(): void
    {
        $this->configureEnabled(false);
        $this->mockCeremony()->expects($this->never())->method('createAuthenticationOptions');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Passkey authentication is not enabled.');

        $this->optionsGenerator()->generate('user@example.com');
    }

    public function testGenerateThrowsWhenRateLimited(): void
    {
        $this->configureEnabled(true);

        $this->mockRateLimiter()->expects($this->once())
            ->method('checkOptionsRate')
            ->with('auth_user@example.com_127.0.0.1')
            ->willThrowException(new LocalizedException(__('Too many passkey requests. Please try again later.')));
        $this->mockCeremony()->expects($this->never())->method('createAuthenticationOptions');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Too many passkey requests. Please try again later.');

        $this->optionsGenerator()->generate('user@example.com');
    }

    public function testGenerateRateLimitKeyForAnonymousRequest(): void
    {
        $this->configureEnabled(true);

        $this->mockRateLimiter()->expects($this->once())
            ->method('checkOptionsRate')
            ->with('auth_anonymous_127.0.0.1');
        $this->ceremonyMock->method('createAuthenticationOptions')->willReturn(['challengeToken' => 't']);

        $this->optionsGenerator()->generate();
    }

    public function testGenerateWithEmailCustomerFound(): void
    {
        $this->configureEnabled(true);

        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn('42');

        $this->mockCustomerRepository()->expects($this->once())
            ->method('get')
            ->with('user@example.com', 1)
            ->willReturn($customer);

        $credential = $this->createStub(CredentialInterface::class);
        $credential->method('getCredentialId')->willReturn(base64_encode('cred-id-1'));
        $credential->method('getTransportsArray')->willReturn(['usb', 'nfc']);

        $this->configureGetByCustomerId(42, [$credential]);

        $capturedAllow = null;
        $this->mockCeremony()->expects($this->once())
            ->method('createAuthenticationOptions')
            ->willReturnCallback(function (array $allow, string $type, ?int $customerId) use (&$capturedAllow) {
                $this->assertSame(ChallengeManager::TYPE_AUTHENTICATION, $type);
                $this->assertSame(42, $customerId);
                $capturedAllow = $allow;
                return [
                    'challenge' => 'abc',
                    'allowCredentials' => [['type' => 'public-key', 'id' => 'Y3JlZC1pZC0x']],
                    'challengeToken' => 'test-challenge-token',
                ];
            });

        $decoded = json_decode($this->optionsGenerator()->generate('user@example.com'), true);

        $this->assertNotNull($capturedAllow);
        $this->assertCount(1, $capturedAllow);
        $this->assertSame('public-key', $capturedAllow[0]->type);
        $this->assertSame('cred-id-1', $capturedAllow[0]->id);
        $this->assertSame(['usb', 'nfc'], $capturedAllow[0]->transports);

        $this->assertCount(1, $decoded['allowCredentials']);
        $this->assertSame('test-challenge-token', $decoded['challengeToken']);
    }

    public function testGenerateWithEmailCustomerNotFound(): void
    {
        $this->configureEnabled(true);

        $this->customerRepositoryMock->method('get')
            ->willThrowException(new NoSuchEntityException(__('No such entity.')));
        $this->mockCredentialRepository()->expects($this->never())->method('getByCustomerId');

        $this->mockCeremony()->expects($this->once())
            ->method('createAuthenticationOptions')
            ->with(
                $this->callback(fn (array $allow) => count($allow) === 1
                    && $allow[0]->id === hex2bin(hash_hmac('sha256', 'passkey-decoy|nonexistent@example.com', 'key'))),
                ChallengeManager::TYPE_AUTHENTICATION,
                null
            )
            ->willReturn([
                'challenge' => 'abc',
                'rpId' => 'example.com',
                'challengeToken' => 'token-for-unknown',
            ]);

        $decoded = json_decode($this->optionsGenerator()->generate(' Nonexistent@Example.com'), true);

        $this->assertNotNull($decoded, 'Anti-enumeration: should return valid JSON even for nonexistent email');
        $this->assertArrayHasKey('challenge', $decoded);
        $this->assertArrayHasKey('rpId', $decoded);
        $this->assertSame('token-for-unknown', $decoded['challengeToken']);
    }

    public function testGenerateWithEmailCustomerWithoutPasskeysGetsDecoy(): void
    {
        $this->configureEnabled(true);

        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn(42);
        $this->customerRepositoryMock->method('get')->willReturn($customer);
        $this->credentialRepositoryMock->method('getByCustomerId')->willReturn([]);

        $this->mockCeremony()->expects($this->once())
            ->method('createAuthenticationOptions')
            ->with(
                $this->callback(fn (array $allow) => count($allow) === 1
                    && $allow[0]->id === hex2bin(hash_hmac('sha256', 'passkey-decoy|jane@example.com', 'key'))
                    && $allow[0]->transports === ['hybrid', 'internal']),
                ChallengeManager::TYPE_AUTHENTICATION,
                42
            )
            ->willReturn(['challenge' => 'abc', 'challengeToken' => 't']);

        $this->optionsGenerator()->generate('jane@example.com');
    }

    public function testGenerateWithoutEmail(): void
    {
        $this->configureEnabled(true);

        $this->mockCustomerRepository()->expects($this->never())->method('get');
        $this->mockCeremony()->expects($this->once())
            ->method('createAuthenticationOptions')
            ->with([], ChallengeManager::TYPE_AUTHENTICATION, null)
            ->willReturn(['challenge' => 'abc', 'challengeToken' => 'token-no-email']);

        $decoded = json_decode($this->optionsGenerator()->generate(null), true);

        $this->assertSame(['challenge' => 'abc', 'challengeToken' => 'token-no-email'], $decoded);
    }

    public function testGenerateMultipleCredentials(): void
    {
        $this->configureEnabled(true);

        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn(99);

        $this->mockCustomerRepository()->method('get')
            ->with('multi@example.com', 1)
            ->willReturn($customer);

        $credential1 = $this->createStub(CredentialInterface::class);
        $credential1->method('getCredentialId')->willReturn(base64_encode('cred-aaa'));
        $credential1->method('getTransportsArray')->willReturn(['usb']);

        $credential2 = $this->createStub(CredentialInterface::class);
        $credential2->method('getCredentialId')->willReturn(base64_encode('cred-bbb'));
        $credential2->method('getTransportsArray')->willReturn(['internal', 'hybrid']);

        $this->configureGetByCustomerId(99, [$credential1, $credential2]);

        $capturedAllow = null;
        $this->mockCeremony()->expects($this->once())
            ->method('createAuthenticationOptions')
            ->willReturnCallback(function (array $allow, string $type, ?int $customerId) use (&$capturedAllow) {
                $this->assertSame(99, $customerId);
                $capturedAllow = $allow;
                return ['challengeToken' => 'multi-token'];
            });

        $this->optionsGenerator()->generate('multi@example.com');

        $this->assertNotNull($capturedAllow);
        $this->assertCount(2, $capturedAllow);
        $this->assertSame('cred-aaa', $capturedAllow[0]->id);
        $this->assertSame(['usb'], $capturedAllow[0]->transports);
        $this->assertSame('cred-bbb', $capturedAllow[1]->id);
        $this->assertSame(['internal', 'hybrid'], $capturedAllow[1]->transports);
    }
}
