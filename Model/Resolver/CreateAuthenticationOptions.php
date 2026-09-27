<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\Resolver;

use MageOS\PasskeyAuth\Api\AuthenticationOptionsInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

class CreateAuthenticationOptions implements ResolverInterface
{
    public function __construct(
        private readonly AuthenticationOptionsInterface $authenticationOptions
    ) {
    }

    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null)
    {
        $email = isset($args['email']) && is_string($args['email']) && $args['email'] !== ''
            ? $args['email']
            : null;

        try {
            $optionsJson = $this->authenticationOptions->generate($email);
        } catch (LocalizedException $e) {
            throw new GraphQlInputException(__($e->getMessage()), $e);
        }

        return ['options_json' => $optionsJson];
    }
}
