<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class AttestationConveyance implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'none', 'label' => __('None')],
            ['value' => 'indirect', 'label' => __('Indirect')],
            ['value' => 'direct', 'label' => __('Direct')],
        ];
    }
}
