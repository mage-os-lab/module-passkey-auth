<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Api\GraphQl;

use Magento\TestFramework\TestCase\GraphQlAbstract;

/**
 * Runs in the dev/tests/api-functional harness.
 *
 * @magentoConfigFixture default_store customer/passkey/enabled 1
 */
class PasskeyAuthenticationTest extends GraphQlAbstract
{
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

    public function testCustomerPasskeysRequiresAuthorization(): void
    {
        $this->expectExceptionMessage('The current customer isn\'t authorized.');

        $this->graphQlQuery('{ customerPasskeys { id name } }');
    }

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
