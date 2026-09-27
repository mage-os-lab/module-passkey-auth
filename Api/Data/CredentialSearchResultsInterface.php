<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Api\Data;

use Magento\Framework\Api\SearchResultsInterface;

/**
 * @api
 */
interface CredentialSearchResultsInterface extends SearchResultsInterface
{
    /**
     * @return \MageOS\PasskeyAuth\Api\Data\CredentialInterface[]
     */
    public function getItems();

    /**
     * @param \MageOS\PasskeyAuth\Api\Data\CredentialInterface[] $items
     * @return $this
     */
    public function setItems(array $items);
}
