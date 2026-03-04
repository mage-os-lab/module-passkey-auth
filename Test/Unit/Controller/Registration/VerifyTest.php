<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Controller\Registration;

use MageOS\PasskeyAuth\Api\Data\CredentialInterface;
use MageOS\PasskeyAuth\Api\RegistrationVerifierInterface;
use MageOS\PasskeyAuth\Controller\Registration\Verify;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksCustomerSessionTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksJsonResultTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksLoggerTrait;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class VerifyTest extends TestCase
{
    use MocksCustomerSessionTrait;
    use MocksJsonResultTrait;
    use MocksLoggerTrait;

    private MockObject $requestMock;
    private RegistrationVerifierInterface&MockObject $registrationVerifierMock;
    private JsonSerializer&MockObject $jsonSerializerMock;
    private ResultFactory&MockObject $resultFactoryMock;
    private Verify $controller;

    protected function setUp(): void
    {
        $this->requestMock = $this->getMockBuilder(RequestInterface::class)
            ->addMethods(['getHeader', 'getContent'])
            ->getMockForAbstractClass();

        $this->createJsonResultMock();
        $this->createCustomerSessionMock();
        $this->registrationVerifierMock = $this->createMock(RegistrationVerifierInterface::class);
        $this->jsonSerializerMock = $this->createMock(JsonSerializer::class);
        $this->createLoggerMock();

        $this->resultFactoryMock = $this->createMock(ResultFactory::class);

        $this->controller = new Verify(
            $this->requestMock,
            $this->jsonFactoryMock,
            $this->customerSessionMock,
            $this->registrationVerifierMock,
            $this->jsonSerializerMock,
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

        $requestBody = [
            'challengeToken' => 'token-abc',
            'credential' => ['id' => 'cred-123', 'type' => 'public-key'],
            'friendlyName' => 'My YubiKey',
        ];

        $this->requestMock->method('getContent')
            ->willReturn(json_encode($requestBody));

        $this->jsonSerializerMock->method('unserialize')
            ->with(json_encode($requestBody))
            ->willReturn($requestBody);

        $serializedCredential = '{"id":"cred-123","type":"public-key"}';
        $this->jsonSerializerMock->method('serialize')
            ->with($requestBody['credential'])
            ->willReturn($serializedCredential);

        $credentialMock = $this->createMock(CredentialInterface::class);
        $credentialMock->method('getEntityId')->willReturn(99);
        $credentialMock->method('getFriendlyName')->willReturn('My YubiKey');
        $credentialMock->method('getCreatedAt')->willReturn('2026-03-04 12:00:00');

        $this->registrationVerifierMock->expects($this->once())
            ->method('verify')
            ->with(42, 'token-abc', $serializedCredential, 'My YubiKey')
            ->willReturn($credentialMock);

        $result = $this->controller->execute();

        $this->assertSame($this->jsonResultMock, $result);
        $this->assertNull($this->capturedHttpCode);
        $this->assertFalse($this->capturedData['errors']);
        $this->assertSame('Passkey registered successfully.', (string) $this->capturedData['message']);
        $this->assertSame(99, $this->capturedData['credential']['entity_id']);
        $this->assertSame('My YubiKey', $this->capturedData['credential']['friendly_name']);
        $this->assertSame('2026-03-04 12:00:00', $this->capturedData['credential']['created_at']);
    }

    public function testExecuteLocalizedException(): void
    {
        $this->configureLoggedIn(42);

        $requestBody = ['challengeToken' => 'tok', 'credential' => [], 'friendlyName' => null];

        $this->requestMock->method('getContent')
            ->willReturn(json_encode($requestBody));

        $this->jsonSerializerMock->method('unserialize')
            ->willReturn($requestBody);

        $this->jsonSerializerMock->method('serialize')
            ->willReturn('[]');

        $this->registrationVerifierMock->method('verify')
            ->willThrowException(new LocalizedException(new Phrase('Challenge expired.')));

        $result = $this->controller->execute();

        $this->assertSame($this->jsonResultMock, $result);
        $this->assertSame(400, $this->capturedHttpCode);
        $this->assertTrue($this->capturedData['errors']);
        $this->assertSame('Challenge expired.', (string) $this->capturedData['message']);
    }

    public function testExecuteGenericException(): void
    {
        $this->configureLoggedIn(42);

        $requestBody = ['challengeToken' => 'tok', 'credential' => [], 'friendlyName' => null];

        $this->requestMock->method('getContent')
            ->willReturn(json_encode($requestBody));

        $this->jsonSerializerMock->method('unserialize')
            ->willReturn($requestBody);

        $this->jsonSerializerMock->method('serialize')
            ->willReturn('[]');

        $this->registrationVerifierMock->method('verify')
            ->willThrowException(new \RuntimeException('Something broke'));

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with('Passkey registration verify error', ['exception' => 'Something broke']);

        $result = $this->controller->execute();

        $this->assertSame($this->jsonResultMock, $result);
        $this->assertSame(400, $this->capturedHttpCode);
        $this->assertTrue($this->capturedData['errors']);
        $this->assertSame(
            'Passkey registration failed. Please try again.',
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
