<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Observer;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Dispatches customer_login after a REST, SOAP or GraphQL passkey sign-in, as core CustomerTokenService does for
 * password token requests, so "last logged in" and other customer_login observers run.
 *
 * Wired only in the web API and GraphQL areas: storefront sign-in already dispatches it through
 * Customer\Model\Session::setCustomerDataAsLoggedIn().
 */
class DispatchCustomerLogin implements ObserverInterface
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly EventManager $eventManager
    ) {
    }

    public function execute(Observer $observer): void
    {
        $customerId = (int) $observer->getEvent()->getData('customer_id');
        if ($customerId <= 0) {
            return;
        }

        $this->eventManager->dispatch('customer_login', [
            'customer' => $this->customerRepository->getById($customerId),
        ]);
    }
}
