<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\Email;

use MageOS\PasskeyAuth\Model\Config;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Area;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends account-security notification emails when a passkey is added to or
 * removed from a customer account. Failures are logged, never thrown — a
 * notification problem must not break registration, deletion, or login.
 */
class CredentialNotifier
{
    public function __construct(
        private readonly Config $config,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly TransportBuilder $transportBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function notifyAdded(int $customerId, ?string $friendlyName): void
    {
        $this->send($customerId, 'added', $friendlyName);
    }

    public function notifyRemoved(int $customerId, ?string $friendlyName): void
    {
        $this->send($customerId, 'removed', $friendlyName);
    }

    private function send(int $customerId, string $template, ?string $friendlyName): void
    {
        try {
            $customer = $this->customerRepository->getById($customerId);
            $storeId = (int) $customer->getStoreId();
            if ($storeId === 0) {
                $storeId = (int) $this->storeManager->getDefaultStoreView()->getId();
            }

            if (!$this->config->isCredentialNotificationEnabled($storeId)) {
                return;
            }

            $store = $this->storeManager->getStore($storeId);
            $customerName = trim($customer->getFirstname() . ' ' . $customer->getLastname());
            $templateId = $template === 'added'
                ? $this->config->getAddedEmailTemplate($storeId)
                : $this->config->getRemovedEmailTemplate($storeId);

            $transport = $this->transportBuilder
                ->setTemplateIdentifier($templateId)
                ->setTemplateOptions([
                    'area' => Area::AREA_FRONTEND,
                    'store' => $storeId,
                ])
                ->setTemplateVars([
                    'customer_name' => $customerName,
                    'passkey_name' => $friendlyName ?: (string) __('Unnamed passkey'),
                    'store_name' => $store->getFrontendName(),
                ])
                ->setFromByScope($this->config->getNotificationIdentity($storeId), $storeId)
                ->addTo($customer->getEmail(), $customerName)
                ->getTransport();

            $transport->sendMessage();
        } catch (\Exception $e) {
            $this->logger->error('Failed to send passkey notification email', [
                'exception' => $e->getMessage(),
                'customer_id' => $customerId,
                'template' => $template,
            ]);
        }
    }
}
