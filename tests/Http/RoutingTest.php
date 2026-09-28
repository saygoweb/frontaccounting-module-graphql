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

    public function testABrowserGetIsVoyager(): void
    {
        $response = $this->exchange('GET', '', "Accept: text/html,application/xhtml+xml,*/*;q=0.8\r\n", null);

        $this->assertSame(200, $response['status'], $response['raw']);
        $this->assertStringStartsWith('text/html', $response['contentType']);
        $this->assertStringContainsString('GraphQLVoyager.renderVoyager', $response['raw']);
    }

    /**
     * What Voyager asks for: a full introspection, anonymously, inside max_depth
     * and max_complexity.
     */
    public function testTheIntrospectionVoyagerSendsIsAnswered(): void
    {
        $response = $this->send(
            'POST',
            '',
            (string) json_encode(['query' => \GraphQL\Type\Introspection::getIntrospectionQuery()])
        );

        $this->assertSame(200, $response['status'], $response['raw']);
        $this->assertArrayNotHasKey('errors', $response['body'], $response['raw']);
        $this->assertSame('Query', $response['body']['data']['__schema']['queryType']['name']);
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
