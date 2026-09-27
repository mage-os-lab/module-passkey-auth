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
use Magento\Customer\Model\CustomerRegistry;
use Magento\Framework\Exception\AuthenticationException;
use Magento\Framework\Exception\EmailNotConfirmedException;
use Magento\Framework\Exception\State\UserLockedException;
use Magento\Framework\Phrase;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

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
        private readonly StoreManagerInterface $storeManager,
        private readonly CustomerRegistry $customerRegistry,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Throws with the message core shows for a password sign-in, and logs the specific reason.
     *
     * @throws UserLockedException
     * @throws EmailNotConfirmedException
     * @throws AuthenticationException When the customer's group is excluded from the current website
     */
    public function assertCanSignIn(CustomerInterface $customer): void
    {
        $customerId = (int) $customer->getId();
        if ($this->authentication->isLocked($customerId)) {
            throw $this->refused($customerId, 'Account is locked', new UserLockedException($this->notAllowed()));
        }

        if ($customer->getConfirmation() && $this->isConfirmationRequired($customer)) {
            throw $this->refused(
                $customerId,
                'Account is not confirmed',
                new EmailNotConfirmedException(__('This account isn\'t confirmed. Verify and try again.'))
            );
        }

        // Core checks this in its customer_customer_authenticated observer (Observer\CustomerGroupAuthenticate)
        if ($customer->getGroupId() && $this->isGroupExcludedFromWebsite((int) $customer->getGroupId())) {
            throw $this->refused(
                $customerId,
                'Customer group is excluded from this website',
                new AuthenticationException($this->notAllowed())
            );
        }
    }

    /**
     * Reset the failed password sign-in count, as core does after a successful sign-in. Skipped when there is
     * nothing to reset, since unlock() always saves the customer.
     */
    public function recordSignIn(int $customerId): void
    {
        $secure = $this->customerRegistry->retrieveSecureData($customerId);
        if ($secure->getFailuresNum() || $secure->getFirstFailure() || $secure->getLockExpires()) {
            $this->authentication->unlock($customerId);
        }
    }

    /**
     * Core's message for a locked account (LoginPost, CustomerTokenService). It doesn't say which check failed.
     */
    private function notAllowed(): Phrase
    {
        return __(
            'The account sign-in was incorrect or your account is disabled temporarily. '
            . 'Please wait and try again later.'
        );
    }

    private function refused(int $customerId, string $reason, AuthenticationException $e): AuthenticationException
    {
        $this->logger->warning('Passkey sign-in refused', [
            'customer_id' => $customerId,
            'reason' => $reason,
        ]);

        return $e;
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
