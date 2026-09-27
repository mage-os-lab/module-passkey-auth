<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model;

use MageOS\PasskeyAuth\Model\Exception\RateLimitExceededException;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Phrase;

/**
 * Note: The check-then-increment pattern is inherently non-atomic in cache-based storage.
 * Under very high concurrency, a few extra requests may slip through. This is acceptable
 * for rate limiting where exact precision is not required.
 */
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

    /**
     * @throws RateLimitExceededException
     */
    public function checkOptionsRate(string $identifier): void
    {
        $key = 'passkey_options_' . hash('sha256', $identifier);
        $this->checkOnly($key, self::OPTIONS_LIMIT, __('Too many passkey requests. Please try again later.'));
        $this->increment($key, self::OPTIONS_WINDOW);
    }

    /**
     * @throws RateLimitExceededException
     */
    public function checkVerifyFailRate(string $ip): void
    {
        $key = 'passkey_verify_fail_' . hash('sha256', $ip);
        $this->checkOnly($key, self::VERIFY_FAIL_LIMIT, __('Too many failed passkey attempts. Please try again later.'));
    }

    public function recordVerifyFailure(string $ip): void
    {
        $key = 'passkey_verify_fail_' . hash('sha256', $ip);
        $this->increment($key, self::VERIFY_FAIL_WINDOW);
    }

    private function checkOnly(string $key, int $limit, Phrase $message): void
    {
        $count = (int) $this->cache->load($key);
        if ($count >= $limit) {
            throw new RateLimitExceededException($message);
        }
    }

    private function increment(string $key, int $window): void
    {
        $count = (int) $this->cache->load($key);
        $this->cache->save((string) ($count + 1), $key, [], $window);
    }
}
