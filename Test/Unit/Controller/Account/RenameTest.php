<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Controller\Account;

use MageOS\PasskeyAuth\Api\CredentialManagementInterface;
use MageOS\PasskeyAuth\Api\Data\CredentialInterface;
use MageOS\PasskeyAuth\Controller\Account\Rename;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksCustomerSessionTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksJsonResultTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksLoggerTrait;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RenameTest extends TestCase
{
    use MocksCustomerSessionTrait;
    use MocksJsonResultTrait;
    use MocksLoggerTrait;

    private RequestInterface&MockObject $requestMock;
    private CredentialManagementInterface&MockObject $credentialManagementMock;
    private JsonSerializer&MockObject $jsonSerializerMock;
    private ResultFactory&MockObject $resultFactoryMock;
    private Rename $controller;

    protected function setUp(): void
    {
        $this->requestMock = $this->getMockBuilder(RequestInterface::class)
            ->addMethods(['getContent', 'getHeader'])
            ->getMockForAbstractClass();

        $this->createJsonResultMock();
        $this->createCustomerSessionMock();
        $this->credentialManagementMock = $this->createMock(CredentialManagementInterface::class);
        $this->jsonSerializerMock = $this->createMock(JsonSerializer::class);
        $this->createLoggerMock();
        $this->resultFactoryMock = $this->createMock(ResultFactory::class);

        $this->controller = new Rename(
            $this->requestMock,
            $this->jsonFactoryMock,
            $this->customerSessionMock,
            $this->credentialManagementMock,
            $this->jsonSerializerMock,
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

        $this->requestMock->method('getContent')
            ->willReturn('{"entity_id":55,"friendly_name":"My YubiKey"}');

        $this->jsonSerializerMock->method('unserialize')
            ->with('{"entity_id":55,"friendly_name":"My YubiKey"}')
            ->willReturn(['entity_id' => 55, 'friendly_name' => 'My YubiKey']);

        $credentialMock = $this->createMock(CredentialInterface::class);
        $credentialMock->method('getFriendlyName')->willReturn('My YubiKey');

        $this->credentialManagementMock->expects($this->once())
            ->method('renameCredential')
            ->with(10, 55, 'My YubiKey')
            ->willReturn($credentialMock);

        $this->controller->execute();

        $this->assertNull($this->capturedHttpCode);
        $this->assertFalse($this->capturedData['errors']);
        $this->assertSame('My YubiKey', $this->capturedData['friendly_name']);
    }

    public function testExecuteLocalizedException(): void
    {
        $this->configureLoggedIn(10);

        $this->requestMock->method('getContent')
            ->willReturn('{"entity_id":55,"friendly_name":""}');

        $this->jsonSerializerMock->method('unserialize')
            ->willReturn(['entity_id' => 55, 'friendly_name' => '']);

        $this->credentialManagementMock->method('renameCredential')
            ->willThrowException(new LocalizedException(new Phrase('Passkey name cannot be empty.')));

        $this->controller->execute();

        $this->assertSame(400, $this->capturedHttpCode);
        $this->assertTrue($this->capturedData['errors']);
        $this->assertEquals('Passkey name cannot be empty.', (string) $this->capturedData['message']);
    }

    public function testExecuteGenericException(): void
    {
        $this->configureLoggedIn(10);

        $this->requestMock->method('getContent')
            ->willReturn('{"entity_id":55,"friendly_name":"Test"}');

        $this->jsonSerializerMock->method('unserialize')
            ->willReturn(['entity_id' => 55, 'friendly_name' => 'Test']);

        $this->credentialManagementMock->method('renameCredential')
            ->willThrowException(new \RuntimeException('DB error'));

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with('Passkey rename error', ['exception' => 'DB error']);

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
