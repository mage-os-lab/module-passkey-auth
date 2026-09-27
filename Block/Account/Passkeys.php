<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Block\Account;

use MageOS\PasskeyAuth\Api\CredentialRepositoryInterface;
use MageOS\PasskeyAuth\Api\Data\CredentialInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

class Passkeys extends Template
{
    public function __construct(
        Context $context,
        private readonly CustomerSession $customerSession,
        private readonly CredentialRepositoryInterface $credentialRepository,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return CredentialInterface[]
     */
    public function getCredentials(): array
    {
        $customerId = (int) $this->customerSession->getCustomerId();
        return $this->credentialRepository->getByCustomerId($customerId);
    }

    public function getCreatedLabel(CredentialInterface $credential): string
    {
        $createdAt = $credential->getCreatedAt();
        return $createdAt ? $this->formatDate($createdAt, \IntlDateFormatter::MEDIUM) : '';
    }

    public function getLastUsedLabel(CredentialInterface $credential): string
    {
        $lastUsedAt = $credential->getLastUsedAt();
        return $lastUsedAt
            ? $this->formatDate($lastUsedAt, \IntlDateFormatter::MEDIUM, true)
            : (string) __('Never');
    }

    public function getRegistrationOptionsUrl(): string
    {
        return $this->getUrl('passkey/registration/options');
    }

    public function getRegistrationVerifyUrl(): string
    {
        return $this->getUrl('passkey/registration/verify');
    }

    public function getDeleteUrl(): string
    {
        return $this->getUrl('passkey/account/delete');
    }

    public function getRenameUrl(): string
    {
        return $this->getUrl('passkey/account/rename');
    }
}
