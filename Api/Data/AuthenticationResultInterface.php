<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Api\Data;

/**
 * @api
 */
interface AuthenticationResultInterface
{
    /**
     * @return int
     */
    public function getCustomerId(): int;

    /**
     * @return string
     */
    public function getToken(): string;
}
