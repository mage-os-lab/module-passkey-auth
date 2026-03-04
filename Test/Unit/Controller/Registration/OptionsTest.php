<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Controller\Registration;

use MageOS\PasskeyAuth\Api\RegistrationOptionsInterface;
use MageOS\PasskeyAuth\Controller\Registration\Options;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksCustomerSessionTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksJsonResultTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksLoggerTrait;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class OptionsTest extends TestCase
{
    use MocksCustomerSessionTrait;
    use MocksJsonResultTrait;
    use MocksLoggerTrait;

    private MockObject $requestMock;
    private RegistrationOptionsInterface&MockObject $registrationOptionsMock;
    private ResultFactory&MockObject $resultFactoryMock;
    private Options $controller;

    protected function setUp(): void
    {
        $this->requestMock = $this->getMockBuilder(RequestInterface::class)
            ->addMethods(['getHeader', 'getContent'])
            ->getMockForAbstractClass();

        $this->createJsonResultMock();
        $this->createCustomerSessionMock();
        $this->registrationOptionsMock = $this->createMock(RegistrationOptionsInterface::class);
        $this->createLoggerMock();

        $this->resultFactoryMock = $this->createMock(ResultFactory::class);

        $this->controller = new Options(
            $this->requestMock,
            $this->jsonFactoryMock,
            $this->customerSessionMock,
            $this->registrationOptionsMock,
            $this->loggerMock,
            $this->resultFactoryMock
        );
    }

    public function testExecuteNotLoggedIn(): void
    {
        $this->configureNotLoggedIn();

        $result = $this->controller->execute();

        $this->assertSame($this->jsonResultMock, $result);
        $this->assertSame(401, $this->capturedHttpCode);
        $this->assertTrue($this->capturedData['errors']);
        $this->assertSame('Please sign in to register a passkey.', (string) $this->capturedData['message']);
    }

    public function testExecuteSuccess(): void
    {
        $this->configureLoggedIn(42);

        $optionsPayload = ['challenge' => 'abc123', 'rp' => ['name' => 'Test']];
        $this->registrationOptionsMock->method('generate')
            ->with(42)
            ->willReturn(json_encode($optionsPayload));

        $result = $this->controller->execute();

        $this->assertSame($this->jsonResultMock, $result);
        $this->assertNull($this->capturedHttpCode);
        $this->assertSame($optionsPayload, $this->capturedData);
    }

    public function testExecuteLocalizedException(): void
    {
        $this->configureLoggedIn(42);

        $this->registrationOptionsMock->method('generate')
            ->willThrowException(new LocalizedException(new Phrase('Too many credentials.')));

        $result = $this->controller->execute();

        $this->assertSame($this->jsonResultMock, $result);
        $this->assertSame(400, $this->capturedHttpCode);
        $this->assertTrue($this->capturedData['errors']);
        $this->assertSame('Too many credentials.', (string) $this->capturedData['message']);
    }

    public function testExecuteGenericException(): void
    {
        $this->configureLoggedIn(42);

        $this->registrationOptionsMock->method('generate')
            ->willThrowException(new \RuntimeException('Unexpected failure'));

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with('Passkey registration options error', ['exception' => 'Unexpected failure']);

        $result = $this->controller->execute();

        $this->assertSame($this->jsonResultMock, $result);
        $this->assertSame(400, $this->capturedHttpCode);
        $this->assertTrue($this->capturedData['errors']);
        $this->assertSame(
            'Unable to generate registration options. Please try again.',
            (string) $this->capturedData['message']
        );
    }

    public function testValidateForCsrfWithAjaxHeader(): void
    {
        $this->requestMock->method('getHeader')
            ->with('X-Requested-With')
            ->willReturn('XMLHttpRequest');

        $this->assertTrue($this->controller->validateForCsrf($this->requestMock));

        // Test createCsrfValidationException returns InvalidRequestException
        $csrfJsonMock = $this->createMock(Json::class);
        $csrfJsonMock->method('setHttpResponseCode')->willReturnSelf();
        $csrfJsonMock->method('setData')->willReturnSelf();

        $this->resultFactoryMock->method('create')
            ->with(ResultFactory::TYPE_JSON)
            ->willReturn($csrfJsonMock);

        $exception = $this->controller->createCsrfValidationException($this->requestMock);
        $this->assertInstanceOf(InvalidRequestException::class, $exception);
    }
}
