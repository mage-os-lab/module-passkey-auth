<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Model\Authentication;

use MageOS\PasskeyAuth\Api\Data\CredentialInterface;
use MageOS\PasskeyAuth\Model\Authentication\OptionsGenerator;
use MageOS\PasskeyAuth\Model\ChallengeManager;
use MageOS\PasskeyAuth\Model\RateLimiter;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksChallengeManagerTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksConfigTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksCredentialRepositoryTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksSerializerFactoryTrait;
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
    use MocksChallengeManagerTrait;
    use MocksSerializerFactoryTrait;

    private CustomerRepositoryInterface&MockObject $customerRepositoryMock;
    private StoreManagerInterface&MockObject $storeManagerMock;
    private Json&MockObject $jsonMock;
    private RateLimiter&MockObject $rateLimiterMock;
    private RemoteAddress&MockObject $remoteAddressMock;
    private OptionsGenerator $optionsGenerator;

    protected function setUp(): void
    {
        $this->createConfigMock();
        $this->createCredentialRepositoryMock();
        $this->createChallengeManagerMock();
        $this->createSerializerFactoryMock();

        $this->customerRepositoryMock = $this->createMock(CustomerRepositoryInterface::class);
        $this->rateLimiterMock = $this->createMock(RateLimiter::class);

        $storeMock = $this->getMockBuilder(StoreInterface::class)
            ->onlyMethods(['getWebsiteId'])
            ->addMethods(['getBaseUrl'])
            ->getMockForAbstractClass();
        $storeMock->method('getWebsiteId')->willReturn('1');
        $storeMock->method('getBaseUrl')->willReturn('https://example.com/');

        $this->storeManagerMock = $this->createMock(StoreManagerInterface::class);
        $this->storeManagerMock->method('getStore')->willReturn($storeMock);

        $this->jsonMock = $this->createMock(Json::class);

        $this->remoteAddressMock = $this->createMock(RemoteAddress::class);
        $this->remoteAddressMock->method('getRemoteAddress')->willReturn('127.0.0.1');

        $this->configMock->method('getRpId')->willReturn('example.com');
        $this->configMock->method('getUserVerification')->willReturn('preferred');
        $this->configMock->method('getCeremonyTimeout')->willReturn(60000);

        $this->optionsGenerator = new OptionsGenerator(
            $this->configMock,
            $this->customerRepositoryMock,
            $this->credentialRepositoryMock,
            $this->challengeManagerMock,
            $this->serializerFactoryMock,
            $this->storeManagerMock,
            $this->jsonMock,
            $this->rateLimiterMock,
            $this->remoteAddressMock
        );
    }

    public function testGenerateThrowsWhenDisabled(): void
    {
        $this->configureEnabled(false);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Passkey authentication is not enabled.');

        $this->optionsGenerator->generate('user@example.com');
    }

    public function testGenerateThrowsWhenRateLimited(): void
    {
        $this->configureEnabled(true);

        $this->rateLimiterMock->method('checkOptionsRate')
            ->willThrowException(new LocalizedException(__('Too many passkey requests. Please try again later.')));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Too many passkey requests. Please try again later.');

        $this->optionsGenerator->generate('user@example.com');
    }

    public function testGenerateWithEmailCustomerFound(): void
    {
        $this->configureEnabled(true);

        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn(42);

        $this->customerRepositoryMock->method('get')
            ->with('user@example.com', 1)
            ->willReturn($customer);

        $credential = $this->createMock(CredentialInterface::class);
        $credential->method('getCredentialId')->willReturn(base64_encode('cred-id-1'));
        $credential->method('getTransportsArray')->willReturn(['usb', 'nfc']);

        $this->configureGetByCustomerId(42, [$credential]);

        $serializedOptions = '{"challenge":"abc","rpId":"example.com","allowCredentials":[{"type":"public-key","id":"Y3JlZC1pZC0x"}]}';
        $this->serializerMock->method('serialize')->willReturn($serializedOptions);

        $this->configureCreateChallenge('test-challenge-token');

        $optionsArray = [
            'challenge' => 'abc',
            'rpId' => 'example.com',
            'allowCredentials' => [
                ['type' => 'public-key', 'id' => 'Y3JlZC1pZC0x'],
            ],
        ];
        $this->jsonMock->method('unserialize')
            ->with($serializedOptions)
            ->willReturn($optionsArray);

        $expectedOutput = $optionsArray;
        $expectedOutput['challengeToken'] = 'test-challenge-token';
        $this->jsonMock->method('serialize')
            ->with($expectedOutput)
            ->willReturn(json_encode($expectedOutput));

        $result = $this->optionsGenerator->generate('user@example.com');
        $decoded = json_decode($result, true);

        $this->assertNotNull($decoded);
        $this->assertArrayHasKey('allowCredentials', $decoded);
        $this->assertCount(1, $decoded['allowCredentials']);
        $this->assertEquals('public-key', $decoded['allowCredentials'][0]['type']);
    }

    public function testGenerateWithEmailCustomerNotFound(): void
    {
        $this->configureEnabled(true);

        $this->customerRepositoryMock->method('get')
            ->willThrowException(new NoSuchEntityException(__('No such entity.')));

        $serializedOptions = '{"challenge":"abc","rpId":"example.com","allowCredentials":[]}';
        $this->serializerMock->method('serialize')->willReturn($serializedOptions);

        $this->configureCreateChallenge('token-for-unknown');

        $optionsArray = [
            'challenge' => 'abc',
            'rpId' => 'example.com',
            'allowCredentials' => [],
        ];
        $this->jsonMock->method('unserialize')
            ->with($serializedOptions)
            ->willReturn($optionsArray);

        $expectedOutput = $optionsArray;
        $expectedOutput['challengeToken'] = 'token-for-unknown';
        $this->jsonMock->method('serialize')
            ->with($expectedOutput)
            ->willReturn(json_encode($expectedOutput));

        $result = $this->optionsGenerator->generate('nonexistent@example.com');
        $decoded = json_decode($result, true);

        $this->assertNotNull($decoded, 'Anti-enumeration: should return valid JSON even for nonexistent email');
        $this->assertArrayHasKey('challenge', $decoded);
        $this->assertArrayHasKey('rpId', $decoded);
    }

    public function testGenerateWithoutEmail(): void
    {
        $this->configureEnabled(true);

        $serializedOptions = '{"challenge":"abc","rpId":"example.com","allowCredentials":[]}';
        $this->serializerMock->method('serialize')->willReturn($serializedOptions);

        $this->configureCreateChallenge('token-no-email');

        $optionsArray = [
            'challenge' => 'abc',
            'rpId' => 'example.com',
            'allowCredentials' => [],
        ];
        $this->jsonMock->method('unserialize')
            ->with($serializedOptions)
            ->willReturn($optionsArray);

        $expectedOutput = $optionsArray;
        $expectedOutput['challengeToken'] = 'token-no-email';
        $this->jsonMock->method('serialize')
            ->with($expectedOutput)
            ->willReturn(json_encode($expectedOutput));

        $result = $this->optionsGenerator->generate(null);
        $decoded = json_decode($result, true);

        $this->assertNotNull($decoded);
        $this->assertEmpty($decoded['allowCredentials']);
    }

    public function testGenerateCreatesChallengeRecord(): void
    {
        $this->configureEnabled(true);

        $serializedOptions = '{"challenge":"xyz"}';
        $this->serializerMock->method('serialize')->willReturn($serializedOptions);

        $this->challengeManagerMock->expects($this->once())
            ->method('create')
            ->with(
                ChallengeManager::TYPE_AUTHENTICATION,
                $serializedOptions,
                null
            )
            ->willReturn('challenge-token-123');

        $this->jsonMock->method('unserialize')->willReturn(['challenge' => 'xyz']);
        $this->jsonMock->method('serialize')->willReturn('{"challenge":"xyz","challengeToken":"challenge-token-123"}');

        $this->optionsGenerator->generate(null);
    }

    public function testGenerateReturnsJsonWithChallengeToken(): void
    {
        $this->configureEnabled(true);

        $serializedOptions = '{"challenge":"test"}';
        $this->serializerMock->method('serialize')->willReturn($serializedOptions);

        $this->configureCreateChallenge('my-token-abc');

        $optionsArray = ['challenge' => 'test'];
        $this->jsonMock->method('unserialize')
            ->with($serializedOptions)
            ->willReturn($optionsArray);

        $expectedOutput = ['challenge' => 'test', 'challengeToken' => 'my-token-abc'];
        $this->jsonMock->method('serialize')
            ->with($expectedOutput)
            ->willReturn('{"challenge":"test","challengeToken":"my-token-abc"}');

        $result = $this->optionsGenerator->generate();
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('challengeToken', $decoded);
        $this->assertEquals('my-token-abc', $decoded['challengeToken']);
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

        $serializedOptions = '{"challenge":"c","rpId":"example.com","allowCredentials":[{"type":"public-key","id":"a"},{"type":"public-key","id":"b"}]}';
        $this->serializerMock->method('serialize')->willReturn($serializedOptions);

        $this->configureCreateChallenge('multi-token');

        $optionsArray = [
            'challenge' => 'c',
            'rpId' => 'example.com',
            'allowCredentials' => [
                ['type' => 'public-key', 'id' => 'a'],
                ['type' => 'public-key', 'id' => 'b'],
            ],
        ];
        $this->jsonMock->method('unserialize')
            ->with($serializedOptions)
            ->willReturn($optionsArray);

        $expectedOutput = $optionsArray;
        $expectedOutput['challengeToken'] = 'multi-token';
        $this->jsonMock->method('serialize')
            ->with($expectedOutput)
            ->willReturn(json_encode($expectedOutput));

        $result = $this->optionsGenerator->generate('multi@example.com');
        $decoded = json_decode($result, true);

        $this->assertNotNull($decoded);
        $this->assertCount(2, $decoded['allowCredentials']);
        $this->assertEquals('public-key', $decoded['allowCredentials'][0]['type']);
        $this->assertEquals('public-key', $decoded['allowCredentials'][1]['type']);
    }
}
