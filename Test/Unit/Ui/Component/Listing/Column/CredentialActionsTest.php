<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Ui\Component\Listing\Column;

use MageOS\PasskeyAuth\Ui\Component\Listing\Column\CredentialActions;
use Magento\Framework\Escaper;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class CredentialActionsTest extends TestCase
{
    private UrlInterface&Stub $urlBuilderMock;
    private CredentialActions $column;

    protected function setUp(): void
    {
        $this->urlBuilderMock = $this->createStub(UrlInterface::class);
        $this->urlBuilderMock->method('getUrl')->willReturn('https://example.com/admin/delete/');

        $this->column = new CredentialActions(
            $this->createStub(ContextInterface::class),
            $this->createStub(UiComponentFactory::class),
            $this->urlBuilderMock,
            new Escaper(),
            [],
            ['name' => 'actions']
        );
    }

    public function testRevokeConfirmEscapesFriendlyNameAndEmail(): void
    {
        $dataSource = ['data' => ['items' => [[
            'entity_id' => 7,
            'friendly_name' => '<img src=x onerror=alert(1)>',
            'customer_email' => '"<script>alert(2)</script>"@example.com',
        ]]]];

        $result = $this->column->prepareDataSource($dataSource);
        $revoke = $result['data']['items'][0]['actions']['revoke'];
        $message = (string) $revoke['confirm']['message'];

        $this->assertStringNotContainsString('<img', $message);
        $this->assertStringNotContainsString('<script', $message);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $message);
        $this->assertStringContainsString('&quot;&lt;script&gt;alert(2)&lt;/script&gt;&quot;@example.com', $message);
        $this->assertSame('https://example.com/admin/delete/', $revoke['href']);
        $this->assertTrue($revoke['post']);
    }

    public function testRevokeConfirmFallsBackToUnnamedLabel(): void
    {
        $dataSource = ['data' => ['items' => [[
            'entity_id' => 7,
            'friendly_name' => '',
            'customer_email' => 'jane@example.com',
        ]]]];

        $result = $this->column->prepareDataSource($dataSource);
        $message = (string) $result['data']['items'][0]['actions']['revoke']['confirm']['message'];

        $this->assertStringContainsString('"Unnamed passkey" for jane@example.com', $message);
    }

    public function testSkipsItemsWithoutEntityId(): void
    {
        $dataSource = ['data' => ['items' => [['friendly_name' => 'Phone']]]];

        $this->assertSame($dataSource, $this->column->prepareDataSource($dataSource));
    }
}
