<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Controller\Registration;

use MageOS\PasskeyAuth\Api\RegistrationOptionsInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

class Options implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly CustomerSession $customerSession,
        private readonly RegistrationOptionsInterface $registrationOptions,
        private readonly LoggerInterface $logger,
        private readonly ResultFactory $resultFactory
    ) {
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        /** @var Json $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $result->setHttpResponseCode(403);
        $result->setData(['errors' => true, 'message' => __('Invalid security token.')]);
        return new InvalidRequestException($result);
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return $request->getHeader('X-Requested-With') === 'XMLHttpRequest' ? true : null;
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
            $optionsJson = $this->registrationOptions->generate($customerId);
            $optionsData = json_decode($optionsJson, true);
            if (!is_array($optionsData)) {
                throw new LocalizedException(__('Unable to generate registration options.'));
            }

            return $resultJson->setData($optionsData);
        } catch (LocalizedException $e) {
            return $resultJson->setHttpResponseCode(400)->setData([
                'errors' => true,
                'message' => $e->getMessage(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Passkey registration options error', ['exception' => $e->getMessage()]);
            return $resultJson->setHttpResponseCode(400)->setData([
                'errors' => true,
                'message' => __('Unable to generate registration options. Please try again.'),
            ]);
        }
    }
}
