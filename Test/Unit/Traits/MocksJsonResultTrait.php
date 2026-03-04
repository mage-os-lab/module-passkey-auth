<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Traits;

use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use PHPUnit\Framework\MockObject\MockObject;

trait MocksJsonResultTrait
{
    private JsonFactory&MockObject $jsonFactoryMock;
    private Json&MockObject $jsonResultMock;
    private ?int $capturedHttpCode = null;
    private ?array $capturedData = null;

    private function createJsonResultMock(): JsonFactory&MockObject
    {
        $this->jsonFactoryMock = $this->createMock(JsonFactory::class);
        $this->jsonResultMock = $this->createMock(Json::class);

        $this->jsonResultMock->method('setHttpResponseCode')
            ->willReturnCallback(function (int $code): Json&MockObject {
                $this->capturedHttpCode = $code;
                return $this->jsonResultMock;
            });

        $this->jsonResultMock->method('setData')
            ->willReturnCallback(function (array $data): Json&MockObject {
                $this->capturedData = $data;
                return $this->jsonResultMock;
            });

        $this->jsonFactoryMock->method('create')->willReturn($this->jsonResultMock);

        return $this->jsonFactoryMock;
    }
}
