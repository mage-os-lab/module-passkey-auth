<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\Resolver;

use MageOS\PasskeyAuth\Api\AuthenticationVerifierInterface;
use MageOS\PasskeyAuth\Model\RateLimiter;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthenticationException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Psr\Log\LoggerInterface;

class VerifyAuthentication implements ResolverInterface
{
    public function __construct(
        private readonly AuthenticationVerifierInterface $authenticationVerifier,
        private readonly RateLimiter $rateLimiter,
        private readonly RemoteAddress $remoteAddress,
        private readonly LoggerInterface $logger
    ) {
    }

    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null)
    {
        $input = $args['input'] ?? [];
        $challengeToken = (string) ($input['challenge_token'] ?? '');
        $assertionResponse = (string) ($input['assertion_response'] ?? '');

        if ($challengeToken === '' || $assertionResponse === '') {
            throw new GraphQlInputException(__('challenge_token and assertion_response are required.'));
        }

        $ip = $this->remoteAddress->getRemoteAddress() ?: 'unknown';

        try {
            $this->rateLimiter->checkVerifyFailRate($ip);
            $result = $this->authenticationVerifier->verify($challengeToken, $assertionResponse);
        } catch (LocalizedException $e) {
            $this->rateLimiter->recordVerifyFailure($ip);
            $this->logger->error('GraphQL passkey authentication failed', ['exception' => $e->getMessage()]);
            // Deliberately generic: do not leak whether the credential exists.
            throw new GraphQlAuthenticationException(
                __('Passkey verification failed. Please try again.'),
                $e
            );
        }

        return ['customer_token' => $result->getToken()];
    }
}
