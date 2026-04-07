<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\AdminTfa;

use Magento\Framework\DataObject;
use Magento\User\Api\Data\UserInterface;

/**
 * Placeholder — full implementation in Task 5.
 */
class Authenticate
{
    public function verifyAssertion(UserInterface $user, DataObject $request): bool
    {
        return false;
    }
}
