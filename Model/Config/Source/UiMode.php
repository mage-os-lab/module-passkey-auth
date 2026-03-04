<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class UiMode implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'standard', 'label' => __('Standard')],
            ['value' => 'preferred', 'label' => __('Preferred')],
        ];
    }
}
