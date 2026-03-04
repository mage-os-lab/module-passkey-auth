<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\ResourceModel\Challenge;

use MageOS\PasskeyAuth\Model\Challenge as ChallengeModel;
use MageOS\PasskeyAuth\Model\ResourceModel\Challenge as ChallengeResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(ChallengeModel::class, ChallengeResource::class);
    }
}
