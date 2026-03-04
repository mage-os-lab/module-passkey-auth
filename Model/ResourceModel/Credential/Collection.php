<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\ResourceModel\Credential;

use MageOS\PasskeyAuth\Model\Credential as CredentialModel;
use MageOS\PasskeyAuth\Model\ResourceModel\Credential as CredentialResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(CredentialModel::class, CredentialResource::class);
    }
}
