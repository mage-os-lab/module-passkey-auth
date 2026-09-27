<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model;

use MageOS\PasskeyAuth\Model\ResourceModel\Credential as CredentialResource;
use Magento\Framework\Model\AbstractModel;

class Credential extends AbstractModel
{
    protected function _construct(): void
    {
        $this->_init(CredentialResource::class);
    }
}
