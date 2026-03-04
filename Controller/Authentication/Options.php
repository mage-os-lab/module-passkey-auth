<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Controller\Authentication;

use MageOS\PasskeyAuth\Api\AuthenticationOptionsInterface;
use MageOS\PasskeyAuth\Model\RateLimiter;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;

class Options implements HttpPostActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly AuthenticationOptionsInterface $authenticationOptions,
        private readonly RateLimiter $rateLimiter,
        private readonly JsonSerializer $json
    ) {
    }

    public function execute(): Json
    {
        $resultJson = $this->resultJsonFactory->create();

        try {
            $body = $this->json->unserialize($this->request->getContent());
            $email = $body['email'] ?? null;

            $this->rateLimiter->checkOptionsRate('auth_' . ($email ?? 'anonymous'));
            $optionsJson = $this->authenticationOptions->generate($email);

            return $resultJson->setData(json_decode($optionsJson, true));
        } catch (\Exception $e) {
            return $resultJson->setHttpResponseCode(400)->setData([
                'errors' => true,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
