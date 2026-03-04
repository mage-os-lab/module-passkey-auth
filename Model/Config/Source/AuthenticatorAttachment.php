<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class AuthenticatorAttachment implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => '', 'label' => __('Any')],
            ['value' => 'platform', 'label' => __('Platform (Built-in Biometrics)')],
            ['value' => 'cross-platform', 'label' => __('Cross-Platform (Security Keys)')],
        ];
    }
}
