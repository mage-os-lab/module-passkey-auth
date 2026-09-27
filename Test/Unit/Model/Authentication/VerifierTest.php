<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Model\Authentication;

use MageOS\PasskeyAuth\Api\Data\AuthenticationResultInterface;
use MageOS\PasskeyAuth\Api\Data\AuthenticationResultInterfaceFactory;
use MageOS\PasskeyAuth\Api\Data\CredentialInterface;
use MageOS\PasskeyAuth\Model\Authentication\AccountGuard;
use MageOS\PasskeyAuth\Model\Authentication\Verifier;
use MageOS\PasskeyAuth\Model\ChallengeManager;
use MageOS\PasskeyAuth\Model\Exception\RateLimitExceededException;
use MageOS\PasskeyAuth\Model\PasskeyTokenService;
use MageOS\PasskeyAuth\Model\RateLimiter;
use MageOS\PasskeyAuth\Model\WebAuthn\Ceremony;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksConfigTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksCredentialRepositoryTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksLoggerTrait;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Config\Share;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\AuthenticationException;
use Magento\Framework\Exception\EmailNotConfirmedException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\State\UserLockedException;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\Exception\AuthenticatorResponseVerificationException;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\TrustPath\EmptyTrustPath;

class VerifierTest extends TestCase
{
    use MocksConfigTrait;
    use MocksCredentialRepositoryTrait;
    use MocksLoggerTrait;

    private const RAW_ID = 'test-credential-raw-id';
    private const CUSTOMER_ID = 42;

    private Ceremony&Stub $ceremonyMock;
    private PasskeyTokenService&Stub $tokenServiceMock;
    private AuthenticationResultInterfaceFactory&Stub $resultFactoryMock;
    private EventManager&Stub $eventManagerMock;
    private DateTime&Stub $dateTimeMock;
    private RateLimiter&Stub $rateLimiterMock;
    private RemoteAddress&Stub $remoteAddressStub;
    private Share&Stub $shareConfigStub;
    private CustomerRepositoryInterface&Stub $customerRepositoryStub;
    private StoreManagerInterface&Stub $storeManagerStub;
    private AccountGuard&Stub $accountGuardMock;
    private CustomerInterface&Stub $customer;
    private bool $ceremonyIsMock = false;
    private bool $tokenServiceIsMock = false;
    private bool $resultFactoryIsMock = false;
    private bool $eventManagerIsMock = false;
    private bool $rateLimiterIsMock = false;
    private bool $accountGuardIsMock = false;
    private ?Verifier $verifier = null;

    private PublicKeyCredential $credential;
    private PublicKeyCredentialRequestOptions $requestOptions;
    private PublicKeyCredentialSource $storedSource;

    protected function setUp(): void
    {
        $this->createConfigStub();
        $this->createCredentialRepositoryStub();
        $this->createLoggerStub();

        $this->ceremonyMock = $this->createStub(Ceremony::class);
        $this->tokenServiceMock = $this->createStub(PasskeyTokenService::class);
        $this->resultFactoryMock = $this->createStub(AuthenticationResultInterfaceFactory::class);
        $this->eventManagerMock = $this->createStub(EventManager::class);
        $this->dateTimeMock = $this->createStub(DateTime::class);
        $this->rateLimiterMock = $this->createStub(RateLimiter::class);
        $this->remoteAddressStub = $this->createStub(RemoteAddress::class);
        $this->remoteAddressStub->method('getRemoteAddress')->willReturn('10.0.0.1');
        $this->shareConfigStub = $this->createStub(Share::class);
        $this->customer = $this->createStub(CustomerInterface::class);
        $this->customerRepositoryStub = $this->createStub(CustomerRepositoryInterface::class);
        $this->customerRepositoryStub->method('getById')->willReturn($this->customer);
        $this->storeManagerStub = $this->createStub(StoreManagerInterface::class);
        $this->accountGuardMock = $this->createStub(AccountGuard::class);

        $this->credential = PublicKeyCredential::create(
            'public-key',
            self::RAW_ID,
            $this->createStub(AuthenticatorAssertionResponse::class)
        );
        $this->requestOptions = PublicKeyCredentialRequestOptions::create('fake-challenge');
        $this->storedSource = $this->createSource(0);
    }

