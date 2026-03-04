<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model;

use MageOS\PasskeyAuth\Model\ResourceModel\Credential\CollectionFactory;

class UserHandleGenerator
{
    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    public function getOrGenerate(int $customerId): string
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('customer_id', $customerId);
        $collection->setPageSize(1);

        $existing = $collection->getFirstItem();
        if ($existing->getId()) {
            return (string) $existing->getData('user_handle');
        }

        return base64_encode(random_bytes(64));
    }
}
