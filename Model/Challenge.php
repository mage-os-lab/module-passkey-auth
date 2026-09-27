<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model;

use MageOS\PasskeyAuth\Model\ResourceModel\Challenge as ChallengeResource;
use Magento\Framework\Model\AbstractModel;

class Challenge extends AbstractModel
{
    protected function _construct(): void
    {
        $this->_init(ChallengeResource::class);
    }
}
