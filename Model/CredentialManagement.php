<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model;

use MageOS\PasskeyAuth\Api\CredentialManagementInterface;
use MageOS\PasskeyAuth\Api\CredentialRepositoryInterface;
use MageOS\PasskeyAuth\Api\Data\CredentialInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\LocalizedException;

class CredentialManagement implements CredentialManagementInterface
{
    public function __construct(
        private readonly CredentialRepositoryInterface $credentialRepository,
        private readonly EventManager $eventManager,
        private readonly CustomerSignOut $customerSignOut
    ) {
    }

    public function listCredentials(int $customerId): array
    {
        return $this->credentialRepository->getByCustomerId($customerId);
    }

    public function deleteCredential(int $customerId, int $entityId): bool
    {
        $credential = $this->credentialRepository->getById($entityId);
        $this->assertOwnership($credential, $customerId);
        $this->remove($credential);
        // Like a password change: sessions it may have started elsewhere end, this one stays
        $this->customerSignOut->endOtherSessions($customerId);

        return true;
    }

    public function revokeCredential(CredentialInterface $credential): bool
    {
        $this->remove($credential);

        // A lost or stolen device may still hold a session or token from this passkey
        return $this->customerSignOut->signOutEverywhere($credential->getCustomerId());
    }

    public function renameCredential(int $customerId, int $entityId, string $friendlyName): CredentialInterface
    {
        $friendlyName = trim($friendlyName);
        $this->validateFriendlyName($friendlyName);

        $credential = $this->credentialRepository->getById($entityId);
        $this->assertOwnership($credential, $customerId);

        $credential->setFriendlyName($friendlyName);
        return $this->credentialRepository->save($credential);
    }

    public function validateFriendlyName(string $friendlyName): void
    {
        $friendlyName = trim($friendlyName);
        if ($friendlyName === '') {
            throw new LocalizedException(__('Passkey name cannot be empty.'));
        }
        if (mb_strlen($friendlyName) > 255) {
            throw new LocalizedException(__('Passkey name must be 255 characters or fewer.'));
        }
        if (preg_match('/[<>&]/', $friendlyName)) {
            throw new LocalizedException(__('Passkey names can\'t contain <, > or &.'));
        }
    }

    private function remove(CredentialInterface $credential): void
    {
        $this->credentialRepository->delete($credential);

        $this->eventManager->dispatch(PasskeyEvents::CREDENTIAL_REMOVE_AFTER, [
            'customer_id' => $credential->getCustomerId(),
            'entity_id' => $credential->getEntityId(),
            'credential_id' => $credential->getCredentialId(),
            'credential' => $credential,
        ]);
    }

    private function assertOwnership(CredentialInterface $credential, int $customerId): void
    {
        if ($credential->getCustomerId() !== $customerId) {
            throw new AuthorizationException(__('You are not authorized to manage this passkey.'));
        }
    }
}
