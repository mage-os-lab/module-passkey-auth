<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model;

use MageOS\PasskeyAuth\Model\ResourceModel\Credential\CollectionFactory;

class UserHandleGenerator
{
    /**
     * WebAuthn caps user.id at 64 bytes.
     */
    private const MAX_HANDLE_BYTES = 64;

    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * Return the customer's WebAuthn user handle as raw bytes, reusing the oldest usable stored one.
     *
     * The user_handle column holds base64 of the raw handle. Earlier releases reused the stored value
     * itself as the next raw handle, so later rows can hold longer, drifted handles; skip any that
     * no longer fit.
     */
    public function getOrGenerate(int $customerId): string
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('customer_id', $customerId);
        $collection->addFieldToSelect('user_handle');
        $collection->setOrder('entity_id', 'ASC');

        foreach ($collection as $credential) {
            $handle = base64_decode((string) $credential->getData('user_handle'), true);
            if ($handle !== false && $handle !== '' && strlen($handle) <= self::MAX_HANDLE_BYTES) {
                return $handle;
            }
        }

        return random_bytes(32);
    }
}