    /**
     * Build the subject lazily, so tests can first replace stubs with mocks.
     */
    private function verifier(): Verifier
    {
        return $this->verifier ??= new Verifier(
            $this->configMock,
            $this->ceremonyMock,
            $this->credentialRepositoryMock,
            $this->tokenServiceMock,
            $this->resultFactoryMock,
            $this->eventManagerMock,
            $this->loggerMock,
            $this->dateTimeMock,
            $this->rateLimiterMock,
            $this->remoteAddressStub,
            $this->shareConfigStub,
            $this->customerRepositoryStub,
            $this->storeManagerStub,
            $this->accountGuardMock
        );
    }

    private function mockCeremony(): Ceremony&MockObject
    {
        if (!$this->ceremonyIsMock) {
            $this->ceremonyMock = $this->createMock(Ceremony::class);
            $this->ceremonyIsMock = true;
        }
        return $this->ceremonyMock;
    }

    private function mockTokenService(): PasskeyTokenService&MockObject
    {
        if (!$this->tokenServiceIsMock) {
            $this->tokenServiceMock = $this->createMock(PasskeyTokenService::class);
            $this->tokenServiceIsMock = true;
        }
        return $this->tokenServiceMock;
    }

    private function mockResultFactory(): AuthenticationResultInterfaceFactory&MockObject
    {
        if (!$this->resultFactoryIsMock) {
            $this->resultFactoryMock = $this->createMock(AuthenticationResultInterfaceFactory::class);
            $this->resultFactoryIsMock = true;
        }
        return $this->resultFactoryMock;
    }

    private function mockEventManager(): EventManager&MockObject
    {
        if (!$this->eventManagerIsMock) {
            $this->eventManagerMock = $this->createMock(EventManager::class);
            $this->eventManagerIsMock = true;
        }
        return $this->eventManagerMock;
    }

    private function mockRateLimiter(): RateLimiter&MockObject
    {
        if (!$this->rateLimiterIsMock) {
            $this->rateLimiterMock = $this->createMock(RateLimiter::class);
            $this->rateLimiterIsMock = true;
        }
        return $this->rateLimiterMock;
    }

    private function mockAccountGuard(): AccountGuard&MockObject
    {
        if (!$this->accountGuardIsMock) {
            $this->accountGuardMock = $this->createMock(AccountGuard::class);
            $this->accountGuardIsMock = true;
        }
        return $this->accountGuardMock;
    }

    public function testVerifyThrowsWhenDisabled(): void
    {
        $this->configureEnabled(false);
        $this->mockCeremony()->expects($this->never())->method('loadAssertion');
        $this->expectRejectionLogged('Passkey authentication is not enabled.');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Passkey authentication is not enabled.');

        $this->verifier()->verify('token123', '{"response":"data"}');
    }

    public function testVerifyThrowsOnInvalidChallengeToken(): void
    {
        $this->configureEnabled(true);

        $this->mockCeremony()->expects($this->once())
            ->method('loadAssertion')
            ->with('bad-token', '{"response":"data"}', ChallengeManager::TYPE_AUTHENTICATION)
            ->willThrowException(new LocalizedException(__('Invalid or expired challenge token.')));
        $this->mockEventManager()->expects($this->never())->method('dispatch');
        $this->expectRejectionLogged('Invalid or expired challenge token.');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Invalid or expired challenge token.');

        $this->verifier()->verify('bad-token', '{"response":"data"}');
    }

