<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Controller\Registration;

use MageOS\PasskeyAuth\Api\RegistrationOptionsInterface;
use MageOS\PasskeyAuth\Model\RateLimiter;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Json;

class Options implements HttpPostActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly CustomerSession $customerSession,
        private readonly RegistrationOptionsInterface $registrationOptions,
        private readonly RateLimiter $rateLimiter
    ) {
    }

    public function execute(): Json
    {
        $resultJson = $this->resultJsonFactory->create();

        if (!$this->customerSession->isLoggedIn()) {
            return $resultJson->setHttpResponseCode(401)->setData([
                'errors' => true,
                'message' => __('Please sign in to register a passkey.'),
            ]);
        }

        try {
            $customerId = (int) $this->customerSession->getCustomerId();
            $this->rateLimiter->checkOptionsRate('reg_' . $customerId);
            $optionsJson = $this->registrationOptions->generate($customerId);

            return $resultJson->setData(json_decode($optionsJson, true));
        } catch (\Exception $e) {
            return $resultJson->setHttpResponseCode(400)->setData([
                'errors' => true,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
