<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Controller\Authentication;

use MageOS\PasskeyAuth\Api\AuthenticationVerifierInterface;
use MageOS\PasskeyAuth\Api\Data\AuthenticationResultInterface;
use MageOS\PasskeyAuth\Controller\Authentication\Verify;
use MageOS\PasskeyAuth\Model\Authentication\PostLoginRedirect;
use MageOS\PasskeyAuth\Model\Exception\RateLimitExceededException;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksCustomerSessionTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksJsonResultTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksLoggerTrait;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Url as CustomerUrl;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Exception\AuthenticationException;
use Magento\Framework\Exception\EmailNotConfirmedException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\State\UserLockedException;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Framework\Stdlib\Cookie\CookieMetadata;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class VerifyTest extends TestCase
{
    use MocksCustomerSessionTrait;
    use MocksJsonResultTrait;
    use MocksLoggerTrait;

    private HttpRequest&Stub $requestMock;
    private AuthenticationVerifierInterface&Stub $verifierMock;
    private CustomerRepositoryInterface&Stub $customerRepositoryMock;
    private JsonSerializer&Stub $jsonMock;
    private CookieManagerInterface&Stub $cookieManagerMock;
    private CookieMetadataFactory&Stub $cookieMetadataFactoryMock;
    private PostLoginRedirect&Stub $postLoginRedirectStub;
    private CustomerUrl&Stub $customerUrlStub;
    private ?Verify $controller = null;

    protected function setUp(): void
    {
        $this->createJsonResultStub();
        $this->createLoggerStub();
        $this->createCustomerSessionStub();

        $this->requestMock = $this->createStub(HttpRequest::class);

        $this->verifierMock = $this->createStub(AuthenticationVerifierInterface::class);
        $this->customerRepositoryMock = $this->createStub(CustomerRepositoryInterface::class);
        $this->jsonMock = $this->createStub(JsonSerializer::class);
        $this->cookieManagerMock = $this->createStub(CookieManagerInterface::class);
        $this->cookieMetadataFactoryMock = $this->createStub(CookieMetadataFactory::class);
        $this->postLoginRedirectStub = $this->createStub(PostLoginRedirect::class);
        $this->postLoginRedirectStub->method('getUrl')->willReturn('https://example.com/checkout/');
        $this->customerUrlStub = $this->createStub(CustomerUrl::class);
        $this->customerUrlStub->method('getAccountUrl')->willReturn('https://example.com/customer/account/');
    }

    private function controller(): Verify
    {
        return $this->controller ??= new Verify(
            $this->requestMock,
            $this->jsonFactoryMock,
            $this->verifierMock,
            $this->customerRepositoryMock,
            $this->customerSessionMock,
            $this->jsonMock,
            $this->cookieManagerMock,
            $this->cookieMetadataFactoryMock,
            $this->loggerMock,
            $this->postLoginRedirectStub,
            $this->customerUrlStub
        );
    }

    private function configureRequestBody(array $body): void
    {
        $bodyJson = json_encode($body);
        $this->requestMock->method('getContent')->willReturn($bodyJson);
        $this->jsonMock = $this->createMock(JsonSerializer::class);
        $this->jsonMock->method('unserialize')
            ->with($bodyJson)
            ->willReturn($body);
    }

    private function createSuccessResult(int $customerId): AuthenticationResultInterface&Stub
    {
        $result = $this->createStub(AuthenticationResultInterface::class);
        $result->method('getCustomerId')->willReturn($customerId);
        return $result;
    }

    public function testExecuteSuccess(): void
    {
        $body = ['challengeToken' => 'my-tok', 'credential' => ['id' => 'cred-1', 'response' => []]];
        $this->configureRequestBody($body);

        $credentialJson = '{"id":"cred-1","response":[]}';
        $this->jsonMock->method('serialize')
            ->with(['id' => 'cred-1', 'response' => []])
            ->willReturn($credentialJson);

        $authResult = $this->createSuccessResult(42);
        $this->verifierMock = $this->createMock(AuthenticationVerifierInterface::class);
        $this->verifierMock->expects($this->once())
            ->method('verify')
            ->with('my-tok', $credentialJson)
            ->willReturn($authResult);

        $customer = $this->createStub(CustomerInterface::class);
        $this->customerRepositoryMock = $this->createMock(CustomerRepositoryInterface::class);
        $this->customerRepositoryMock->expects($this->once())
            ->method('getById')
            ->with(42)
            ->willReturn($customer);

        $this->mockCustomerSession()->expects($this->once())
            ->method('setCustomerDataAsLoggedIn')
            ->with($customer);

        $this->cookieManagerMock = $this->createMock(CookieManagerInterface::class);
        $this->cookieManagerMock->method('getCookie')
            ->with('mage-cache-sessid')
            ->willReturn(null);

        $result = $this->controller()->execute();

        $this->assertSame($this->jsonResultMock, $result);
        $this->assertNull($this->capturedHttpCode);
        $this->assertFalse($this->capturedData['errors']);
        $this->assertEquals('Login successful.', (string) $this->capturedData['message']);
        $this->assertSame('https://example.com/checkout/', $this->capturedData['redirect_url']);
    }

    public function testExecuteSuccessClearsCookie(): void
    {
        $body = ['challengeToken' => 'tok-2', 'credential' => ['id' => 'c2']];
        $this->configureRequestBody($body);

        $this->jsonMock->method('serialize')
            ->with(['id' => 'c2'])
            ->willReturn('{"id":"c2"}');

        $authResult = $this->createSuccessResult(10);
        $this->verifierMock->method('verify')->willReturn($authResult);

        $customer = $this->createStub(CustomerInterface::class);
        $this->customerRepositoryMock = $this->createMock(CustomerRepositoryInterface::class);
        $this->customerRepositoryMock->method('getById')->with(10)->willReturn($customer);

        $this->cookieManagerMock = $this->createMock(CookieManagerInterface::class);
        $this->cookieManagerMock->method('getCookie')
            ->with('mage-cache-sessid')
            ->willReturn('some-session-value');

        $cookieMetadata = $this->createMock(CookieMetadata::class);
        $this->cookieMetadataFactoryMock = $this->createMock(CookieMetadataFactory::class);
        $this->cookieMetadataFactoryMock->expects($this->once())
            ->method('createCookieMetadata')
            ->willReturn($cookieMetadata);

        $cookieMetadata->expects($this->once())
            ->method('setPath')
            ->with('/');

        $this->cookieManagerMock->expects($this->once())
            ->method('deleteCookie')
            ->with('mage-cache-sessid', $cookieMetadata);

        $result = $this->controller()->execute();

        $this->assertSame($this->jsonResultMock, $result);
        $this->assertNull($this->capturedHttpCode);
        $this->assertFalse($this->capturedData['errors']);
    }

    public function testExecuteSuccessNoCookie(): void
    {
        $body = ['challengeToken' => 'tok-3', 'credential' => ['id' => 'c3']];
        $this->configureRequestBody($body);

        $this->jsonMock->method('serialize')
            ->with(['id' => 'c3'])
            ->willReturn('{"id":"c3"}');

        $authResult = $this->createSuccessResult(20);
        $this->verifierMock->method('verify')->willReturn($authResult);

        $customer = $this->createStub(CustomerInterface::class);
        $this->customerRepositoryMock = $this->createMock(CustomerRepositoryInterface::class);
        $this->customerRepositoryMock->method('getById')->with(20)->willReturn($customer);

        $this->cookieManagerMock = $this->createMock(CookieManagerInterface::class);
        $this->cookieManagerMock->method('getCookie')
            ->with('mage-cache-sessid')
            ->willReturn(null);

        $this->cookieManagerMock->expects($this->never())
            ->method('deleteCookie');

        $this->cookieMetadataFactoryMock = $this->createMock(CookieMetadataFactory::class);
        $this->cookieMetadataFactoryMock->expects($this->never())
            ->method('createCookieMetadata');

        $result = $this->controller()->execute();

        $this->assertSame($this->jsonResultMock, $result);
        $this->assertNull($this->capturedHttpCode);
        $this->assertFalse($this->capturedData['errors']);
    }

    public function testExecuteRejectionShowsGenericMessageWithoutLoggingAgain(): void
    {
        $body = ['challengeToken' => 'tok-bad', 'credential' => ['id' => 'xx']];
        $this->configureRequestBody($body);

        $this->jsonMock->method('serialize')
            ->with(['id' => 'xx'])
            ->willReturn('{"id":"xx"}');

        $this->verifierMock->method('verify')
            ->willThrowException(new LocalizedException(__('Challenge expired.')));

        // The verifier logs rejections
        $this->mockLogger()->expects($this->never())->method('warning');
        $this->mockLogger()->expects($this->never())->method('error');

        $result = $this->controller()->execute();

        $this->assertSame($this->jsonResultMock, $result);
        $this->assertSame(400, $this->capturedHttpCode);
        $this->assertTrue($this->capturedData['errors']);
        $this->assertEquals(
            'Passkey verification failed. Please try again.',
            (string) $this->capturedData['message']
        );
    }

    public function testExecuteShowsRateLimitMessage(): void
    {
        $this->configureRequestBody(['challengeToken' => 'tok', 'credential' => ['id' => 'zz']]);
        $this->jsonMock->method('serialize')->willReturn('{"id":"zz"}');
        $this->verifierMock->method('verify')->willThrowException(
            new RateLimitExceededException(__('Too many failed passkey attempts. Please try again later.'))
        );
        $this->mockLogger()->expects($this->never())->method('warning');
        $this->mockLogger()->expects($this->never())->method('error');

        $this->controller()->execute();

        $this->assertSame(429, $this->capturedHttpCode);
        $this->assertTrue($this->capturedData['errors']);
        $this->assertSame(
            'Too many failed passkey attempts. Please try again later.',
            (string) $this->capturedData['message']
        );
    }

    /**
     * @return array<string, array{AuthenticationException, string}>
     */
    public static function refusedAccountProvider(): array
    {
        return [
            // LoginPost's wording for a locked account
            'locked' => [
                new UserLockedException(__('The account is locked.')),
                'The account sign-in was incorrect or your account is disabled temporarily. '
                    . 'Please wait and try again later.',
            ],
            'not confirmed' => [
                new EmailNotConfirmedException(__('This account isn\'t confirmed. Verify and try again.')),
                'This account isn\'t confirmed. Verify and try again.',
            ],
            'group excluded' => [
                new AuthenticationException(__('This website is excluded from customer\'s group.')),
                'This website is excluded from customer\'s group.',
            ],
        ];
    }

    #[DataProvider('refusedAccountProvider')]
    public function testExecuteShowsRefusedAccountMessage(AuthenticationException $exception, string $message): void
    {
        $this->configureRequestBody(['challengeToken' => 'tok', 'credential' => ['id' => 'r1']]);
        $this->jsonMock->method('serialize')->willReturn('{"id":"r1"}');
        $this->verifierMock->method('verify')->willThrowException($exception);
        $this->mockCustomerSession()->expects($this->never())->method('setCustomerDataAsLoggedIn');
        $this->mockLogger()->expects($this->never())->method('error');

        $this->controller()->execute();

        $this->assertSame(403, $this->capturedHttpCode);
        $this->assertTrue($this->capturedData['errors']);
        $this->assertSame($message, (string) $this->capturedData['message']);
    }

    public function testExecuteFailsWhenCustomerCannotBeLoaded(): void
    {
        $this->configureRequestBody(['challengeToken' => 'tok', 'credential' => ['id' => 'c4']]);
        $this->jsonMock->method('serialize')->willReturn('{"id":"c4"}');
        $this->verifierMock->method('verify')->willReturn($this->createSuccessResult(30));
        $this->customerRepositoryMock->method('getById')
            ->willThrowException(new NoSuchEntityException(__('No such entity.')));

        $this->mockCustomerSession()->expects($this->never())->method('setCustomerDataAsLoggedIn');
        $this->mockLogger()->expects($this->once())
            ->method('error')
            ->with('Passkey authentication verify error', ['exception' => 'No such entity.']);

        $this->controller()->execute();

        $this->assertSame(400, $this->capturedHttpCode);
        $this->assertTrue($this->capturedData['errors']);
    }

    public function testExecuteFallsBackToAccountPageWhenRedirectFails(): void
    {
        $this->configureRequestBody(['challengeToken' => 'tok', 'credential' => ['id' => 'c5']]);
        $this->jsonMock->method('serialize')->willReturn('{"id":"c5"}');
        $this->verifierMock->method('verify')->willReturn($this->createSuccessResult(40));
        $customer = $this->createStub(CustomerInterface::class);
        $this->customerRepositoryMock->method('getById')->willReturn($customer);

        $this->postLoginRedirectStub = $this->createStub(PostLoginRedirect::class);
        $this->postLoginRedirectStub->method('getUrl')->willThrowException(new \RuntimeException('Store not found'));

        $this->mockCustomerSession()->expects($this->once())
            ->method('setCustomerDataAsLoggedIn')
            ->with($customer);
        $this->mockLogger()->expects($this->once())
            ->method('error')
            ->with('Passkey sign-in redirect error', ['exception' => 'Store not found']);

        $this->controller()->execute();

        $this->assertNull($this->capturedHttpCode);
        $this->assertFalse($this->capturedData['errors']);
        $this->assertSame('https://example.com/customer/account/', $this->capturedData['redirect_url']);
    }

    public function testExecuteGenericException(): void
    {
        $body = ['challengeToken' => 'tok-err', 'credential' => ['id' => 'yy']];
        $this->configureRequestBody($body);

        $this->jsonMock->method('serialize')
            ->with(['id' => 'yy'])
            ->willReturn('{"id":"yy"}');

        $this->verifierMock->method('verify')
            ->willThrowException(new \RuntimeException('Unexpected failure'));

        $this->mockLogger()->expects($this->once())
            ->method('error')
            ->with('Passkey authentication verify error', ['exception' => 'Unexpected failure']);

        $result = $this->controller()->execute();

        $this->assertSame($this->jsonResultMock, $result);
        $this->assertSame(400, $this->capturedHttpCode);
        $this->assertTrue($this->capturedData['errors']);
        $this->assertEquals(
            'Passkey verification failed. Please try again.',
            (string) $this->capturedData['message']
        );
    }

    public function testExecuteHandlesErrorsThatAreNotExceptions(): void
    {
        $this->configureRequestBody(['challengeToken' => 'tok', 'credential' => []]);
        $this->jsonMock->method('serialize')->willReturn('[]');
        $this->verifierMock->method('verify')->willThrowException(new \TypeError('Unexpected type'));

        $this->mockLogger()->expects($this->once())
            ->method('error')
            ->with('Passkey authentication verify error', ['exception' => 'Unexpected type']);

        $this->controller()->execute();

        $this->assertSame(400, $this->capturedHttpCode);
        $this->assertTrue($this->capturedData['errors']);
        $this->assertArrayNotHasKey('redirect_url', $this->capturedData);
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
