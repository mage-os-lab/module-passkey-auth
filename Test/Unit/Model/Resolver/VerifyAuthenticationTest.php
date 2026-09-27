<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Model\Resolver;

use MageOS\PasskeyAuth\Api\AuthenticationVerifierInterface;
use MageOS\PasskeyAuth\Api\Data\AuthenticationResultInterface;
use MageOS\PasskeyAuth\Model\Exception\RateLimitExceededException;
use MageOS\PasskeyAuth\Model\Resolver\VerifyAuthentication;
use Magento\Framework\Exception\AuthenticationException;
use Magento\Framework\Exception\EmailNotConfirmedException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\State\UserLockedException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthenticationException;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class VerifyAuthenticationTest extends TestCase
{
    private const INPUT = ['input' => ['challenge_token' => 'tok', 'assertion_response' => '{}']];

    private AuthenticationVerifierInterface&Stub $verifier;
    private ?VerifyAuthentication $resolver = null;

    protected function setUp(): void
    {
        $this->verifier = $this->createStub(AuthenticationVerifierInterface::class);
    }

    private function resolve(): array
    {
        $this->resolver ??= new VerifyAuthentication($this->verifier);

        return $this->resolver->resolve(
            $this->createStub(Field::class),
            null,
            $this->createStub(ResolveInfo::class),
            null,
            self::INPUT
        );
    }

    public function testReturnsCustomerToken(): void
    {
        $result = $this->createStub(AuthenticationResultInterface::class);
        $result->method('getToken')->willReturn('customer-token');
        $this->verifier->method('verify')->willReturn($result);

        $this->assertSame(['customer_token' => 'customer-token'], $this->resolve());
    }

    public function testHidesRejectionReason(): void
    {
        $this->verifier->method('verify')
            ->willThrowException(new LocalizedException(__('Invalid or expired challenge token.')));

        $this->expectException(GraphQlAuthenticationException::class);
        $this->expectExceptionMessage('Passkey verification failed. Please try again.');

        $this->resolve();
    }

    public function testShowsRateLimitMessage(): void
    {
        $this->verifier->method('verify')->willThrowException(
            new RateLimitExceededException(__('Too many failed passkey attempts. Please try again later.'))
        );

        $this->expectException(GraphQlAuthenticationException::class);
        $this->expectExceptionMessage('Too many failed passkey attempts. Please try again later.');

        $this->resolve();
    }

    /**
     * @return array<string, array{AuthenticationException}>
     */
    public static function refusedAccountProvider(): array
    {
        $notAllowed = __(
            'The account sign-in was incorrect or your account is disabled temporarily. '
            . 'Please wait and try again later.'
        );

        return [
            'locked' => [new UserLockedException($notAllowed)],
            'not confirmed' => [
                new EmailNotConfirmedException(__('This account isn\'t confirmed. Verify and try again.')),
            ],
            'group excluded' => [new AuthenticationException($notAllowed)],
        ];
    }

    #[DataProvider('refusedAccountProvider')]
    public function testShowsRefusedAccountMessage(AuthenticationException $exception): void
    {
        $this->verifier->method('verify')->willThrowException($exception);

        try {
            $this->resolve();
            $this->fail('Expected GraphQlAuthenticationException');
        } catch (GraphQlAuthenticationException $e) {
            // The account guard's message is already customer-facing
            $this->assertSame($exception->getMessage(), $e->getMessage());
            $this->assertSame($exception, $e->getPrevious());
        }
    }
}
