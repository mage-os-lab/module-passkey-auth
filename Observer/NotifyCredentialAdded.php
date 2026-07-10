<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Observer;

use MageOS\PasskeyAuth\Api\Data\CredentialInterface;
use MageOS\PasskeyAuth\Model\Email\CredentialNotifier;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class NotifyCredentialAdded implements ObserverInterface
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

        $credential = $observer->getEvent()->getData('credential');
        $friendlyName = $credential instanceof CredentialInterface
            ? $credential->getFriendlyName()
            : null;

        $this->notifier->notifyAdded($customerId, $friendlyName);
    }
}
