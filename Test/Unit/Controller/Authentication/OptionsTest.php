<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Controller\Authentication;

use MageOS\PasskeyAuth\Api\AuthenticationOptionsInterface;
use MageOS\PasskeyAuth\Controller\Authentication\Options;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksJsonResultTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksLoggerTrait;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class OptionsTest extends TestCase
{
    use MocksJsonResultTrait;
    use MocksLoggerTrait;

    private RequestInterface&MockObject $requestMock;
    private AuthenticationOptionsInterface&MockObject $authOptionsMock;
    private JsonSerializer&MockObject $jsonMock;
    private Options $controller;

    protected function setUp(): void
    {
        $this->createJsonResultMock();
        $this->createLoggerMock();

        $this->requestMock = $this->getMockBuilder(RequestInterface::class)
            ->addMethods(['getContent'])
            ->getMockForAbstractClass();

        $this->authOptionsMock = $this->createMock(AuthenticationOptionsInterface::class);
        $this->jsonMock = $this->createMock(JsonSerializer::class);

        $this->controller = new Options(
            $this->requestMock,
            $this->jsonFactoryMock,
            $this->authOptionsMock,
            $this->jsonMock,
            $this->loggerMock
        );
    }

    public function testExecuteSuccess(): void
    {
        $bodyJson = '{"email":"user@example.com"}';
        $this->requestMock->method('getContent')->willReturn($bodyJson);

        $this->jsonMock->method('unserialize')
            ->with($bodyJson)
            ->willReturn(['email' => 'user@example.com']);

        $optionsJson = '{"challenge":"abc","rpId":"example.com","challengeToken":"tok-1"}';
        $this->authOptionsMock->expects($this->once())
            ->method('generate')
            ->with('user@example.com')
            ->willReturn($optionsJson);

        $result = $this->controller->execute();

        $this->assertSame($this->jsonResultMock, $result);
        $this->assertNull($this->capturedHttpCode);
        $this->assertEquals(
            ['challenge' => 'abc', 'rpId' => 'example.com', 'challengeToken' => 'tok-1'],
            $this->capturedData
        );
    }

    public function testExecuteWithoutEmail(): void
    {
        $bodyJson = '{"foo":"bar"}';
        $this->requestMock->method('getContent')->willReturn($bodyJson);

        $this->jsonMock->method('unserialize')
            ->with($bodyJson)
            ->willReturn(['foo' => 'bar']);

        $optionsJson = '{"challenge":"xyz"}';
        $this->authOptionsMock->expects($this->once())
            ->method('generate')
            ->with(null)
            ->willReturn($optionsJson);

        $result = $this->controller->execute();

        $this->assertSame($this->jsonResultMock, $result);
        $this->assertNull($this->capturedHttpCode);
        $this->assertEquals(['challenge' => 'xyz'], $this->capturedData);
    }

    public function testExecuteLocalizedException(): void
    {
        $bodyJson = '{"email":"bad@example.com"}';
        $this->requestMock->method('getContent')->willReturn($bodyJson);

        $this->jsonMock->method('unserialize')
            ->with($bodyJson)
            ->willReturn(['email' => 'bad@example.com']);

        $this->authOptionsMock->method('generate')
            ->willThrowException(new LocalizedException(__('Passkey authentication is not enabled.')));

        $result = $this->controller->execute();

        $this->assertSame($this->jsonResultMock, $result);
        $this->assertSame(400, $this->capturedHttpCode);
        $this->assertTrue($this->capturedData['errors']);
        $this->assertEquals('Passkey authentication is not enabled.', (string) $this->capturedData['message']);
    }

    public function testExecuteGenericException(): void
    {
        $bodyJson = '{"email":"user@example.com"}';
        $this->requestMock->method('getContent')->willReturn($bodyJson);

        $this->jsonMock->method('unserialize')
            ->with($bodyJson)
            ->willReturn(['email' => 'user@example.com']);

        $this->authOptionsMock->method('generate')
            ->willThrowException(new \RuntimeException('Something broke'));

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with('Passkey authentication options error', ['exception' => 'Something broke']);

        $result = $this->controller->execute();

        $this->assertSame($this->jsonResultMock, $result);
        $this->assertSame(400, $this->capturedHttpCode);
        $this->assertTrue($this->capturedData['errors']);
        $this->assertEquals(
            'Unable to generate authentication options. Please try again.',
            (string) $this->capturedData['message']
        );
    }
}
