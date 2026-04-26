<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Controller\Authentication;

use MageOS\PasskeyAuth\Api\AuthenticationOptionsInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Psr\Log\LoggerInterface;

class Options implements HttpPostActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly AuthenticationOptionsInterface $authenticationOptions,
        private readonly JsonSerializer $json,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): Json
    {
        $resultJson = $this->resultJsonFactory->create();

        try {
            $body = $this->json->unserialize($this->request->getContent());
            if (!is_array($body)) {
                $body = [];
            }
            $email = isset($body['email']) && is_string($body['email']) ? $body['email'] : null;

            $optionsJson = $this->authenticationOptions->generate($email);
            $optionsData = json_decode($optionsJson, true);
            if (!is_array($optionsData)) {
                throw new LocalizedException(__('Unable to generate authentication options.'));
            }

            return $resultJson->setData($optionsData);
        } catch (LocalizedException $e) {
            return $resultJson->setHttpResponseCode(400)->setData([
                'errors' => true,
                'message' => $e->getMessage(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Passkey authentication options error', ['exception' => $e->getMessage()]);
            return $resultJson->setHttpResponseCode(400)->setData([
                'errors' => true,
                'message' => __('Unable to generate authentication options. Please try again.'),
            ]);
        }
    }
}
