<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Traits;

use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use PHPUnit\Framework\MockObject\Stub;

trait MocksJsonResultTrait
{
    private JsonFactory&Stub $jsonFactoryMock;
    private Json&Stub $jsonResultMock;
    private ?int $capturedHttpCode = null;
    private ?array $capturedData = null;

    private function createJsonResultStub(): JsonFactory&Stub
    {
        $this->jsonFactoryMock = $this->createStub(JsonFactory::class);
        $this->jsonResultMock = $this->createStub(Json::class);

        $this->jsonResultMock->method('setHttpResponseCode')
            ->willReturnCallback(function (int $code): Json {
                $this->capturedHttpCode = $code;
                return $this->jsonResultMock;
            });

        $this->jsonResultMock->method('setData')
            ->willReturnCallback(function (array $data): Json {
                $this->capturedData = $data;
                return $this->jsonResultMock;
            });

        $this->jsonFactoryMock->method('create')->willReturn($this->jsonResultMock);

        return $this->jsonFactoryMock;
    }
}
