<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Api;

use MageOS\PasskeyAuth\Api\Data\CredentialInterface;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * @api
 */
interface CredentialManagementInterface
{
    /**
     * @param int $customerId
     * @return CredentialInterface[]
     */
    public function listCredentials(int $customerId): array;

    /**
     * Delete one of the customer's own passkeys, then end the customer's other storefront sessions, as core does
     * when a customer changes their password. The current session and API tokens are kept.
     *
     * @param int $customerId
     * @param int $entityId
     * @return bool
     * @throws AuthorizationException
     * @throws NoSuchEntityException
     */
    public function deleteCredential(int $customerId, int $entityId): bool;

    /**
     * Delete a credential without an ownership check (admin revocation). Does not sign the customer out: the
     * admin controllers do that once per customer with Model\CustomerSignOut::signOutEverywhere().
     *
     * @param CredentialInterface $credential
     * @return void
     */
    public function revokeCredential(CredentialInterface $credential): void;

    /**
     * @param int $customerId
     * @param int $entityId
     * @param string $friendlyName
     * @return CredentialInterface
     * @throws AuthorizationException
     * @throws NoSuchEntityException
     */
    public function renameCredential(int $customerId, int $entityId, string $friendlyName): CredentialInterface;
}
