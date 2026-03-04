<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class UserVerification implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'required', 'label' => __('Required')],
            ['value' => 'preferred', 'label' => __('Preferred')],
            ['value' => 'discouraged', 'label' => __('Discouraged')],
        ];
    }
}
