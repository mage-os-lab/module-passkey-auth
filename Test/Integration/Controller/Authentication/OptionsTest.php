<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Integration\Controller\Authentication;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\TestFramework\TestCase\AbstractController;

/**
 * @magentoDbIsolation enabled
 */
class OptionsTest extends AbstractController
{
    /**
     * @magentoConfigFixture current_store customer/passkey/enabled 1
     */
    public function testReturnsRequestOptionsWithChallengeToken(): void
    {
        $this->getRequest()->setMethod(HttpRequest::METHOD_POST);
        $this->getRequest()->setContent('{}');

        $this->dispatch('passkey/authentication/options');

        $this->assertSame(200, $this->getResponse()->getHttpResponseCode());

        $body = json_decode($this->getResponse()->getBody(), true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('challenge', $body);
        $this->assertArrayHasKey('challengeToken', $body);
        $this->assertArrayNotHasKey('errors', $body);
    }

    /**
     * @magentoConfigFixture current_store customer/passkey/enabled 0
     */
    public function testReturnsErrorWhenDisabled(): void
    {
        $this->getRequest()->setMethod(HttpRequest::METHOD_POST);
        $this->getRequest()->setContent('{}');

        $this->dispatch('passkey/authentication/options');

        $this->assertSame(400, $this->getResponse()->getHttpResponseCode());

        $body = json_decode($this->getResponse()->getBody(), true);
        $this->assertTrue($body['errors']);
    }
}
