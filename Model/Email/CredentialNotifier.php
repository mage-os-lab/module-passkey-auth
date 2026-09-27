<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\Email;

use MageOS\PasskeyAuth\Model\Config;
use Magento\Customer\Api\CustomerNameGenerationInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\Area;
use Magento\Framework\Exception\NoSuchEntityException;
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
        private readonly CustomerNameGenerationInterface $customerNameGeneration,
        private readonly TransportBuilder $transportBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param int $customerId
     * @param string|null $friendlyName
     * @param string $templatePath Config path holding the email template ID
     */
    public function notify(int $customerId, ?string $friendlyName, string $templatePath): void
    {
        try {
            $customer = $this->customerRepository->getById($customerId);
            $storeId = $this->getStoreId($customer);

            if (!$this->config->isCredentialNotificationEnabled($storeId)) {
                return;
            }

            $customerName = $this->customerNameGeneration->getCustomerName($customer);

            $transport = $this->transportBuilder
                ->setTemplateIdentifier($this->config->getEmailTemplate($templatePath, $storeId))
                ->setTemplateOptions([
                    'area' => Area::AREA_FRONTEND,
                    'store' => $storeId,
                ])
                ->setTemplateVars([
                    'customer_name' => $customerName,
                    'passkey_name' => $friendlyName ?: (string) __('Unnamed passkey'),
                    'store_name' => $this->storeManager->getStore($storeId)->getFrontendName(),
                ])
                ->setFromByScope($this->config->getNotificationIdentity($storeId), $storeId)
                ->addTo($customer->getEmail(), $customerName)
                ->getTransport();

            $transport->sendMessage();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send passkey notification email', [
                'exception' => $e->getMessage(),
                'customer_id' => $customerId,
                'template' => $templatePath,
            ]);
        }
    }

    /**
     * The customer's store, or for accounts created in the admin (store 0) their website's default store,
     * as core AccountManagement does for customer emails. The default store view when the website is gone.
     */
    private function getStoreId(CustomerInterface $customer): int
    {
        $storeId = (int) $customer->getStoreId();
        if ($storeId !== 0) {
            return $storeId;
        }

        $websiteId = (int) $customer->getWebsiteId();
        if ($websiteId !== 0) {
            try {
                $defaultStore = $this->storeManager->getWebsite($websiteId)->getDefaultStore();
            } catch (NoSuchEntityException) {
                $defaultStore = null;
            }
            if ($defaultStore && $defaultStore->getId()) {
                return (int) $defaultStore->getId();
            }
        }

        return (int) $this->storeManager->getDefaultStoreView()->getId();
    }
}
