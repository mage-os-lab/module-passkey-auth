<?php

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
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class OptionsGeneratorTest extends TestCase
{
    use MocksConfigTrait;
    use MocksCredentialRepositoryTrait;

    private CustomerRepositoryInterface&MockObject $customerRepositoryMock;
    private Ceremony&MockObject $ceremonyMock;
    private RateLimiter&MockObject $rateLimiterMock;
    private OptionsGenerator $optionsGenerator;

    protected function setUp(): void
    {
        $this->createConfigMock();
        $this->createCredentialRepositoryMock();

        $this->customerRepositoryMock = $this->createMock(CustomerRepositoryInterface::class);
        $this->ceremonyMock = $this->createMock(Ceremony::class);
        $this->rateLimiterMock = $this->createMock(RateLimiter::class);

        $storeStub = $this->createStub(StoreInterface::class);
        $storeStub->method('getWebsiteId')->willReturn('1');
        $storeManagerStub = $this->createStub(StoreManagerInterface::class);
        $storeManagerStub->method('getStore')->willReturn($storeStub);

        $remoteAddressStub = $this->createStub(RemoteAddress::class);
        $remoteAddressStub->method('getRemoteAddress')->willReturn('127.0.0.1');

        $this->optionsGenerator = new OptionsGenerator(
            $this->configMock,
            $this->customerRepositoryMock,
            $this->credentialRepositoryMock,
            $this->ceremonyMock,
            $storeManagerStub,
            new Json(),
            $this->rateLimiterMock,
            $remoteAddressStub
        );
    }

    public function testGenerateThrowsWhenDisabled(): void
    {
        $this->configureEnabled(false);
        $this->ceremonyMock->expects($this->never())->method('createAuthenticationOptions');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Passkey authentication is not enabled.');

        $this->optionsGenerator->generate('user@example.com');
    }

    public function testGenerateThrowsWhenRateLimited(): void
    {
        $this->configureEnabled(true);

        $this->rateLimiterMock->expects($this->once())
            ->method('checkOptionsRate')
            ->with('auth_user@example.com_127.0.0.1')
            ->willThrowException(new LocalizedException(__('Too many passkey requests. Please try again later.')));
        $this->ceremonyMock->expects($this->never())->method('createAuthenticationOptions');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Too many passkey requests. Please try again later.');

        $this->optionsGenerator->generate('user@example.com');
    }

    public function testGenerateRateLimitKeyForAnonymousRequest(): void
    {
        $this->configureEnabled(true);

        $this->rateLimiterMock->expects($this->once())
            ->method('checkOptionsRate')
            ->with('auth_anonymous_127.0.0.1');
        $this->ceremonyMock->method('createAuthenticationOptions')->willReturn(['challengeToken' => 't']);

        $this->optionsGenerator->generate();
    }

    public function testGenerateWithEmailCustomerFound(): void
    {
        $this->configureEnabled(true);

        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn('42');

        $this->customerRepositoryMock->expects($this->once())
            ->method('get')
            ->with('user@example.com', 1)
            ->willReturn($customer);

        $credential = $this->createMock(CredentialInterface::class);
        $credential->method('getCredentialId')->willReturn(base64_encode('cred-id-1'));
        $credential->method('getTransportsArray')->willReturn(['usb', 'nfc']);

        $this->configureGetByCustomerId(42, [$credential]);

        $capturedAllow = null;
        $this->ceremonyMock->expects($this->once())
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

        $decoded = json_decode($this->optionsGenerator->generate('user@example.com'), true);

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
        $this->credentialRepositoryMock->expects($this->never())->method('getByCustomerId');

        $this->ceremonyMock->expects($this->once())
            ->method('createAuthenticationOptions')
            ->with([], ChallengeManager::TYPE_AUTHENTICATION, null)
            ->willReturn([
                'challenge' => 'abc',
                'rpId' => 'example.com',
                'challengeToken' => 'token-for-unknown',
            ]);

        $decoded = json_decode($this->optionsGenerator->generate('nonexistent@example.com'), true);

        $this->assertNotNull($decoded, 'Anti-enumeration: should return valid JSON even for nonexistent email');
        $this->assertArrayHasKey('challenge', $decoded);
        $this->assertArrayHasKey('rpId', $decoded);
        $this->assertSame('token-for-unknown', $decoded['challengeToken']);
    }

    public function testGenerateWithoutEmail(): void
    {
        $this->configureEnabled(true);

        $this->customerRepositoryMock->expects($this->never())->method('get');
        $this->ceremonyMock->expects($this->once())
            ->method('createAuthenticationOptions')
            ->with([], ChallengeManager::TYPE_AUTHENTICATION, null)
            ->willReturn(['challenge' => 'abc', 'challengeToken' => 'token-no-email']);

        $decoded = json_decode($this->optionsGenerator->generate(null), true);

        $this->assertSame(['challenge' => 'abc', 'challengeToken' => 'token-no-email'], $decoded);
    }

    public function testGenerateMultipleCredentials(): void
    {
        $this->configureEnabled(true);

        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn(99);

        $this->customerRepositoryMock->method('get')
            ->with('multi@example.com', 1)
            ->willReturn($customer);

        $credential1 = $this->createMock(CredentialInterface::class);
        $credential1->method('getCredentialId')->willReturn(base64_encode('cred-aaa'));
        $credential1->method('getTransportsArray')->willReturn(['usb']);

        $credential2 = $this->createMock(CredentialInterface::class);
        $credential2->method('getCredentialId')->willReturn(base64_encode('cred-bbb'));
        $credential2->method('getTransportsArray')->willReturn(['internal', 'hybrid']);

        $this->configureGetByCustomerId(99, [$credential1, $credential2]);

        $capturedAllow = null;
        $this->ceremonyMock->expects($this->once())
            ->method('createAuthenticationOptions')
            ->willReturnCallback(function (array $allow, string $type, ?int $customerId) use (&$capturedAllow) {
                $this->assertSame(99, $customerId);
                $capturedAllow = $allow;
                return ['challengeToken' => 'multi-token'];
            });

        $this->optionsGenerator->generate('multi@example.com');

        $this->assertNotNull($capturedAllow);
        $this->assertCount(2, $capturedAllow);
        $this->assertSame('cred-aaa', $capturedAllow[0]->id);
        $this->assertSame(['usb'], $capturedAllow[0]->transports);
        $this->assertSame('cred-bbb', $capturedAllow[1]->id);
        $this->assertSame(['internal', 'hybrid'], $capturedAllow[1]->transports);
    }
}
