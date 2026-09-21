<?php

namespace FA\GraphQL\Tests\Http;

use FA\GraphQL\Server;
use PHPUnit\Framework\TestCase;

/**
 * Through Apache, .htaccess and index.php, which the unit tests never touch.
 * FA_GRAPHQL_URL is set by the docker stack; elsewhere, point it at an install.
 */
class EndpointTest extends TestCase
{
    /** @var string */
    private $url;

    protected function setUp(): void
    {
        $url = getenv('FA_GRAPHQL_URL');
        $this->url = $url !== false && $url !== '' ? $url : 'http://localhost:8000/modules/graphql/';
    }

    public function testApiVersionOverHttp(): void
    {
        list($status, $body) = $this->post('{"query": "{ apiVersion }"}');

        $this->assertSame(200, $status);
        $this->assertSame(['data' => ['apiVersion' => Server::VERSION]], json_decode($body, true));
    }

    public function testFrontAccountingIsServingTheModule(): void
    {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]);
        $page = file_get_contents(dirname($this->url, 2) . '/index.php', false, $context);

        $this->assertIsString($page);
        $this->assertStringContainsString('FrontAccounting', $page);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function post(string $json): array
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $json,
            'ignore_errors' => true,
            'timeout' => 10,
        ]]);
        $body = file_get_contents($this->url, false, $context);
        $this->assertIsString($body, 'no response from ' . $this->url);

        preg_match('#^HTTP/\S+ (\d{3})#', $http_response_header[0], $m);

        return [(int) $m[1], $body];
    }
}
