<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Exception\LocalizedException;

class RateLimiter
{
    private const OPTIONS_LIMIT = 10;
    private const OPTIONS_WINDOW = 60;
    private const VERIFY_FAIL_LIMIT = 5;
    private const VERIFY_FAIL_WINDOW = 900;

    public function __construct(
        private readonly CacheInterface $cache
    ) {
    }

    public function checkOptionsRate(string $identifier): void
    {
        $this->check(
            'passkey_options_' . md5($identifier),
            self::OPTIONS_LIMIT,
            self::OPTIONS_WINDOW,
            __('Too many passkey requests. Please try again later.')
        );
    }

    public function checkVerifyFailRate(string $ip): void
    {
        $this->check(
            'passkey_verify_fail_' . md5($ip),
            self::VERIFY_FAIL_LIMIT,
            self::VERIFY_FAIL_WINDOW,
            __('Too many failed passkey attempts. Please try again later.')
        );
    }

    public function recordVerifyFailure(string $ip): void
    {
        $key = 'passkey_verify_fail_' . md5($ip);
        $count = (int) $this->cache->load($key);
        $this->cache->save((string) ($count + 1), $key, [], self::VERIFY_FAIL_WINDOW);
    }

    private function check(string $key, int $limit, int $window, \Magento\Framework\Phrase $message): void
    {
        $count = (int) $this->cache->load($key);
        if ($count >= $limit) {
            throw new LocalizedException($message);
        }
        $this->cache->save((string) ($count + 1), $key, [], $window);
    }
}
