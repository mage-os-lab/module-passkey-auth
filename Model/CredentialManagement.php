<?php

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
        private readonly EventManager $eventManager
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

        $this->credentialRepository->delete($credential);

        $this->eventManager->dispatch('passkey_credential_remove_after', [
            'customer_id' => $customerId,
            'credential_id' => $entityId,
        ]);

        return true;
    }

    public function renameCredential(int $customerId, int $entityId, string $friendlyName): CredentialInterface
    {
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
            throw new LocalizedException(__('Passkey name contains invalid characters.'));
        }
    }

    private function assertOwnership(CredentialInterface $credential, int $customerId): void
    {
        if ($credential->getCustomerId() !== $customerId) {
            throw new AuthorizationException(__('You are not authorized to manage this passkey.'));
        }
    }
}
