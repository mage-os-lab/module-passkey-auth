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
     * @return \MageOS\PasskeyAuth\Api\Data\CredentialInterface[]
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
     * Delete a credential without an ownership check (admin revocation), then sign the customer out everywhere:
     * end all their storefront sessions and revoke their API tokens, for a lost or stolen device.
     *
     * @param \MageOS\PasskeyAuth\Api\Data\CredentialInterface $credential
     * @return bool False when the credential was deleted but the customer could not be signed out (logged)
     */
    public function revokeCredential(CredentialInterface $credential): bool;

    /**
     * @param int $customerId
     * @param int $entityId
     * @param string $friendlyName
     * @return \MageOS\PasskeyAuth\Api\Data\CredentialInterface
     * @throws AuthorizationException
     * @throws NoSuchEntityException
     */
    public function renameCredential(int $customerId, int $entityId, string $friendlyName): CredentialInterface;
}
