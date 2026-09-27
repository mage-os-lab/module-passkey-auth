<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Observer;

use MageOS\PasskeyAuth\Api\Data\CredentialInterface;
use MageOS\PasskeyAuth\Model\Email\CredentialNotifier;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Emails the customer when a passkey is added or removed. Wired per event in
 * di.xml with the config path of the email template to send.
 */
class NotifyCredentialChange implements ObserverInterface
{
    public function __construct(
        private readonly CredentialNotifier $notifier,
        private readonly string $templatePath
    ) {
    }

    public function execute(Observer $observer): void
    {
        $credential = $observer->getEvent()->getData('credential');
        if (!$credential instanceof CredentialInterface || $credential->getCustomerId() <= 0) {
            return;
        }

        $this->notifier->notify($credential->getCustomerId(), $credential->getFriendlyName(), $this->templatePath);
    }
}
