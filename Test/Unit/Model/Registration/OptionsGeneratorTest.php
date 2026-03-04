<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Model\Registration;

use MageOS\PasskeyAuth\Api\Data\CredentialInterface;
use MageOS\PasskeyAuth\Model\ChallengeManager;
use MageOS\PasskeyAuth\Model\RateLimiter;
use MageOS\PasskeyAuth\Model\Registration\OptionsGenerator;
use MageOS\PasskeyAuth\Model\UserHandleGenerator;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksChallengeManagerTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksConfigTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksCredentialRepositoryTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksSerializerFactoryTrait;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class OptionsGeneratorTest extends TestCase
{
    use MocksConfigTrait;
    use MocksCredentialRepositoryTrait;
    use MocksChallengeManagerTrait;
    use MocksSerializerFactoryTrait;

    private CustomerRepositoryInterface&MockObject $customerRepositoryMock;
    private UserHandleGenerator&MockObject $userHandleGeneratorMock;
    private Json&MockObject $jsonMock;
    private RateLimiter&MockObject $rateLimiterMock;
    private OptionsGenerator $optionsGenerator;

    protected function setUp(): void
    {
        $this->createConfigMock();
        $this->createCredentialRepositoryMock();
        $this->createChallengeManagerMock();
        $this->createSerializerFactoryMock();

        $this->customerRepositoryMock = $this->createMock(CustomerRepositoryInterface::class);
        $this->userHandleGeneratorMock = $this->createMock(UserHandleGenerator::class);
        $this->jsonMock = $this->createMock(Json::class);
        $this->rateLimiterMock = $this->createMock(RateLimiter::class);

        $this->optionsGenerator = new OptionsGenerator(
            $this->configMock,
            $this->customerRepositoryMock,
            $this->credentialRepositoryMock,
            $this->userHandleGeneratorMock,
            $this->challengeManagerMock,
            $this->serializerFactoryMock,
            $this->jsonMock,
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

    private function configureDefaultHappyPath(int $customerId): void
    {
        $this->configureEnabled(true);
        $this->configureMaxCredentials(10);
        $this->configureCountByCustomerId($customerId, 0);
        $this->configureGetByCustomerId($customerId, []);
        $this->configureCustomer($customerId);
        $this->userHandleGeneratorMock->method('getOrGenerate')
            ->with($customerId)
            ->willReturn('dXNlci1oYW5kbGU=');

        $this->configMock->method('getRpName')->willReturn('Test Store');
        $this->configMock->method('getRpId')->willReturn('example.com');
        $this->configMock->method('getAuthenticatorAttachment')->willReturn(null);
        $this->configMock->method('getUserVerification')->willReturn('preferred');
        $this->configMock->method('getAttestationConveyance')->willReturn('none');
        $this->configMock->method('getCeremonyTimeout')->willReturn(60000);

        $this->serializerMock->method('serialize')->willReturn('{"serialized":"options"}');
        $this->configureCreateChallenge('challenge-token-abc');

        $this->jsonMock->method('unserialize')
            ->with('{"serialized":"options"}')
            ->willReturn(['serialized' => 'options']);
        $this->jsonMock->method('serialize')
            ->willReturn('{"serialized":"options","challengeToken":"challenge-token-abc"}');
    }

    public function testGenerateThrowsWhenDisabled(): void
    {
        $this->configureEnabled(false);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Passkey authentication is not enabled.');

        $this->optionsGenerator->generate(42);
    }

    public function testGenerateThrowsWhenRateLimited(): void
    {
        $this->configureEnabled(true);
        $this->rateLimiterMock->method('checkOptionsRate')
            ->with('reg_42')
            ->willThrowException(new LocalizedException(__('Too many passkey requests. Please try again later.')));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Too many passkey requests. Please try again later.');

        $this->optionsGenerator->generate(42);
    }

    public function testGenerateThrowsWhenMaxCredentialsReached(): void
    {
        $this->configureEnabled(true);
        $this->configureMaxCredentials(10);
        $this->configureCountByCustomerId(42, 10);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Maximum number of passkeys (10) reached.');

        $this->optionsGenerator->generate(42);
    }

    public function testGenerateSucceedsUnderMaxCredentials(): void
    {
        $customerId = 42;
        $this->configureEnabled(true);
        $this->configureMaxCredentials(10);
        $this->configureCountByCustomerId($customerId, 9);
        $this->configureGetByCustomerId($customerId, []);
        $this->configureCustomer($customerId);
        $this->userHandleGeneratorMock->method('getOrGenerate')->willReturn('dXNlci1oYW5kbGU=');
        $this->configMock->method('getRpName')->willReturn('Test Store');
        $this->configMock->method('getRpId')->willReturn('example.com');
        $this->configMock->method('getAuthenticatorAttachment')->willReturn(null);
        $this->configMock->method('getUserVerification')->willReturn('preferred');
        $this->configMock->method('getAttestationConveyance')->willReturn('none');
        $this->configMock->method('getCeremonyTimeout')->willReturn(60000);

        $this->serializerMock->method('serialize')->willReturn('{"options":"data"}');
        $this->configureCreateChallenge('token123');
        $this->jsonMock->method('unserialize')->willReturn(['options' => 'data']);
        $this->jsonMock->method('serialize')->willReturn('{"options":"data","challengeToken":"token123"}');

        $result = $this->optionsGenerator->generate($customerId);

        $this->assertSame('{"options":"data","challengeToken":"token123"}', $result);
    }

    public function testGenerateExcludesExistingCredentials(): void
    {
        $customerId = 42;
        $this->configureEnabled(true);
        $this->configureMaxCredentials(10);
        $this->configureCountByCustomerId($customerId, 2);
        $this->configureCustomer($customerId);
        $this->userHandleGeneratorMock->method('getOrGenerate')->willReturn('dXNlci1oYW5kbGU=');
        $this->configMock->method('getRpName')->willReturn('Test Store');
        $this->configMock->method('getRpId')->willReturn('example.com');
        $this->configMock->method('getAuthenticatorAttachment')->willReturn(null);
        $this->configMock->method('getUserVerification')->willReturn('preferred');
        $this->configMock->method('getAttestationConveyance')->willReturn('none');
        $this->configMock->method('getCeremonyTimeout')->willReturn(60000);

        $cred1 = $this->createMock(CredentialInterface::class);
        $cred1->method('getCredentialId')->willReturn(base64_encode('cred-id-1'));
        $cred1->method('getTransportsArray')->willReturn(['usb', 'nfc']);

        $cred2 = $this->createMock(CredentialInterface::class);
        $cred2->method('getCredentialId')->willReturn(base64_encode('cred-id-2'));
        $cred2->method('getTransportsArray')->willReturn(['internal']);

        $this->configureGetByCustomerId($customerId, [$cred1, $cred2]);

        $capturedOptions = null;
        $this->serializerMock->method('serialize')
            ->willReturnCallback(function ($object) use (&$capturedOptions) {
                $capturedOptions = $object;
                return '{"serialized":"with-excludes"}';
            });

        $this->configureCreateChallenge('token-xyz');
        $this->jsonMock->method('unserialize')->willReturn(['serialized' => 'with-excludes']);
        $this->jsonMock->method('serialize')->willReturn('{"serialized":"with-excludes","challengeToken":"token-xyz"}');

        $this->optionsGenerator->generate($customerId);

        $this->assertNotNull($capturedOptions);
        $excludeCredentials = $capturedOptions->excludeCredentials;
        $this->assertCount(2, $excludeCredentials);
        $this->assertSame('cred-id-1', $excludeCredentials[0]->id);
        $this->assertSame(['usb', 'nfc'], $excludeCredentials[0]->transports);
        $this->assertSame('cred-id-2', $excludeCredentials[1]->id);
        $this->assertSame(['internal'], $excludeCredentials[1]->transports);
    }

    public function testGenerateCreatesChallengeWithCustomerId(): void
    {
        $customerId = 42;
        $this->configureDefaultHappyPath($customerId);

        $this->challengeManagerMock->expects($this->once())
            ->method('create')
            ->with(
                ChallengeManager::TYPE_REGISTRATION,
                $this->isType('string'),
                $customerId
            )
            ->willReturn('challenge-token-abc');

        $this->optionsGenerator->generate($customerId);
    }

    public function testGenerateReturnsJsonWithChallengeToken(): void
    {
        $customerId = 42;
        $this->configureEnabled(true);
        $this->configureMaxCredentials(10);
        $this->configureCountByCustomerId($customerId, 0);
        $this->configureGetByCustomerId($customerId, []);
        $this->configureCustomer($customerId);
        $this->userHandleGeneratorMock->method('getOrGenerate')->willReturn('dXNlci1oYW5kbGU=');
        $this->configMock->method('getRpName')->willReturn('Test Store');
        $this->configMock->method('getRpId')->willReturn('example.com');
        $this->configMock->method('getAuthenticatorAttachment')->willReturn(null);
        $this->configMock->method('getUserVerification')->willReturn('preferred');
        $this->configMock->method('getAttestationConveyance')->willReturn('none');
        $this->configMock->method('getCeremonyTimeout')->willReturn(60000);

        $this->serializerMock->method('serialize')->willReturn('{"rp":"entity"}');
        $this->configureCreateChallenge('my-challenge-token');

        $this->jsonMock->method('unserialize')
            ->with('{"rp":"entity"}')
            ->willReturn(['rp' => 'entity']);

        $capturedArray = null;
        $this->jsonMock->method('serialize')
            ->willReturnCallback(function (array $data) use (&$capturedArray) {
                $capturedArray = $data;
                return json_encode($data);
            });

        $result = $this->optionsGenerator->generate($customerId);

        $this->assertNotNull($capturedArray);
        $this->assertArrayHasKey('challengeToken', $capturedArray);
        $this->assertSame('my-challenge-token', $capturedArray['challengeToken']);

        $decoded = json_decode($result, true);
        $this->assertSame('my-challenge-token', $decoded['challengeToken']);
    }

    public function testGenerateCallsUserHandleGenerator(): void
    {
        $customerId = 42;
        $this->configureDefaultHappyPath($customerId);

        $this->userHandleGeneratorMock->expects($this->once())
            ->method('getOrGenerate')
            ->with($customerId)
            ->willReturn('dXNlci1oYW5kbGU=');

        $this->optionsGenerator->generate($customerId);
    }
}
