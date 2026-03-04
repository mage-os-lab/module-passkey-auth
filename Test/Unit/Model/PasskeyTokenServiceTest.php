<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Model;

use MageOS\PasskeyAuth\Model\PasskeyTokenService;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Integration\Api\TokenManager;
use Magento\Integration\Model\CustomUserContext;
use Magento\Integration\Model\CustomUserContextFactory;
use Magento\Integration\Model\UserToken\UserTokenParameters;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PasskeyTokenServiceTest extends TestCase
{
    private TokenManager&MockObject $tokenManager;
    private CustomUserContextFactory&MockObject $userContextFactory;
    private PasskeyTokenService $service;

    protected function setUp(): void
    {
        $this->tokenManager = $this->createMock(TokenManager::class);
        $this->userContextFactory = $this->createMock(CustomUserContextFactory::class);

        $this->service = new PasskeyTokenService(
            $this->tokenManager,
            $this->userContextFactory
        );
    }

    public function testCreateTokenForCustomer(): void
    {
        $customerId = 42;
        $expectedToken = 'abc123tokenvalue';

        $userContext = $this->createMock(CustomUserContext::class);
        $tokenParams = $this->createMock(UserTokenParameters::class);

        $this->userContextFactory->expects($this->once())
            ->method('create')
            ->with([
                'userId' => $customerId,
                'userType' => UserContextInterface::USER_TYPE_CUSTOMER,
            ])
            ->willReturn($userContext);

        $this->tokenManager->expects($this->once())
            ->method('createUserTokenParameters')
            ->willReturn($tokenParams);

        $this->tokenManager->expects($this->once())
            ->method('create')
            ->with($userContext, $tokenParams)
            ->willReturn($expectedToken);

        $result = $this->service->createTokenForCustomer($customerId);

        $this->assertSame($expectedToken, $result);
    }

    public function testCreateTokenPassesCorrectUserType(): void
    {
        $customerId = 99;

        $userContext = $this->createMock(CustomUserContext::class);
        $tokenParams = $this->createMock(UserTokenParameters::class);

        $this->userContextFactory->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $args): bool {
                $this->assertArrayHasKey('userType', $args);
                $this->assertSame(UserContextInterface::USER_TYPE_CUSTOMER, $args['userType']);
                return true;
            }))
            ->willReturn($userContext);

        $this->tokenManager->method('createUserTokenParameters')->willReturn($tokenParams);
        $this->tokenManager->method('create')->willReturn('token');

        $this->service->createTokenForCustomer($customerId);
    }
}