    public function testVerifyThrowsOnInvalidResponseType(): void
    {
        $this->configureEnabled(true);

        $this->ceremonyMock->method('loadAssertion')
            ->willThrowException(new LocalizedException(__('Invalid assertion response.')));
        $this->mockCredentialRepository()->expects($this->never())->method('getByCredentialId');
        $this->expectRejectionLogged('Invalid assertion response.');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Invalid assertion response.');

        $this->verifier()->verify('valid-token', '{"response":"attestation-not-assertion"}');
    }

    public function testVerifyThrowsWhenCredentialNotFound(): void
    {
        $this->configureEnabled(true);
        $this->configureLoadAssertion();

        $expectedCredentialId = base64_encode(self::RAW_ID);

        $this->mockCredentialRepository()->method('getByCredentialId')
            ->with($expectedCredentialId)
            ->willThrowException(new NoSuchEntityException(__('No such entity.')));
        $this->mockCeremony()->expects($this->never())->method('verifyAssertion');

        $this->mockEventManager()->expects($this->once())
            ->method('dispatch')
            ->with('passkey_authentication_failure', [
                'credential_id' => $expectedCredentialId,
                'customer_id' => null,
                'reason' => 'credential_not_found',
                'message' => 'No such entity.',
            ]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Passkey verification failed. Please try again.');

        $this->verifier()->verify('valid-token', '{"response":"assertion"}');
    }

    public function testVerifyRejectsCredentialOfAnotherWebsite(): void
    {
        $this->configureEnabled(true);
        $this->configureLoadAssertion();
        $this->configureStoredCredential(0);
        $this->configureWebsites(customerWebsiteId: 1, currentWebsiteId: 2);

        $this->mockCeremony()->expects($this->never())->method('verifyAssertion');
        $this->mockTokenService()->expects($this->never())->method('createTokenForCustomer');
        $this->mockRateLimiter()->expects($this->once())->method('recordVerifyFailure')->with('10.0.0.1');
        $this->mockEventManager()->expects($this->once())
            ->method('dispatch')
            ->with('passkey_authentication_failure', [
                'credential_id' => base64_encode(self::RAW_ID),
                'customer_id' => self::CUSTOMER_ID,
                'reason' => 'credential_not_found',
                'message' => 'Passkey credential belongs to another website.',
            ]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Passkey verification failed. Please try again.');

        $this->verifier()->verify('valid-token', '{"response":"assertion"}');
    }

    public function testVerifyAcceptsCredentialOfCurrentWebsite(): void
    {
        $this->configureEnabled(true);
        $this->configureLoadAssertion();
        $this->configureStoredCredential(0);
        $this->configureWebsites(customerWebsiteId: 2, currentWebsiteId: 2);
        $this->configureVerifiedAssertion(1);
        $result = $this->configureTokenAndResult();

        $this->assertSame($result, $this->verifier()->verify('valid-token', '{"response":"assertion"}'));
    }

    public function testVerifyIgnoresWebsiteWhenAccountsAreGlobal(): void
    {
        $this->configureEnabled(true);
        $this->configureLoadAssertion();
        $this->configureStoredCredential(0);
        $this->shareConfigStub->method('isWebsiteScope')->willReturn(false);
        $store = $this->createStub(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(2);
        $this->storeManagerStub->method('getStore')->willReturn($store);

        // Loaded once, for the account checks only
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getWebsiteId')->willReturn(1);
        $this->customerRepositoryStub = $this->createMock(CustomerRepositoryInterface::class);
        $this->customerRepositoryStub->expects($this->once())
            ->method('getById')
            ->with(self::CUSTOMER_ID)
            ->willReturn($customer);
        $this->mockAccountGuard()->expects($this->once())->method('assertCanSignIn')->with($customer);
        $this->configureVerifiedAssertion(1);
        $result = $this->configureTokenAndResult();

        $this->assertSame($result, $this->verifier()->verify('valid-token', '{"response":"assertion"}'));
    }

    public function testVerifyReusesCustomerLoadedForWebsiteCheck(): void
    {
        $this->configureEnabled(true);
        $this->configureLoadAssertion();
        $this->configureStoredCredential(0);
        // Expects one getById call
        $customer = $this->configureWebsites(customerWebsiteId: 2, currentWebsiteId: 2);
        $this->mockAccountGuard()->expects($this->once())->method('assertCanSignIn')->with($customer);
        $this->configureVerifiedAssertion(1);
        $result = $this->configureTokenAndResult();

        $this->assertSame($result, $this->verifier()->verify('valid-token', '{"response":"assertion"}'));
    }

    /**
     * @return array<string, array{AuthenticationException}>
     */
    public static function refusedAccountProvider(): array
    {
        return [
            'locked' => [new UserLockedException(__('The account is locked.'))],
            'not confirmed' => [
                new EmailNotConfirmedException(__('This account isn\'t confirmed. Verify and try again.')),
            ],
            'group excluded' => [new AuthenticationException(__('This website is excluded from customer\'s group.'))],
        ];
    }

    #[DataProvider('refusedAccountProvider')]
    public function testVerifyRefusesAccountAfterVerification(AuthenticationException $refusal): void
    {
        $this->configureEnabled(true);
        $this->configureLoadAssertion();
        $this->configureStoredCredential(0);
        $this->configureVerifiedAssertion(1);

        $this->mockAccountGuard()->expects($this->once())
            ->method('assertCanSignIn')
            ->with($this->customer)
            ->willThrowException($refusal);
        $this->mockAccountGuard()->expects($this->never())->method('recordSignIn');

        // The key holder proved possession: not a failed attempt
        $this->mockRateLimiter()->expects($this->never())->method('recordVerifyFailure');
        $this->mockTokenService()->expects($this->never())->method('createTokenForCustomer');
        $this->mockCredentialRepository()->expects($this->never())->method('save');
        $this->mockEventManager()->expects($this->never())->method('dispatch');
        $this->mockLogger()->expects($this->once())
            ->method('warning')
            ->with('Passkey sign-in refused', [
                'customer_id' => self::CUSTOMER_ID,
                'reason' => $refusal->getMessage(),
            ]);

        try {
            $this->verifier()->verify('valid-token', '{"response":"assertion"}');
            $this->fail('Expected the sign-in to be refused');
        } catch (AuthenticationException $e) {
            $this->assertSame($refusal, $e);
        }
    }

    public function testVerifyChecksAccountOnlyAfterAssertionIsVerified(): void
    {
        $this->configureEnabled(true);
        $this->configureLoadAssertion();
        $this->configureStoredCredential(0);
        $this->ceremonyMock->method('verifyAssertion')
            ->willThrowException(AuthenticatorResponseVerificationException::create('Invalid signature'));

        $this->mockAccountGuard()->expects($this->never())->method('assertCanSignIn');
        $this->mockRateLimiter()->expects($this->once())->method('recordVerifyFailure')->with('10.0.0.1');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Passkey verification failed. Please try again.');

        $this->verifier()->verify('valid-token', '{"response":"assertion"}');
    }

    public function testVerifyDoesNotCheckAccountOfUnknownCredential(): void
    {
        $this->configureEnabled(true);
        $this->configureLoadAssertion();
        $this->credentialRepositoryMock->method('getByCredentialId')
            ->willThrowException(new NoSuchEntityException(__('No such entity.')));

        $this->mockAccountGuard()->expects($this->never())->method('assertCanSignIn');

        $this->expectException(LocalizedException::class);

        $this->verifier()->verify('valid-token', '{"response":"assertion"}');
    }

    public function testVerifyResetsFailedSignInCountOnSuccess(): void
    {
        $this->configureEnabled(true);
        $this->configureLoadAssertion();
        $this->configureStoredCredential(0);
        $this->configureVerifiedAssertion(1);
        $result = $this->configureTokenAndResult();

        $this->mockAccountGuard()->expects($this->once())->method('recordSignIn')->with(self::CUSTOMER_ID);

        $this->assertSame($result, $this->verifier()->verify('valid-token', '{"response":"assertion"}'));
    }

    public function testVerifySucceedsWhenFailedSignInCountResetFails(): void
    {
        $this->configureEnabled(true);
        $this->configureLoadAssertion();
        $this->configureStoredCredential(0);
        $this->configureVerifiedAssertion(1);
        $result = $this->configureTokenAndResult();

        $this->accountGuardMock->method('recordSignIn')->willThrowException(new \RuntimeException('Lock wait timeout'));
        $this->mockLogger()->expects($this->once())
            ->method('error')
            ->with('Failed to reset failed sign-in count after passkey sign-in', [
                'exception' => 'Lock wait timeout',
                'customer_id' => self::CUSTOMER_ID,
            ]);

        $this->assertSame($result, $this->verifier()->verify('valid-token', '{"response":"assertion"}'));
    }

    public function testVerifyTurnsValidatorErrorsIntoGenericFailure(): void
    {
        $this->configureEnabled(true);
        $this->configureLoadAssertion();
        $this->configureStoredCredential(0);

        $this->ceremonyMock->method('verifyAssertion')->willThrowException(new \TypeError('Unexpected type'));
        $this->mockRateLimiter()->expects($this->once())->method('recordVerifyFailure')->with('10.0.0.1');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Passkey verification failed. Please try again.');

        $this->verifier()->verify('valid-token', '{"response":"assertion"}');
    }

    public function testVerifyRecordsFailureForErrors(): void
    {
        $this->configureEnabled(true);
        $this->ceremonyMock->method('loadAssertion')->willThrowException(new \TypeError('Unexpected type'));
        $this->mockRateLimiter()->expects($this->once())
            ->method('recordVerifyFailure')
            ->with('10.0.0.1');
        // Unexpected errors are left to the caller's error handling
        $this->mockLogger()->expects($this->never())->method('warning');

        $this->expectException(\TypeError::class);

        $this->verifier()->verify('bad-token', '{"response":"assertion"}');
    }

    public function testVerifyThrowsWhenValidatorFails(): void
    {
        $this->configureEnabled(true);
        $this->configureLoadAssertion();
        $this->configureStoredCredential(0);

        $this->mockCeremony()->expects($this->once())
            ->method('verifyAssertion')
            ->with($this->credential, $this->requestOptions, $this->storedSource)
            ->willThrowException(
                AuthenticatorResponseVerificationException::create('Signature verification failed')
            );

        $this->mockEventManager()->expects($this->once())
            ->method('dispatch')
            ->with('passkey_authentication_failure', [
                'credential_id' => base64_encode(self::RAW_ID),
                'customer_id' => self::CUSTOMER_ID,
                'reason' => 'verification_failed',
                'message' => 'Signature verification failed',
            ]);
        $this->mockTokenService()->expects($this->never())->method('createTokenForCustomer');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Passkey verification failed. Please try again.');

        $this->verifier()->verify('valid-token', '{"response":"assertion"}');
    }

    public function testVerifySucceeds(): void
    {
        $this->configureEnabled(true);
        $this->configureLoadAssertion();
        $storedCredential = $this->createMock(CredentialInterface::class);
        $storedCredential->method('getEntityId')->willReturn(17);
        $this->configureStoredCredential(5, $storedCredential);
        $updatedSource = $this->configureVerifiedAssertion(6);

        $this->mockCeremony()->expects($this->once())
            ->method('serializeSource')
            ->with($updatedSource)
            ->willReturn('{"updated":"source"}');
        $this->dateTimeMock->method('gmtDate')->willReturn('2026-03-04 12:00:00');

        $storedCredential->expects($this->once())->method('setSignCount')->with(6);
        $storedCredential->expects($this->once())->method('setPublicKey')->with('{"updated":"source"}');
        $storedCredential->expects($this->once())->method('setLastUsedAt')->with('2026-03-04 12:00:00');
        $this->mockCredentialRepository()->expects($this->once())->method('save')->with($storedCredential);

        $this->mockTokenService()->expects($this->once())
            ->method('createTokenForCustomer')
            ->with(self::CUSTOMER_ID)
            ->willReturn('test-token-value');

        $result = $this->createStub(AuthenticationResultInterface::class);
        $this->mockResultFactory()->expects($this->once())
            ->method('create')
            ->with(['data' => ['customer_id' => self::CUSTOMER_ID, 'token' => 'test-token-value']])
            ->willReturn($result);

        $this->mockEventManager()->expects($this->once())
            ->method('dispatch')
            ->with('passkey_authentication_success', [
                'customer_id' => self::CUSTOMER_ID,
                'entity_id' => 17,
                'credential_id' => base64_encode(self::RAW_ID),
                'credential' => $storedCredential,
            ]);
        $this->mockLogger()->expects($this->never())->method('warning');

        $this->assertSame($result, $this->verifier()->verify('valid-token', '{"response":"assertion"}'));
    }

    public function testVerifyThrowsWhenRateLimited(): void
    {
        $this->configureEnabled(true);
        $this->mockRateLimiter()->method('checkVerifyFailRate')
            ->with('10.0.0.1')
            ->willThrowException(new RateLimitExceededException(
                __('Too many failed passkey attempts. Please try again later.')
            ));
        $this->mockCeremony()->expects($this->never())->method('loadAssertion');
        $this->mockRateLimiter()->expects($this->never())->method('recordVerifyFailure');
        $this->expectRejectionLogged('Too many failed passkey attempts. Please try again later.');

        $this->expectException(RateLimitExceededException::class);
        $this->expectExceptionMessage('Too many failed passkey attempts. Please try again later.');

        $this->verifier()->verify('valid-token', '{"response":"assertion"}');
    }

    public function testVerifyRecordsFailureForClientIp(): void
    {
        $this->configureEnabled(true);
        $this->ceremonyMock->method('loadAssertion')
            ->willThrowException(new LocalizedException(__('Invalid or expired challenge token.')));
        $this->mockRateLimiter()->expects($this->once())
            ->method('recordVerifyFailure')
            ->with('10.0.0.1');

        $this->expectException(LocalizedException::class);

        $this->verifier()->verify('bad-token', '{"response":"assertion"}');
    }

    public function testVerifySuccessDoesNotRecordFailure(): void
    {
        $this->configureEnabled(true);
        $this->configureLoadAssertion();
        $this->configureStoredCredential(0);
        $this->configureVerifiedAssertion(0);
        $result = $this->configureTokenAndResult();

        $this->mockRateLimiter()->expects($this->once())->method('checkVerifyFailRate')->with('10.0.0.1');
        $this->mockRateLimiter()->expects($this->never())->method('recordVerifyFailure');

        $this->assertSame($result, $this->verifier()->verify('valid-token', '{"response":"assertion"}'));
    }

    public function testVerifySucceedsWhenCredentialUpdateFails(): void
    {
        $this->configureEnabled(true);
        $this->configureLoadAssertion();
        $this->configureStoredCredential(5);
        $this->configureVerifiedAssertion(10);
        $result = $this->configureTokenAndResult();

        $this->credentialRepositoryMock->method('save')
            ->willThrowException(new \RuntimeException('Database connection lost'));

        $this->mockLogger()->expects($this->once())
            ->method('error')
            ->with('Failed to update passkey credential after authentication', [
                'exception' => 'Database connection lost',
                'credential_id' => base64_encode(self::RAW_ID),
            ]);

        $this->assertSame($result, $this->verifier()->verify('valid-token', '{"response":"assertion"}'));
    }

    public function testVerifyThrowsWhenTokenCreationFails(): void
    {
        $this->configureEnabled(true);
        $this->configureLoadAssertion();
        $this->configureStoredCredential(5);
        $this->configureVerifiedAssertion(10);
        $this->dateTimeMock->method('gmtDate')->willReturn('2026-03-04 12:00:00');

        $this->mockTokenService()->method('createTokenForCustomer')
            ->with(self::CUSTOMER_ID)
            ->willThrowException(new \RuntimeException('Token service unavailable'));

        $this->mockLogger()->expects($this->once())
            ->method('error')
            ->with('Failed to create token for passkey customer', [
                'exception' => 'Token service unavailable',
                'customer_id' => self::CUSTOMER_ID,
            ]);
        $this->mockEventManager()->expects($this->never())->method('dispatch');
        $this->mockAccountGuard()->expects($this->never())->method('recordSignIn');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Authentication succeeded but token creation failed.');

        $this->verifier()->verify('valid-token', '{"response":"assertion"}');
    }

    private function expectRejectionLogged(string $reason): void
    {
        $this->mockLogger()->expects($this->once())
            ->method('warning')
            ->with('Passkey authentication rejected', ['reason' => $reason]);
    }

    private function configureLoadAssertion(): void
    {
        $this->mockCeremony()->expects($this->once())
            ->method('loadAssertion')
            ->with('valid-token', '{"response":"assertion"}', ChallengeManager::TYPE_AUTHENTICATION)
            ->willReturn([$this->credential, $this->requestOptions]);
    }

    /**
     * Pass a mock as $storedCredential to set expectations on it; a stub is created otherwise.
     */
    private function configureStoredCredential(
        int $storedSignCount,
        (CredentialInterface&Stub)|null $storedCredential = null
    ): CredentialInterface&Stub {
        $storedCredential ??= $this->createStub(CredentialInterface::class);
        $storedCredential->method('getPublicKey')->willReturn('{"serialized":"credential-source"}');
        $storedCredential->method('getCustomerId')->willReturn(self::CUSTOMER_ID);
        $storedCredential->method('getSignCount')->willReturn($storedSignCount);

        $this->mockCredentialRepository()->method('getByCredentialId')
            ->with(base64_encode(self::RAW_ID))
            ->willReturn($storedCredential);

        $this->mockCeremony()->method('deserializeSource')
            ->with('{"serialized":"credential-source"}')
            ->willReturn($this->storedSource);

        return $storedCredential;
    }

    private function configureWebsites(int $customerWebsiteId, int $currentWebsiteId): CustomerInterface
    {
        $this->shareConfigStub->method('isWebsiteScope')->willReturn(true);

        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getWebsiteId')->willReturn($customerWebsiteId);
        $this->customerRepositoryStub = $this->createMock(CustomerRepositoryInterface::class);
        $this->customerRepositoryStub->expects($this->once())
            ->method('getById')
            ->with(self::CUSTOMER_ID)
            ->willReturn($customer);

        $store = $this->createStub(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn($currentWebsiteId);
        $this->storeManagerStub->method('getStore')->willReturn($store);

        return $customer;
    }

    private function configureVerifiedAssertion(int $newCounter): PublicKeyCredentialSource
    {
        $updatedSource = $this->createSource($newCounter);
        $this->mockCeremony()->expects($this->once())
            ->method('verifyAssertion')
            ->with($this->credential, $this->requestOptions, $this->storedSource)
            ->willReturn($updatedSource);

        return $updatedSource;
    }

    private function configureTokenAndResult(): AuthenticationResultInterface
    {
        $this->dateTimeMock->method('gmtDate')->willReturn('2026-03-04 12:00:00');
        $this->mockTokenService()->method('createTokenForCustomer')
            ->with(self::CUSTOMER_ID)
            ->willReturn('test-token-value');

        $result = $this->createStub(AuthenticationResultInterface::class);
        $this->resultFactoryMock->method('create')->willReturn($result);

        return $result;
    }

    private function createSource(int $counter): PublicKeyCredentialSource
    {
        return new PublicKeyCredentialSource(
            self::RAW_ID,
            'public-key',
            [],
            'none',
            EmptyTrustPath::create(),
            Uuid::fromString('00000000-0000-0000-0000-000000000000'),
            'fake-credential-public-key',
            'user-handle-bytes',
            $counter
        );
    }
}
