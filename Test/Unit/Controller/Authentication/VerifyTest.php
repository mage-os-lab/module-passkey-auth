<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Controller\Authentication;

use MageOS\PasskeyAuth\Api\AuthenticationVerifierInterface;
use MageOS\PasskeyAuth\Api\Data\AuthenticationResultInterface;
use MageOS\PasskeyAuth\Controller\Authentication\Verify;
use MageOS\PasskeyAuth\Model\RateLimiter;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksCustomerSessionTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksJsonResultTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksLoggerTrait;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Framework\Stdlib\Cookie\CookieMetadata;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class VerifyTest extends TestCase
{
    use MocksCustomerSessionTrait;
    use MocksJsonResultTrait;
    use MocksLoggerTrait;

    private RequestInterface&MockObject $requestMock;
    private AuthenticationVerifierInterface&MockObject $verifierMock;
    private CustomerRepositoryInterface&MockObject $customerRepositoryMock;
    private RateLimiter&MockObject $rateLimiterMock;
    private JsonSerializer&MockObject $jsonMock;
    private CookieManagerInterface&MockObject $cookieManagerMock;
    private CookieMetadataFactory&MockObject $cookieMetadataFactoryMock;
    private Verify $controller;

    protected function setUp(): void
    {
        $this->createJsonResultMock();
        $this->createLoggerMock();
        $this->createCustomerSessionMock();

        $this->requestMock = $this->getMockBuilder(RequestInterface::class)
            ->addMethods(['getContent', 'getClientIp'])
            ->getMockForAbstractClass();

        $this->verifierMock = $this->createMock(AuthenticationVerifierInterface::class);
        $this->customerRepositoryMock = $this->createMock(CustomerRepositoryInterface::class);
        $this->rateLimiterMock = $this->createMock(RateLimiter::class);
        $this->jsonMock = $this->createMock(JsonSerializer::class);
        $this->cookieManagerMock = $this->createMock(CookieManagerInterface::class);
        $this->cookieMetadataFactoryMock = $this->createMock(CookieMetadataFactory::class);

        $this->controller = new Verify(
            $this->requestMock,
            $this->jsonFactoryMock,
            $this->verifierMock,
            $this->customerRepositoryMock,
            $this->customerSessionMock,
            $this->rateLimiterMock,
            $this->jsonMock,
            $this->cookieManagerMock,
            $this->cookieMetadataFactoryMock,
            $this->loggerMock
        );
    }

    private function configureRequestBody(array $body): void
    {
        $bodyJson = json_encode($body);
        $this->requestMock->method('getContent')->willReturn($bodyJson);
        $this->jsonMock->method('unserialize')
            ->with($bodyJson)
            ->willReturn($body);
    }

    private function configureClientIp(string $ip = '127.0.0.1'): void
    {
        $this->requestMock->method('getClientIp')->willReturn($ip);
    }

    private function createSuccessResult(int $customerId): AuthenticationResultInterface&MockObject
    {
        $result = $this->createMock(AuthenticationResultInterface::class);
        $result->method('getCustomerId')->willReturn($customerId);
        return $result;
    }

    public function testExecuteRateLimited(): void
    {
        $body = ['challengeToken' => 'tok', 'credential' => ['id' => 'abc']];
        $this->configureRequestBody($body);
        $this->configureClientIp('10.0.0.1');

        $this->rateLimiterMock->method('checkVerifyFailRate')
            ->with('10.0.0.1')
            ->willThrowException(new LocalizedException(
                __('Too many failed passkey attempts. Please try again later.')
            ));

        $this->rateLimiterMock->expects($this->once())
            ->method('recordVerifyFailure')
            ->with('10.0.0.1');

        $this->loggerMock->expects($this->once())
            ->method('error');

        $result = $this->controller->execute();

        $this->assertSame($this->jsonResultMock, $result);
        $this->assertSame(400, $this->capturedHttpCode);
        $this->assertTrue($this->capturedData['errors']);
    }

    public function testExecuteSuccess(): void
    {
        $body = ['challengeToken' => 'my-tok', 'credential' => ['id' => 'cred-1', 'response' => []]];
        $this->configureRequestBody($body);
        $this->configureClientIp();

        $credentialJson = '{"id":"cred-1","response":[]}';
        $this->jsonMock->method('serialize')
            ->with(['id' => 'cred-1', 'response' => []])
            ->willReturn($credentialJson);

        $authResult = $this->createSuccessResult(42);
        $this->verifierMock->expects($this->once())
            ->method('verify')
            ->with('my-tok', $credentialJson)
            ->willReturn($authResult);

        $customer = $this->createMock(CustomerInterface::class);
        $this->customerRepositoryMock->expects($this->once())
            ->method('getById')
            ->with(42)
            ->willReturn($customer);

        $this->customerSessionMock->expects($this->once())
            ->method('setCustomerDataAsLoggedIn')
            ->with($customer);

        $this->cookieManagerMock->method('getCookie')
            ->with('mage-cache-sessid')
            ->willReturn(null);

        $result = $this->controller->execute();

        $this->assertSame($this->jsonResultMock, $result);
        $this->assertNull($this->capturedHttpCode);
        $this->assertFalse($this->capturedData['errors']);
        $this->assertEquals('Login successful.', (string) $this->capturedData['message']);
    }

    public function testExecuteSuccessClearsCookie(): void
    {
        $body = ['challengeToken' => 'tok-2', 'credential' => ['id' => 'c2']];
        $this->configureRequestBody($body);
        $this->configureClientIp();

        $this->jsonMock->method('serialize')
            ->with(['id' => 'c2'])
            ->willReturn('{"id":"c2"}');

        $authResult = $this->createSuccessResult(10);
        $this->verifierMock->method('verify')->willReturn($authResult);

        $customer = $this->createMock(CustomerInterface::class);
        $this->customerRepositoryMock->method('getById')->with(10)->willReturn($customer);

        $this->cookieManagerMock->method('getCookie')
            ->with('mage-cache-sessid')
            ->willReturn('some-session-value');

        $cookieMetadata = $this->createMock(CookieMetadata::class);
        $this->cookieMetadataFactoryMock->expects($this->once())
            ->method('createCookieMetadata')
            ->willReturn($cookieMetadata);

        $cookieMetadata->expects($this->once())
            ->method('setPath')
            ->with('/');

        $this->cookieManagerMock->expects($this->once())
            ->method('deleteCookie')
            ->with('mage-cache-sessid', $cookieMetadata);

        $result = $this->controller->execute();

        $this->assertSame($this->jsonResultMock, $result);
        $this->assertNull($this->capturedHttpCode);
        $this->assertFalse($this->capturedData['errors']);
    }

    public function testExecuteSuccessNoCookie(): void
    {
        $body = ['challengeToken' => 'tok-3', 'credential' => ['id' => 'c3']];
        $this->configureRequestBody($body);
        $this->configureClientIp();

        $this->jsonMock->method('serialize')
            ->with(['id' => 'c3'])
            ->willReturn('{"id":"c3"}');

        $authResult = $this->createSuccessResult(20);
        $this->verifierMock->method('verify')->willReturn($authResult);

        $customer = $this->createMock(CustomerInterface::class);
        $this->customerRepositoryMock->method('getById')->with(20)->willReturn($customer);

        $this->cookieManagerMock->method('getCookie')
            ->with('mage-cache-sessid')
            ->willReturn(null);

        $this->cookieManagerMock->expects($this->never())
            ->method('deleteCookie');

        $this->cookieMetadataFactoryMock->expects($this->never())
            ->method('createCookieMetadata');

        $result = $this->controller->execute();

        $this->assertSame($this->jsonResultMock, $result);
        $this->assertNull($this->capturedHttpCode);
        $this->assertFalse($this->capturedData['errors']);
    }

    public function testExecuteLocalizedException(): void
    {
        $body = ['challengeToken' => 'tok-bad', 'credential' => ['id' => 'xx']];
        $this->configureRequestBody($body);
        $this->configureClientIp('192.168.1.1');

        $this->jsonMock->method('serialize')
            ->with(['id' => 'xx'])
            ->willReturn('{"id":"xx"}');

        $this->verifierMock->method('verify')
            ->willThrowException(new LocalizedException(__('Challenge expired.')));

        $this->rateLimiterMock->expects($this->once())
            ->method('recordVerifyFailure')
            ->with('192.168.1.1');

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with('Passkey authentication verify error', ['exception' => 'Challenge expired.']);

        $result = $this->controller->execute();

        $this->assertSame($this->jsonResultMock, $result);
        $this->assertSame(400, $this->capturedHttpCode);
        $this->assertTrue($this->capturedData['errors']);
        $this->assertEquals(
            'Passkey verification failed. Please try again.',
            (string) $this->capturedData['message']
        );
    }

    public function testExecuteGenericException(): void
    {
        $body = ['challengeToken' => 'tok-err', 'credential' => ['id' => 'yy']];
        $this->configureRequestBody($body);
        $this->configureClientIp('10.10.10.10');

        $this->jsonMock->method('serialize')
            ->with(['id' => 'yy'])
            ->willReturn('{"id":"yy"}');

        $this->verifierMock->method('verify')
            ->willThrowException(new \RuntimeException('Unexpected failure'));

        $this->rateLimiterMock->expects($this->once())
            ->method('recordVerifyFailure')
            ->with('10.10.10.10');

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with('Passkey authentication verify error', ['exception' => 'Unexpected failure']);

        $result = $this->controller->execute();

        $this->assertSame($this->jsonResultMock, $result);
        $this->assertSame(400, $this->capturedHttpCode);
        $this->assertTrue($this->capturedData['errors']);
        $this->assertEquals(
            'Passkey verification failed. Please try again.',
            (string) $this->capturedData['message']
        );
    }

    public function testValidateForCsrfNotImplemented(): void
    {
        $reflection = new \ReflectionClass(Verify::class);
        $this->assertFalse(
            $reflection->implementsInterface(\Magento\Framework\App\CsrfAwareActionInterface::class),
            'Verify controller should not implement CsrfAwareActionInterface'
        );
    }
}
