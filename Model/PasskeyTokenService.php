<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Integration\Api\TokenManager;
use Magento\Integration\Model\CustomUserContextFactory;

class PasskeyTokenService
{
    public function __construct(
        private readonly TokenManager $tokenManager,
        private readonly CustomUserContextFactory $userContextFactory
    ) {
    }

    public function createTokenForCustomer(int $customerId): string
    {
        $userContext = $this->userContextFactory->create([
            'userId' => $customerId,
            'userType' => UserContextInterface::USER_TYPE_CUSTOMER,
        ]);
        $params = $this->tokenManager->createUserTokenParameters();
        return $this->tokenManager->create($userContext, $params);
    }
}
