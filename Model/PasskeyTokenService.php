<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Integration\Api\TokenManager;
use Magento\Integration\Model\CustomUserContext;

class PasskeyTokenService
{
    public function __construct(
        private readonly TokenManager $tokenManager
    ) {
    }

    public function createTokenForCustomer(int $customerId): string
    {
        $userContext = new CustomUserContext($customerId, UserContextInterface::USER_TYPE_CUSTOMER);
        $params = $this->tokenManager->createUserTokenParameters();
        return $this->tokenManager->create($userContext, $params);
    }
}
