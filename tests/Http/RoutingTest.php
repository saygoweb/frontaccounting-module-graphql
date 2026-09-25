<?php

namespace FA\GraphQL\Tests\Http;

use PHPUnit\Framework\TestCase;

class RoutingTest extends TestCase
{
    use GraphQLClient;

    public function testGetIs405AsJson(): void
    {
        $response = $this->send('GET', '');

        $this->assertSame(405, $response['status']);
        $this->assertStringStartsWith('application/json', $response['contentType']);
        $this->assertIsArray($response['body'], $response['raw']);
        $this->assertNotEmpty($response['body']['errors'][0]['message']);
    }

    public function testAnUnknownPathIs404AsJson(): void
    {
        $response = $this->send('POST', 'no-such-route', '{"query":"{ apiVersion }"}');

        $this->assertSame(404, $response['status']);
        $this->assertStringStartsWith('application/json', $response['contentType']);
        $this->assertIsArray($response['body'], $response['raw']);
    }

    /**
     * Review Focus 1: clients configured with the script's own URL.
     */
    public function testPostingToIndexPhpAnswersLikeTheDirectory(): void
    {
        $viaDirectory = $this->gql('{ apiVersion }');
        $viaScript = $this->gql('{ apiVersion }', [], null, 'index.php');

        $this->assertSame(200, $viaScript['status'], $viaScript['raw']);
        $this->assertSame($viaDirectory['body'], $viaScript['body']);
    }

    public function testAnAuthenticatedRequestThroughIndexPhpKeepsItsToken(): void
    {
        $token = $this->login()['accessToken'];

        $response = $this->gql('{ me { login } }', [], $token, 'index.php');

        $this->assertSame('apitest', $response['body']['data']['me']['login'] ?? null, $response['raw']);
    }
}
