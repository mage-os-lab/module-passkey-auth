<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Model\Authentication;

use MageOS\PasskeyAuth\Model\Authentication\AccountGuard;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\GroupExcludedWebsiteRepositoryInterface;
use Magento\Customer\Model\AccountConfirmation;
use Magento\Customer\Model\AuthenticationInterface;
use Magento\Framework\Exception\AuthenticationException;
use Magento\Framework\Exception\EmailNotConfirmedException;
use Magento\Framework\Exception\State\UserLockedException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class AccountGuardTest extends TestCase
{
    private const CUSTOMER_ID = 42;
    private const WEBSITE_ID = 3;
    private const EMAIL = 'jane@example.com';

    private AuthenticationInterface&MockObject $authentication;
    private AccountConfirmation&Stub $accountConfirmation;
    private GroupExcludedWebsiteRepositoryInterface&Stub $groupExcludedWebsiteRepository;
    private StoreManagerInterface&Stub $storeManager;
    private ?AccountGuard $guard = null;

    protected function setUp(): void
    {
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
    }

    private function guard(): AccountGuard
    {
        return $this->guard ??= new AccountGuard(
            $this->authentication,
            $this->accountConfirmation,
            $this->groupExcludedWebsiteRepository,
            $this->storeManager
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

    public function testAllowsActiveConfirmedAccount(): void
    {
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

        $this->expectException(UserLockedException::class);
        $this->expectExceptionMessage('The account is locked.');

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

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('This website is excluded from customer\'s group.');

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

    public function testRecordSignInResetsFailedSignInCount(): void
    {
        $this->authentication->expects($this->once())->method('unlock')->with(self::CUSTOMER_ID);

        $this->guard()->recordSignIn(self::CUSTOMER_ID);
    }
}
