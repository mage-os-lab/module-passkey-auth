<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Observer;

use MageOS\PasskeyAuth\Api\CredentialRepositoryInterface;
use MageOS\PasskeyAuth\Model\Config;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class SetEnrollmentPromptFlag implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly CredentialRepositoryInterface $credentialRepository,
        private readonly CustomerSession $customerSession
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled() || !$this->config->isPromptAfterLoginEnabled()) {
            return;
        }

        $customer = $observer->getEvent()->getData('customer');
        if (!$customer) {
            return;
        }

        $customerId = (int) $customer->getId();
        if ($this->credentialRepository->countByCustomerId($customerId) === 0) {
            $this->customerSession->setData('show_passkey_enrollment_prompt', true);
        }
    }
}
