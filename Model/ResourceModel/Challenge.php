<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Challenge extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('passkey_challenge', 'entity_id');
    }
}
