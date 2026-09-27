<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Api\GraphQl;

use Magento\TestFramework\TestCase\GraphQlAbstract;

/**
 * Runs in the dev/tests/api-functional harness.
 *
 * The harness reads magentoConfigFixture from test methods only, so each test declares it.
 */
class PasskeyAuthenticationTest extends GraphQlAbstract
{
    /**
     * @magentoConfigFixture default_store customer/passkey/enabled 1
     */
    public function testGuestCanCreateAuthenticationOptions(): void
    {
        $mutation = <<<'MUTATION'
mutation {
    createPasskeyAuthenticationOptions {
        options_json
    }
}
MUTATION;

        $response = $this->graphQlMutation($mutation);

        $this->assertArrayHasKey('createPasskeyAuthenticationOptions', $response);
        $options = json_decode($response['createPasskeyAuthenticationOptions']['options_json'], true);
        $this->assertIsArray($options);
        $this->assertArrayHasKey('challenge', $options);
        $this->assertArrayHasKey('challengeToken', $options);
        $this->assertTrue(empty($options['allowCredentials']), 'Guests must not receive credential lists.');
    }

    /**
     * @magentoConfigFixture default_store customer/passkey/enabled 1
     */
    public function testCustomerPasskeysRequiresAuthorization(): void
    {
        $this->expectExceptionMessage('The current customer isn\'t authorized.');

        $this->graphQlQuery('{ customerPasskeys { id name } }');
    }

    /**
     * @magentoConfigFixture default_store customer/passkey/enabled 1
     */
    public function testVerifyAuthenticationRejectsGarbageInput(): void
    {
        $mutation = <<<'MUTATION'
mutation {
    verifyPasskeyAuthentication(input: {
        challenge_token: "0000000000000000000000000000000000000000000000000000000000000000",
        assertion_response: "{}"
    }) {
        customer_token
    }
}
MUTATION;

        $this->expectExceptionMessage('Passkey verification failed. Please try again.');

        $this->graphQlMutation($mutation);
    }
}
