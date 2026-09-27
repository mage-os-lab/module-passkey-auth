<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\Resolver;

use MageOS\PasskeyAuth\Api\CredentialManagementInterface;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\GraphQl\Model\Query\ContextInterface;

class CustomerPasskeys implements ResolverInterface
{
    public function __construct(
        private readonly CredentialManagementInterface $credentialManagement,
        private readonly CredentialFormatter $formatter
    ) {
    }

    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null)
    {
        /** @var ContextInterface $context */
        if (false === $context->getExtensionAttributes()->getIsCustomer()) {
            throw new GraphQlAuthorizationException(__('The current customer isn\'t authorized.'));
        }

        $credentials = $this->credentialManagement->listCredentials((int) $context->getUserId());

        return array_map($this->formatter->format(...), $credentials);
    }
}
