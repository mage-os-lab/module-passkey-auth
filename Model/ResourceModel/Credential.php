<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Credential extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('passkey_credential', 'entity_id');
    }
}
