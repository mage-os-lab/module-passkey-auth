<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Api;

use MageOS\PasskeyAuth\Api\Data\CredentialInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;

interface CredentialRepositoryInterface
{
    /**
     * @throws NoSuchEntityException
     */
    public function getById(int $entityId): CredentialInterface;

    /**
     * @throws NoSuchEntityException
     */
    public function getByCredentialId(string $credentialId): CredentialInterface;

    /**
     * @return CredentialInterface[]
     */
    public function getByCustomerId(int $customerId): array;

    /**
     * @throws CouldNotSaveException
     */
    public function save(CredentialInterface $credential): CredentialInterface;

    /**
     * @throws CouldNotDeleteException
     */
    public function delete(CredentialInterface $credential): bool;

    /**
     * @throws CouldNotDeleteException
     * @throws NoSuchEntityException
     */
    public function deleteById(int $entityId): bool;

    public function countByCustomerId(int $customerId): int;
}
