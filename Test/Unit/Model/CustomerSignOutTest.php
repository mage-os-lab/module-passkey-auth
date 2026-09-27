<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Model;

use MageOS\PasskeyAuth\Model\CustomerSignOut;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksLoggerTrait;
use Magento\Customer\Api\SessionCleanerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Integration\Api\CustomerTokenServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CustomerSignOutTest extends TestCase
{
    use MocksLoggerTrait;

    private const CUSTOMER_ID = 42;

    private SessionCleanerInterface&MockObject $sessionCleaner;
    private CustomerTokenServiceInterface&MockObject $tokenService;
    private ?CustomerSignOut $signOut = null;

    protected function setUp(): void
    {
        $this->createLoggerStub();
        $this->sessionCleaner = $this->createMock(SessionCleanerInterface::class);
        $this->tokenService = $this->createMock(CustomerTokenServiceInterface::class);
    }

    private function signOut(): CustomerSignOut
    {
        return $this->signOut ??= new CustomerSignOut($this->sessionCleaner, $this->tokenService, $this->loggerMock);
    }

    public function testSignOutEverywhereEndsSessionsAndRevokesTokens(): void
    {
        $this->sessionCleaner->expects($this->once())->method('clearFor')->with(self::CUSTOMER_ID);
        $this->tokenService->expects($this->once())
            ->method('revokeCustomerAccessToken')
            ->with(self::CUSTOMER_ID)
            ->willReturn(true);
        $this->mockLogger()->expects($this->never())->method('error');

        $this->assertTrue($this->signOut()->signOutEverywhere(self::CUSTOMER_ID));
    }

    public function testSignOutEverywhereStillRevokesTokensWhenSessionsFail(): void
    {
        $this->sessionCleaner->expects($this->once())
            ->method('clearFor')
            ->willThrowException(new \RuntimeException('Deadlock'));
        $this->tokenService->expects($this->once())->method('revokeCustomerAccessToken')->with(self::CUSTOMER_ID);
        $this->mockLogger()->expects($this->once())
            ->method('error')
            ->with('Failed to end customer sessions after passkey removal', [
                'exception' => 'Deadlock',
                'customer_id' => self::CUSTOMER_ID,
            ]);

        $this->assertFalse($this->signOut()->signOutEverywhere(self::CUSTOMER_ID));
    }

    public function testSignOutEverywhereReportsTokenFailure(): void
    {
        $this->sessionCleaner->expects($this->once())->method('clearFor')->with(self::CUSTOMER_ID);
        $this->tokenService->expects($this->once())
            ->method('revokeCustomerAccessToken')
            ->willThrowException(new LocalizedException(__('Failed to revoke customer\'s access tokens')));
        $this->mockLogger()->expects($this->once())
            ->method('error')
            ->with('Failed to revoke customer API tokens after passkey revocation', [
                'exception' => 'Failed to revoke customer\'s access tokens',
                'customer_id' => self::CUSTOMER_ID,
            ]);

        $this->assertFalse($this->signOut()->signOutEverywhere(self::CUSTOMER_ID));
    }

    public function testEndOtherSessionsKeepsTokens(): void
    {
        $this->sessionCleaner->expects($this->once())->method('clearFor')->with(self::CUSTOMER_ID);
        $this->tokenService->expects($this->never())->method('revokeCustomerAccessToken');

        $this->assertTrue($this->signOut()->endOtherSessions(self::CUSTOMER_ID));
    }

    public function testEndOtherSessionsReportsFailure(): void
    {
        $this->sessionCleaner->expects($this->once())
            ->method('clearFor')
            ->willThrowException(new \RuntimeException('Deadlock'));
        $this->tokenService->expects($this->never())->method('revokeCustomerAccessToken');
        $this->mockLogger()->expects($this->once())
            ->method('error')
            ->with('Failed to end customer sessions after passkey removal', [
                'exception' => 'Deadlock',
                'customer_id' => self::CUSTOMER_ID,
            ]);

        $this->assertFalse($this->signOut()->endOtherSessions(self::CUSTOMER_ID));
    }
}
