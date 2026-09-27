<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\CustomerData;

use MageOS\PasskeyAuth\Api\CredentialRepositoryInterface;
use MageOS\PasskeyAuth\Model\Config;
use MageOS\PasskeyAuth\Model\Enrollment\NewAccountFlag;
use Magento\Customer\CustomerData\SectionSourceInterface;
use Magento\Customer\Model\Session as CustomerSession;

class PasskeySection implements SectionSourceInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly CustomerSession $customerSession,
        private readonly CredentialRepositoryInterface $credentialRepository,
        private readonly NewAccountFlag $newAccountFlag
    ) {
    }

    public function getSectionData(): array
    {
        if (!$this->customerSession->isLoggedIn() || !$this->config->isEnabled()) {
            return ['show_enrollment_prompt' => false];
        }

        $customerId = (int) $this->customerSession->getCustomerId();
        $promptEnabled = $this->newAccountFlag->isSetFor($customerId)
            ? $this->config->isPromptOnRegistrationEnabled()
            : $this->config->isPromptAfterLoginEnabled();
        if (!$promptEnabled) {
            return ['show_enrollment_prompt' => false];
        }

        $hasPasskeys = $this->credentialRepository->countByCustomerId($customerId) > 0;

        return ['show_enrollment_prompt' => !$hasPasskeys];
    }
}
