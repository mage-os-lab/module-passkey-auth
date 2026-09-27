<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Observer;

use MageOS\PasskeyAuth\Model\Enrollment\NewAccountFlag;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * customer_register_success: remember the new account for the enrollment prompt.
 */
class FlagNewAccount implements ObserverInterface
{
    public function __construct(
        private readonly NewAccountFlag $newAccountFlag
    ) {
    }

    public function execute(Observer $observer): void
    {
        $customer = $observer->getEvent()->getData('customer');
        if ($customer instanceof CustomerInterface && $customer->getId()) {
            $this->newAccountFlag->set((int) $customer->getId());
        }
    }
}
