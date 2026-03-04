<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Model;

use MageOS\PasskeyAuth\Model\RateLimiter;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RateLimiterTest extends TestCase
{
    private CacheInterface&MockObject $cache;
    private RateLimiter $rateLimiter;

    protected function setUp(): void
    {
        $this->cache = $this->createMock(CacheInterface::class);
        $this->rateLimiter = new RateLimiter($this->cache);
    }

    public function testCheckOptionsRateFirstRequest(): void
    {
        $this->cache->expects($this->exactly(2))
            ->method('load')
            ->willReturn(false);

        $this->cache->expects($this->once())
            ->method('save')
            ->with('1', $this->anything(), [], 60);

        $this->rateLimiter->checkOptionsRate('test@example.com');
    }

    public function testCheckOptionsRateUnderLimit(): void
    {
        $this->cache->expects($this->exactly(2))
            ->method('load')
            ->willReturn('5');

        $this->cache->expects($this->once())
            ->method('save')
            ->with('6', $this->anything(), [], 60);

        $this->rateLimiter->checkOptionsRate('test@example.com');
    }

    public function testCheckOptionsRateAtLimit(): void
    {
        $this->cache->expects($this->once())
            ->method('load')
            ->willReturn('10');

        $this->expectException(LocalizedException::class);
        $this->rateLimiter->checkOptionsRate('test@example.com');
    }

    public function testCheckVerifyFailRateFirstRequest(): void
    {
        $this->cache->expects($this->once())
            ->method('load')
            ->willReturn(false);

        $this->rateLimiter->checkVerifyFailRate('127.0.0.1');
    }

    public function testCheckVerifyFailRateUnderLimit(): void
    {
        $this->cache->expects($this->once())
            ->method('load')
            ->willReturn('3');

        $this->rateLimiter->checkVerifyFailRate('127.0.0.1');
    }

    public function testCheckVerifyFailRateAtLimit(): void
    {
        $this->cache->expects($this->once())
            ->method('load')
            ->willReturn('5');

        $this->expectException(LocalizedException::class);
        $this->rateLimiter->checkVerifyFailRate('127.0.0.1');
    }

    public function testRecordVerifyFailure(): void
    {
        $this->cache->expects($this->once())
            ->method('load')
            ->willReturn('2');

        $this->cache->expects($this->once())
            ->method('save')
            ->with('3', $this->anything(), [], 900);

        $this->rateLimiter->recordVerifyFailure('127.0.0.1');
    }
}
