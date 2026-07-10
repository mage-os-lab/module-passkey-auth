<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\AdminTfa;

use Magento\Framework\Exception\LocalizedException;

class OriginValidator
{
    public function __construct(
        private readonly AdminTfaConfig $adminTfaConfig
    ) {
    }

    public function validate(array $credentialConfig): void
    {
        $storedRpId = $credentialConfig['rp_id'] ?? null;
        if ($storedRpId === null) {
            return;
        }

        $currentRpId = $this->adminTfaConfig->getRpId();
        if ($storedRpId !== $currentRpId) {
            throw new LocalizedException(__(
                'The admin domain has changed since your passkey was registered '
                . '(was "%1", now "%2"). Please ask an administrator to reset your '
                . 'passkey configuration.',
                $storedRpId,
                $currentRpId
            ));
        }
    }
}
