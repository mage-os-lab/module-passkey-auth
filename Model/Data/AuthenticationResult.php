<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\Data;

use MageOS\PasskeyAuth\Api\Data\AuthenticationResultInterface;
use Magento\Framework\DataObject;

class AuthenticationResult extends DataObject implements AuthenticationResultInterface
{
    public function getCustomerId(): int
    {
        return (int) $this->getData('customer_id');
    }

    public function getToken(): string
    {
        return (string) $this->getData('token');
    }
}
