<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Api;

interface WebAuthnConfigInterface
{
    /**
     * Get allowed origins for WebAuthn ceremony validation.
     *
     * @return string[]
     */
    public function getAllowedOrigins(): array;
}
