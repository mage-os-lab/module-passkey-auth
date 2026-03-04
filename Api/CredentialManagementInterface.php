<?php

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
     * @throws AuthorizationException
     * @throws NoSuchEntityException
     */
    public function deleteCredential(int $customerId, int $entityId): bool;

    /**
     * @throws AuthorizationException
     * @throws NoSuchEntityException
     */
    public function renameCredential(int $customerId, int $entityId, string $friendlyName): CredentialInterface;
}
