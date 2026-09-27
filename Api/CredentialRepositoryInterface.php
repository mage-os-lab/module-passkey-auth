<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Api;

use MageOS\PasskeyAuth\Api\Data\CredentialInterface;
use MageOS\PasskeyAuth\Api\Data\CredentialSearchResultsInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * @api
 */
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
     * @param \Magento\Framework\Api\SearchCriteriaInterface $searchCriteria
     * @return \MageOS\PasskeyAuth\Api\Data\CredentialSearchResultsInterface
     */
    public function getList(SearchCriteriaInterface $searchCriteria): CredentialSearchResultsInterface;

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
