<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\Resolver;

use MageOS\PasskeyAuth\Api\RegistrationVerifierInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\GraphQl\Model\Query\ContextInterface;

class VerifyRegistration implements ResolverInterface
{
    public function __construct(
        private readonly RegistrationVerifierInterface $registrationVerifier,
        private readonly CredentialFormatter $formatter
    ) {
    }

    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null)
    {
        /** @var ContextInterface $context */
        if (false === $context->getExtensionAttributes()->getIsCustomer()) {
            throw new GraphQlAuthorizationException(__('The current customer isn\'t authorized.'));
        }

        $input = $args['input'] ?? [];
        $challengeToken = (string) ($input['challenge_token'] ?? '');
        $attestationResponse = (string) ($input['attestation_response'] ?? '');
        $name = isset($input['name']) && is_string($input['name']) ? $input['name'] : null;

        if ($challengeToken === '' || $attestationResponse === '') {
            throw new GraphQlInputException(__('challenge_token and attestation_response are required.'));
        }

        try {
            $credential = $this->registrationVerifier->verify(
                (int) $context->getUserId(),
                $challengeToken,
                $attestationResponse,
                $name
            );
        } catch (LocalizedException $e) {
            throw new GraphQlInputException(__($e->getMessage()), $e);
        }

        return $this->formatter->format($credential);
    }
}
