<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\CustomerData;

use MageOS\PasskeyAuth\Api\CredentialRepositoryInterface;
use MageOS\PasskeyAuth\Model\Config;
use Magento\Customer\CustomerData\SectionSourceInterface;
use Magento\Customer\Model\Session as CustomerSession;

class PasskeySection implements SectionSourceInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly CustomerSession $customerSession,
        private readonly CredentialRepositoryInterface $credentialRepository
    ) {
    }

    public function getSectionData(): array
    {
        if (!$this->customerSession->isLoggedIn()
            || !$this->config->isEnabled()
            || !$this->config->isPromptAfterLoginEnabled()
        ) {
            return ['show_enrollment_prompt' => false];
        }

        $customerId = (int) $this->customerSession->getCustomerId();
        $hasPasskeys = $this->credentialRepository->countByCustomerId($customerId) > 0;

        return ['show_enrollment_prompt' => !$hasPasskeys];
    }
}
