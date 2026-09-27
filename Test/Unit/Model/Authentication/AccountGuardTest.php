<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Model\Authentication;

use MageOS\PasskeyAuth\Model\Authentication\AccountGuard;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksLoggerTrait;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\GroupExcludedWebsiteRepositoryInterface;
use Magento\Customer\Model\AccountConfirmation;
use Magento\Customer\Model\AuthenticationInterface;
use Magento\Customer\Model\CustomerRegistry;
use Magento\Customer\Model\Data\CustomerSecure;
use Magento\Framework\Exception\AuthenticationException;
use Magento\Framework\Exception\EmailNotConfirmedException;
use Magento\Framework\Exception\State\UserLockedException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class AccountGuardTest extends TestCase
{
    use MocksLoggerTrait;

    private const CUSTOMER_ID = 42;
    private const WEBSITE_ID = 3;
    private const EMAIL = 'jane@example.com';
    private const NOT_ALLOWED = 'The account sign-in was incorrect or your account is disabled temporarily. '
        . 'Please wait and try again later.';

    private AuthenticationInterface&MockObject $authentication;
    private AccountConfirmation&Stub $accountConfirmation;
    private GroupExcludedWebsiteRepositoryInterface&Stub $groupExcludedWebsiteRepository;
    private StoreManagerInterface&Stub $storeManager;
    private CustomerRegistry&Stub $customerRegistry;
    private ?AccountGuard $guard = null;

    protected function setUp(): void
    {
        $this->createLoggerStub();
        $this->authentication = $this->createMock(AuthenticationInterface::class);
        // A passkey attempt never counts toward core's lockout, in any test
        $this->authentication->expects($this->never())->method('processAuthenticationFailure');
        $this->authentication->expects($this->never())->method('authenticate');
        $this->accountConfirmation = $this->createStub(AccountConfirmation::class);
        $this->groupExcludedWebsiteRepository = $this->createStub(GroupExcludedWebsiteRepositoryInterface::class);
        $this->storeManager = $this->createStub(StoreManagerInterface::class);
        $store = $this->createStub(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn('1');
        $this->storeManager->method('getStore')->willReturn($store);
        $this->customerRegistry = $this->createStub(CustomerRegistry::class);
    }

    private function guard(): AccountGuard
    {
        return $this->guard ??= new AccountGuard(
            $this->authentication,
            $this->accountConfirmation,
            $this->groupExcludedWebsiteRepository,
            $this->storeManager,
            $this->customerRegistry,
            $this->loggerMock
        );
    }

    private function customer(?string $confirmation = null, int $groupId = 1): CustomerInterface&Stub
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn(self::CUSTOMER_ID);
        $customer->method('getWebsiteId')->willReturn(self::WEBSITE_ID);
        $customer->method('getEmail')->willReturn(self::EMAIL);
        $customer->method('getConfirmation')->willReturn($confirmation);
        $customer->method('getGroupId')->willReturn($groupId);

        return $customer;
    }

    private function expectRefusalLogged(string $reason): void
    {
        $this->mockLogger()->expects($this->once())
            ->method('warning')
            ->with('Passkey sign-in refused', ['customer_id' => self::CUSTOMER_ID, 'reason' => $reason]);
    }

    public function testAllowsActiveConfirmedAccount(): void
    {
        $this->mockLogger()->expects($this->never())->method('warning');
        $this->authentication->expects($this->once())
            ->method('isLocked')
            ->with(self::CUSTOMER_ID)
            ->willReturn(false);
        $this->accountConfirmation->method('isConfirmationRequired')->willReturn(true);

        // No pending confirmation key: the account is confirmed
        $this->guard()->assertCanSignIn($this->customer());
        $this->addToAssertionCount(1);
    }

    public function testRefusesLockedAccount(): void
    {
        $this->authentication->expects($this->once())
            ->method('isLocked')
            ->with(self::CUSTOMER_ID)
            ->willReturn(true);
        $this->expectRefusalLogged('Account is locked');

        // Core's message for a locked account, not the reason
        $this->expectException(UserLockedException::class);
        $this->expectExceptionMessage(self::NOT_ALLOWED);

        $this->guard()->assertCanSignIn($this->customer());
    }

    public function testRefusesUnconfirmedAccount(): void
    {
        $this->authentication->method('isLocked')->willReturn(false);
        $this->accountConfirmation = $this->createMock(AccountConfirmation::class);
        $this->accountConfirmation->expects($this->once())
            ->method('isConfirmationRequired')
            ->with(self::WEBSITE_ID, self::CUSTOMER_ID, self::EMAIL)
            ->willReturn(true);
        $this->expectRefusalLogged('Account is not confirmed');

        $this->expectException(EmailNotConfirmedException::class);
        $this->expectExceptionMessage('This account isn\'t confirmed. Verify and try again.');

        $this->guard()->assertCanSignIn($this->customer('confirmation-key'));
    }

    public function testRefusesAccountWithUnconfirmedEmailChange(): void
    {
        $this->authentication->method('isLocked')->willReturn(false);
        $this->accountConfirmation = $this->createMock(AccountConfirmation::class);
        $this->accountConfirmation->method('isConfirmationRequired')->willReturn(false);
        $this->accountConfirmation->expects($this->once())
            ->method('isEmailChangedConfirmationRequired')
            ->with(self::WEBSITE_ID, self::CUSTOMER_ID, self::EMAIL)
            ->willReturn(true);

        $this->expectException(EmailNotConfirmedException::class);
        $this->expectExceptionMessage('This account isn\'t confirmed. Verify and try again.');

        $this->guard()->assertCanSignIn($this->customer('confirmation-key'));
    }

    public function testAllowsPendingConfirmationWhenNotRequired(): void
    {
        $this->authentication->method('isLocked')->willReturn(false);
        $this->accountConfirmation->method('isConfirmationRequired')->willReturn(false);
        $this->accountConfirmation->method('isEmailChangedConfirmationRequired')->willReturn(false);

        $this->guard()->assertCanSignIn($this->customer('confirmation-key'));
        $this->addToAssertionCount(1);
    }

    public function testChecksLockBeforeConfirmation(): void
    {
        $this->authentication->method('isLocked')->willReturn(true);
        $this->accountConfirmation = $this->createMock(AccountConfirmation::class);
        $this->accountConfirmation->expects($this->never())->method('isConfirmationRequired');

        $this->expectException(UserLockedException::class);

        $this->guard()->assertCanSignIn($this->customer('confirmation-key'));
    }

    public function testRefusesGroupExcludedFromCurrentWebsite(): void
    {
        $this->authentication->method('isLocked')->willReturn(false);
        $this->groupExcludedWebsiteRepository = $this->createMock(GroupExcludedWebsiteRepositoryInterface::class);
        // The resource model returns website IDs as strings
        $this->groupExcludedWebsiteRepository->expects($this->once())
            ->method('getCustomerGroupExcludedWebsites')
            ->with(5)
            ->willReturn(['1', '4']);
        $this->expectRefusalLogged('Customer group is excluded from this website');

        // Core's message for a locked account, not the reason
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage(self::NOT_ALLOWED);

        $this->guard()->assertCanSignIn($this->customer(null, 5));
    }

    public function testAllowsGroupExcludedFromOtherWebsites(): void
    {
        $this->authentication->method('isLocked')->willReturn(false);
        $this->groupExcludedWebsiteRepository->method('getCustomerGroupExcludedWebsites')->willReturn(['4']);

        $this->guard()->assertCanSignIn($this->customer(null, 5));
        $this->addToAssertionCount(1);
    }

    public function testSkipsGroupCheckWithoutGroup(): void
    {
        $this->authentication->method('isLocked')->willReturn(false);
        $this->groupExcludedWebsiteRepository = $this->createMock(GroupExcludedWebsiteRepositoryInterface::class);
        $this->groupExcludedWebsiteRepository->expects($this->never())->method('getCustomerGroupExcludedWebsites');

        $this->guard()->assertCanSignIn($this->customer(null, 0));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function failedSignInDataProvider(): array
    {
        return [
            'failed attempts' => [['failures_num' => '2', 'first_failure' => '2026-09-27 10:00:00']],
            'first failure only' => [['failures_num' => '0', 'first_failure' => '2026-09-27 10:00:00']],
            'lock expiry' => [['failures_num' => '0', 'lock_expires' => '2026-09-27 10:10:00']],
        ];
    }

    #[DataProvider('failedSignInDataProvider')]
    public function testRecordSignInResetsFailedSignInCount(array $secureData): void
    {
        $this->customerRegistry->method('retrieveSecureData')->willReturn(new CustomerSecure($secureData));
        $this->authentication->expects($this->once())->method('unlock')->with(self::CUSTOMER_ID);

        $this->guard()->recordSignIn(self::CUSTOMER_ID);
    }

    public function testRecordSignInSkipsUnlockWithNothingToReset(): void
    {
        // As loaded from the database for a customer with no failed attempts
        $this->customerRegistry->method('retrieveSecureData')->willReturn(new CustomerSecure([
            'failures_num' => '0',
            'first_failure' => null,
            'lock_expires' => null,
        ]));
        $this->authentication->expects($this->never())->method('unlock');

        $this->guard()->recordSignIn(self::CUSTOMER_ID);
    }
}
