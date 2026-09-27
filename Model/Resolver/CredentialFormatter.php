<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\Resolver;

use MageOS\PasskeyAuth\Api\Data\CredentialInterface;

class CredentialFormatter
{
    /**
     * Shape a credential for the CustomerPasskey GraphQL type.
     */
    public function format(CredentialInterface $credential): array
    {
        return [
            'id' => (int) $credential->getEntityId(),
            'name' => $credential->getFriendlyName(),
            'transports' => $credential->getTransportsArray(),
            'created_at' => $credential->getCreatedAt(),
            'last_used_at' => $credential->getLastUsedAt(),
        ];
    }
}
