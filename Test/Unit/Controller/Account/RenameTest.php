<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Controller\Account;

use MageOS\PasskeyAuth\Api\CredentialManagementInterface;
use MageOS\PasskeyAuth\Api\Data\CredentialInterface;
use MageOS\PasskeyAuth\Controller\Account\Rename;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksCustomerSessionTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksJsonResultTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksLoggerTrait;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class RenameTest extends TestCase
{
    use MocksCustomerSessionTrait;
    use MocksJsonResultTrait;
    use MocksLoggerTrait;

    private HttpRequest&Stub $requestMock;
    private CredentialManagementInterface&Stub $credentialManagementMock;
    private JsonSerializer&Stub $jsonSerializerMock;
    private ResultFactory&Stub $resultFactoryMock;
    private ?Rename $controller = null;

    protected function setUp(): void
    {
        $this->requestMock = $this->createStub(HttpRequest::class);

        $this->createJsonResultStub();
        $this->createCustomerSessionStub();
        $this->credentialManagementMock = $this->createStub(CredentialManagementInterface::class);
        $this->jsonSerializerMock = $this->createStub(JsonSerializer::class);
        $this->createLoggerStub();
        $this->resultFactoryMock = $this->createStub(ResultFactory::class);
    }

    private function controller(): Rename
    {
        return $this->controller ??= new Rename(
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

        $this->controller()->execute();

        $this->assertSame(401, $this->capturedHttpCode);
        $this->assertTrue($this->capturedData['errors']);
    }

    public function testExecuteSuccess(): void
    {
        $this->configureLoggedIn(10);

        $this->requestMock->method('getContent')
            ->willReturn('{"entity_id":55,"friendly_name":"My YubiKey"}');

        $this->jsonSerializerMock = $this->createMock(JsonSerializer::class);
        $this->jsonSerializerMock->method('unserialize')
            ->with('{"entity_id":55,"friendly_name":"My YubiKey"}')
            ->willReturn(['entity_id' => 55, 'friendly_name' => 'My YubiKey']);

        $credentialMock = $this->createStub(CredentialInterface::class);
        $credentialMock->method('getFriendlyName')->willReturn('My YubiKey');

        $this->credentialManagementMock = $this->createMock(CredentialManagementInterface::class);
        $this->credentialManagementMock->expects($this->once())
            ->method('renameCredential')
            ->with(10, 55, 'My YubiKey')
            ->willReturn($credentialMock);

        $this->controller()->execute();

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

        $this->controller()->execute();

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

        $this->mockLogger()->expects($this->once())
            ->method('error')
            ->with('Passkey rename error', ['exception' => 'DB error']);

        $this->controller()->execute();

        $this->assertSame(400, $this->capturedHttpCode);
        $this->assertTrue($this->capturedData['errors']);
    }

    public function testValidateForCsrfWithAjaxHeader(): void
    {
        $requestMock = $this->createMock(HttpRequest::class);

        $requestMock->method('getHeader')
            ->with('X-Requested-With')
            ->willReturn('XMLHttpRequest');

        $result = $this->controller()->validateForCsrf($requestMock);

        $this->assertTrue($result);
    }
}
