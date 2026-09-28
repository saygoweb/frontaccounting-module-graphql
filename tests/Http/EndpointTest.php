<?php

namespace FA\GraphQL\Tests\Http;

use PHPUnit\Framework\TestCase;

/**
 * Through Apache, .htaccess and index.php, which the unit tests never touch.
 * FA_GRAPHQL_URL is set by tools/ci.sh, or derived from the CI image's FA_URL;
 * elsewhere, point it at an install.
 */
class EndpointTest extends TestCase
{
    use GraphQLClient;

    public function testApiVersionOverHttp(): void
    {
        $response = $this->send('POST', '', '{"query": "{ apiVersion }"}');

        $this->assertSame(200, $response['status']);
        $this->assertStringStartsWith('application/json', $response['contentType']);
        $this->assertSame(['data' => ['apiVersion' => '0.1.0']], $response['body']);
    }

    /**
     * FrontAccounting reads the superglobals while it loads: `JsHttpRequest=`
     * would wrap the body as JavaScript, `path_to_root` would die() with HTML.
     */
    public function testQueryParametersFrontAccountingReactsToAreIgnored(): void
    {
        foreach (['?JsHttpRequest=1-script', '?JsHttpRequest=1-xml', '?path_to_root=x'] as $query) {
            $response = $this->send('POST', $query, '{"query": "{ apiVersion }"}');

            $this->assertSame(200, $response['status'], $query);
            $this->assertStringStartsWith('application/json', $response['contentType'], $query);
            $this->assertSame(['data' => ['apiVersion' => '0.1.0']], $response['body'], $query);
        }
    }

    public function testFrontAccountingIsServingTheModule(): void
    {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]);
        $page = file_get_contents(dirname($this->url(), 2) . '/index.php', false, $context);

        $this->assertIsString($page);
        $this->assertStringContainsString('FrontAccounting', $page);
    }
}
