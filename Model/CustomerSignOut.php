<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model;

use Magento\Customer\Api\SessionCleanerInterface;
use Magento\Integration\Api\CustomerTokenServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * Signs a customer out after a passkey is removed. Sign-in sessions and tokens are not tied to the passkey that
 * created them, so this works per customer. Failures are logged and reported to the caller, never thrown: the
 * passkey is already gone by then.
 */
class CustomerSignOut
{
    public function __construct(
        private readonly SessionCleanerInterface $sessionCleaner,
        private readonly CustomerTokenServiceInterface $customerTokenService,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * For a lost or stolen device: end every storefront session of the customer and revoke all their API tokens.
     *
     * @return bool False when either step failed
     */
    public function signOutEverywhere(int $customerId): bool
    {
        $sessionsEnded = $this->endOtherSessions($customerId);

        try {
            $this->customerTokenService->revokeCustomerAccessToken($customerId);
            return $sessionsEnded;
        } catch (\Exception $e) {
            $this->logger->error('Failed to revoke customer API tokens after passkey revocation', [
                'exception' => $e->getMessage(),
                'customer_id' => $customerId,
            ]);
            return false;
        }
    }

    /**
     * As core does when a customer changes their own password: end their other storefront sessions. Core's
     * SessionCleaner ends every session started before now, except the one making the request. API tokens are
     * kept.
     *
     * @return bool False when the sessions could not be ended
     */
    public function endOtherSessions(int $customerId): bool
    {
        try {
            $this->sessionCleaner->clearFor($customerId);
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to end customer sessions after passkey removal', [
                'exception' => $e->getMessage(),
                'customer_id' => $customerId,
            ]);
            return false;
        }
    }
}
