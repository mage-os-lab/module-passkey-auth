<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model;

use MageOS\PasskeyAuth\Api\CredentialRepositoryInterface;
use MageOS\PasskeyAuth\Api\Data\CredentialInterface;
use MageOS\PasskeyAuth\Model\Data\Credential as CredentialDTO;
use MageOS\PasskeyAuth\Model\Data\CredentialFactory as CredentialDTOFactory;
use MageOS\PasskeyAuth\Model\CredentialFactory as CredentialModelFactory;
use MageOS\PasskeyAuth\Model\ResourceModel\Credential as CredentialResource;
use MageOS\PasskeyAuth\Model\ResourceModel\Credential\CollectionFactory;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;

class CredentialRepository implements CredentialRepositoryInterface
{
    public function __construct(
        private readonly CredentialResource $resource,
        private readonly CredentialModelFactory $credentialFactory,
        private readonly CredentialDTOFactory $credentialDTOFactory,
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    public function getById(int $entityId): CredentialInterface
    {
        $model = $this->credentialFactory->create();
        $this->resource->load($model, $entityId);
        if (!$model->getId()) {
            throw new NoSuchEntityException(__('Passkey credential with ID "%1" does not exist.', $entityId));
        }
        return $this->toDTO($model);
    }

    public function getByCredentialId(string $credentialId): CredentialInterface
    {
        $model = $this->credentialFactory->create();
        $this->resource->load($model, $credentialId, 'credential_id');
        if (!$model->getId()) {
            throw new NoSuchEntityException(__('Passkey credential not found.'));
        }
        return $this->toDTO($model);
    }

    public function getByCustomerId(int $customerId): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('customer_id', $customerId);
        $collection->setOrder('created_at', 'DESC');

        $results = [];
        foreach ($collection as $model) {
            $results[] = $this->toDTO($model);
        }
        return $results;
    }

    public function save(CredentialInterface $credential): CredentialInterface
    {
        $this->validateCredential($credential);

        $model = $this->credentialFactory->create();
        if ($credential->getEntityId()) {
            $this->resource->load($model, $credential->getEntityId());
            if (!$model->getId()) {
                throw new CouldNotSaveException(__('Passkey credential with ID "%1" does not exist.', $credential->getEntityId()));
            }
        }

        $model->setData('customer_id', $credential->getCustomerId());
        $model->setData('credential_id', $credential->getCredentialId());
        $model->setData('public_key', $credential->getPublicKey());
        $model->setData('user_handle', $credential->getUserHandle());
        $model->setData('sign_count', $credential->getSignCount());
        $model->setData('transports', $credential->getTransports());
        $model->setData('friendly_name', $credential->getFriendlyName());
        $model->setData('aaguid', $credential->getAaguid());
        if ($credential->getLastUsedAt()) {
            $model->setData('last_used_at', $credential->getLastUsedAt());
        }

        try {
            $this->resource->save($model);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('Could not save passkey credential: %1', $e->getMessage()), $e);
        }

        return $this->toDTO($model);
    }

    public function delete(CredentialInterface $credential): bool
    {
        $model = $this->credentialFactory->create();
        $this->resource->load($model, $credential->getEntityId());
        if (!$model->getId()) {
            throw new CouldNotDeleteException(__('Passkey credential does not exist.'));
        }

        try {
            $this->resource->delete($model);
        } catch (\Exception $e) {
            throw new CouldNotDeleteException(__('Could not delete passkey credential: %1', $e->getMessage()), $e);
        }

        return true;
    }

    public function deleteById(int $entityId): bool
    {
        $credential = $this->getById($entityId);
        return $this->delete($credential);
    }

    public function countByCustomerId(int $customerId): int
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('customer_id', $customerId);
        return $collection->getSize();
    }

    private function validateCredential(CredentialInterface $credential): void
    {
        if ($credential->getCustomerId() <= 0) {
            throw new CouldNotSaveException(__('Invalid customer ID for passkey credential.'));
        }
        if ($credential->getCredentialId() === '') {
            throw new CouldNotSaveException(__('Credential ID cannot be empty.'));
        }
        if ($credential->getPublicKey() === '') {
            throw new CouldNotSaveException(__('Public key cannot be empty.'));
        }
        if ($credential->getSignCount() < 0) {
            throw new CouldNotSaveException(__('Sign count cannot be negative.'));
        }
    }

    private function toDTO(Credential $model): CredentialInterface
    {
        $dto = $this->credentialDTOFactory->create();
        $dto->setData($model->getData());
        return $dto;
    }
}
