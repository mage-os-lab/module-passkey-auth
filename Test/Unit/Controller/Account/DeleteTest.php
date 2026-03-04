<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Controller\Account;

use MageOS\PasskeyAuth\Api\CredentialManagementInterface;
use MageOS\PasskeyAuth\Controller\Account\Delete;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksCustomerSessionTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksJsonResultTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksLoggerTrait;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DeleteTest extends TestCase
{
    use MocksCustomerSessionTrait;
    use MocksJsonResultTrait;
    use MocksLoggerTrait;

    private RequestInterface&MockObject $requestMock;
    private CredentialManagementInterface&MockObject $credentialManagementMock;
    private ResultFactory&MockObject $resultFactoryMock;
    private Delete $controller;

    protected function setUp(): void
    {
        $this->requestMock = $this->getMockBuilder(RequestInterface::class)
            ->addMethods(['getHeader'])
            ->getMockForAbstractClass();

        $this->createJsonResultMock();
        $this->createCustomerSessionMock();
        $this->credentialManagementMock = $this->createMock(CredentialManagementInterface::class);
        $this->createLoggerMock();
        $this->resultFactoryMock = $this->createMock(ResultFactory::class);

        $this->controller = new Delete(
            $this->requestMock,
            $this->jsonFactoryMock,
            $this->customerSessionMock,
            $this->credentialManagementMock,
            $this->loggerMock,
            $this->resultFactoryMock
        );
    }

    public function testExecuteNotLoggedIn(): void
    {
        $this->configureNotLoggedIn();

        $this->controller->execute();

        $this->assertSame(401, $this->capturedHttpCode);
        $this->assertTrue($this->capturedData['errors']);
    }

    public function testExecuteSuccess(): void
    {
        $this->configureLoggedIn(10);

        $this->requestMock->method('getParam')
            ->with('entity_id')
            ->willReturn('55');

        $this->credentialManagementMock->expects($this->once())
            ->method('deleteCredential')
            ->with(10, 55)
            ->willReturn(true);

        $this->controller->execute();

        $this->assertNull($this->capturedHttpCode);
        $this->assertFalse($this->capturedData['errors']);
    }

    public function testExecuteLocalizedException(): void
    {
        $this->configureLoggedIn(10);

        $this->requestMock->method('getParam')
            ->with('entity_id')
            ->willReturn('55');

        $this->credentialManagementMock->method('deleteCredential')
            ->willThrowException(new LocalizedException(new Phrase('Credential not found.')));

        $this->controller->execute();

        $this->assertSame(400, $this->capturedHttpCode);
        $this->assertTrue($this->capturedData['errors']);
        $this->assertEquals('Credential not found.', (string) $this->capturedData['message']);
    }

    public function testExecuteGenericException(): void
    {
        $this->configureLoggedIn(10);

        $this->requestMock->method('getParam')
            ->with('entity_id')
            ->willReturn('55');

        $this->credentialManagementMock->method('deleteCredential')
            ->willThrowException(new \RuntimeException('DB error'));

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with('Passkey delete error', ['exception' => 'DB error']);

        $this->controller->execute();

        $this->assertSame(400, $this->capturedHttpCode);
        $this->assertTrue($this->capturedData['errors']);
    }

    public function testValidateForCsrfWithAjaxHeader(): void
    {
        $requestMock = $this->getMockBuilder(RequestInterface::class)
            ->addMethods(['getHeader'])
            ->getMockForAbstractClass();

        $requestMock->method('getHeader')
            ->with('X-Requested-With')
            ->willReturn('XMLHttpRequest');

        $result = $this->controller->validateForCsrf($requestMock);

        $this->assertTrue($result);
    }
}
