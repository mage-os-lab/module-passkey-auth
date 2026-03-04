<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Block\Login;

use MageOS\PasskeyAuth\Block\Login\PasskeyButton;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksConfigTrait;
use Magento\Framework\View\Element\Template\Context;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PasskeyButtonTest extends TestCase
{
    use MocksConfigTrait;

    private Context&MockObject $contextMock;
    private PasskeyButton $block;

    protected function setUp(): void
    {
        $this->contextMock = $this->createMock(Context::class);
        $this->createConfigMock();

        $this->block = new PasskeyButton(
            $this->contextMock,
            $this->configMock
        );
    }

    public function testGetUiMode(): void
    {
        $this->configMock->method('getUiMode')->willReturn('preferred');

        $this->assertSame('preferred', $this->block->getUiMode());
    }
}
