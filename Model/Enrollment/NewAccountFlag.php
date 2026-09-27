<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\Enrollment;

use Magento\Customer\Model\Session as CustomerSession;

/**
 * Remembers, for the rest of the session, that the customer created their account in it, so the
 * enrollment prompt follows "Prompt After Account Creation" instead of "Prompt After Password Login".
 */
class NewAccountFlag
{
    private const SESSION_KEY = 'passkey_new_account_id';

    public function __construct(
        private readonly CustomerSession $customerSession
    ) {
    }

    public function set(int $customerId): void
    {
        $this->customerSession->setData(self::SESSION_KEY, $customerId);
    }

    public function isSetFor(int $customerId): bool
    {
        return $customerId > 0 && (int) $this->customerSession->getData(self::SESSION_KEY) === $customerId;
    }
}
