<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\Registration;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Exception\LocalizedException;

/**
 * Blocks passkey registration during a Login as Customer session, so an admin cannot add a passkey of their own
 * to the customer's account.
 *
 * Magento_LoginAsCustomer is optional, so this reads the customer session key its GetLoggedAsCustomerAdminId reads,
 * instead of depending on that module.
 */
class AdminImpersonationGuard
{
    /**
     * Core's key, spelling included (SetLoggedAsCustomerAdminId::execute() calls setLoggedAsCustomerAdmindId()).
     */
    private const SESSION_KEY_ADMIN_ID = 'logged_as_customer_admind_id';

    public function __construct(
        private readonly CustomerSession $customerSession
    ) {
    }

    /**
     * @throws LocalizedException While an admin is signed in as the customer
     */
    public function assertNotImpersonated(): void
    {
        if ((int) $this->customerSession->getData(self::SESSION_KEY_ADMIN_ID) > 0) {
            throw new LocalizedException(
                __('Passkeys can\'t be added while an admin is signed in as this customer.')
            );
        }
    }
}
