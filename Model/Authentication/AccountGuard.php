<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\Authentication;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\GroupExcludedWebsiteRepositoryInterface;
use Magento\Customer\Model\AccountConfirmation;
use Magento\Customer\Model\AuthenticationInterface;
use Magento\Framework\Exception\AuthenticationException;
use Magento\Framework\Exception\EmailNotConfirmedException;
use Magento\Framework\Exception\State\UserLockedException;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The account checks core password sign-in makes (Customer\Model\AccountManagement\Authenticate), for passkey
 * sign-in. Failed passkey attempts never count toward core's lockout: credential IDs are public, so anyone could
 * otherwise lock a customer out. An existing lock is honoured.
 */
class AccountGuard
{
    public function __construct(
        private readonly AuthenticationInterface $authentication,
        private readonly AccountConfirmation $accountConfirmation,
        private readonly GroupExcludedWebsiteRepositoryInterface $groupExcludedWebsiteRepository,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @throws UserLockedException
     * @throws EmailNotConfirmedException
     * @throws AuthenticationException When the customer's group is excluded from the current website
     */
    public function assertCanSignIn(CustomerInterface $customer): void
    {
        $customerId = (int) $customer->getId();
        if ($this->authentication->isLocked($customerId)) {
            throw new UserLockedException(__('The account is locked.'));
        }

        if ($customer->getConfirmation() && $this->isConfirmationRequired($customer)) {
            throw new EmailNotConfirmedException(__('This account isn\'t confirmed. Verify and try again.'));
        }

        // Core checks this in its customer_customer_authenticated observer (Observer\CustomerGroupAuthenticate)
        if ($customer->getGroupId() && $this->isGroupExcludedFromWebsite((int) $customer->getGroupId())) {
            throw new AuthenticationException(__('This website is excluded from customer\'s group.'));
        }
    }

    /**
     * Reset the failed password sign-in count, as core does after a successful sign-in.
     */
    public function recordSignIn(int $customerId): void
    {
        $this->authentication->unlock($customerId);
    }

    private function isConfirmationRequired(CustomerInterface $customer): bool
    {
        $websiteId = (int) $customer->getWebsiteId();
        $customerId = (int) $customer->getId();

        return $this->accountConfirmation->isConfirmationRequired($websiteId, $customerId, $customer->getEmail())
            || $this->accountConfirmation->isEmailChangedConfirmationRequired(
                $websiteId,
                $customerId,
                $customer->getEmail()
            );
    }

    private function isGroupExcludedFromWebsite(int $groupId): bool
    {
        $excluded = $this->groupExcludedWebsiteRepository->getCustomerGroupExcludedWebsites($groupId);

        return in_array((int) $this->storeManager->getStore()->getWebsiteId(), array_map('intval', $excluded), true);
    }
}
