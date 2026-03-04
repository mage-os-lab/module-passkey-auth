<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Api\Data;

interface AuthenticationResultInterface
{
    public function getCustomerId(): int;

    public function getToken(): string;
}
