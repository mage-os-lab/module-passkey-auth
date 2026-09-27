<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Controller\Account;

use MageOS\PasskeyAuth\Api\CredentialManagementInterface;
use MageOS\PasskeyAuth\Controller\Account\Delete;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksCustomerSessionTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksJsonResultTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksLoggerTrait;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class DeleteTest extends TestCase
{
    use MocksCustomerSessionTrait;
    use MocksJsonResultTrait;
    use MocksLoggerTrait;

    private HttpRequest&Stub $requestMock;
    private CredentialManagementInterface&Stub $credentialManagementMock;
    private ResultFactory&Stub $resultFactoryMock;
    private ?Delete $controller = null;

    protected function setUp(): void
    {
        $this->requestMock = $this->createStub(HttpRequest::class);

        $this->createJsonResultStub();
        $this->createCustomerSessionStub();
        $this->credentialManagementMock = $this->createStub(CredentialManagementInterface::class);
        $this->createLoggerStub();
        $this->resultFactoryMock = $this->createStub(ResultFactory::class);
    }

    private function controller(): Delete
    {
        return $this->controller ??= new Delete(
            $this->requestMock,
            $this->jsonFactoryMock,
            $this->customerSessionMock,
            $this->credentialManagementMock,
            $this->loggerMock,
            $this->resultFactoryMock
        );
    }

    private function configureEntityIdParam(string $entityId): void
    {
        $this->requestMock = $this->createMock(HttpRequest::class);
        $this->requestMock->method('getParam')
            ->with('entity_id')
            ->willReturn($entityId);
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

        $this->configureEntityIdParam('55');

        $this->credentialManagementMock = $this->createMock(CredentialManagementInterface::class);
        $this->credentialManagementMock->expects($this->once())
            ->method('deleteCredential')
            ->with(10, 55)
            ->willReturn(true);

        $this->controller()->execute();

        $this->assertNull($this->capturedHttpCode);
        $this->assertFalse($this->capturedData['errors']);
    }

    public function testExecuteLocalizedException(): void
    {
        $this->configureLoggedIn(10);

        $this->configureEntityIdParam('55');

        $this->credentialManagementMock->method('deleteCredential')
            ->willThrowException(new LocalizedException(new Phrase('Credential not found.')));

        $this->controller()->execute();

        $this->assertSame(400, $this->capturedHttpCode);
        $this->assertTrue($this->capturedData['errors']);
        $this->assertEquals('Credential not found.', (string) $this->capturedData['message']);
    }

    /**
     * @return array<string, array{\Throwable}>
     */
    public static function unexpectedErrorProvider(): array
    {
        return [
            'exception' => [new \RuntimeException('DB error')],
            'error' => [new \TypeError('DB error')],
        ];
    }

    #[DataProvider('unexpectedErrorProvider')]
    public function testExecuteGenericException(\Throwable $error): void
    {
        $this->configureLoggedIn(10);

        $this->configureEntityIdParam('55');

        $this->credentialManagementMock->method('deleteCredential')
            ->willThrowException($error);

        $this->mockLogger()->expects($this->once())
            ->method('error')
            ->with('Passkey delete error', ['exception' => 'DB error']);

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
