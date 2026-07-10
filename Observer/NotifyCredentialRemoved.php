<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Observer;

use MageOS\PasskeyAuth\Model\Email\CredentialNotifier;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class NotifyCredentialRemoved implements ObserverInterface
{
    public function __construct(
        private readonly CredentialNotifier $notifier
    ) {
    }

    public function execute(Observer $observer): void
    {
        $customerId = (int) $observer->getEvent()->getData('customer_id');
        if ($customerId <= 0) {
            return;
        }

        $friendlyName = $observer->getEvent()->getData('friendly_name');

        $this->notifier->notifyRemoved(
            $customerId,
            is_string($friendlyName) ? $friendlyName : null
        );
    }
}
