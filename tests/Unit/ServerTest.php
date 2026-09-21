<?php

namespace FA\GraphQL\Tests\Unit;

use FA\GraphQL\Server;
use PHPUnit\Framework\TestCase;

class ServerTest extends TestCase
{
    public function testApiVersionResolves(): void
    {
        $response = (new Server())->handle('POST', '{"query": "{ apiVersion }"}');

        $this->assertSame(200, $response->status);
        $this->assertSame(['data' => ['apiVersion' => Server::VERSION]], $response->body);
    }

    public function testGetIsRefused(): void
    {
        $response = (new Server())->handle('GET', '');

        $this->assertSame(405, $response->status);
        $this->assertArrayHasKey('errors', $response->body);
    }

    public function testBodyWithoutQueryIsRefused(): void
    {
        $response = (new Server())->handle('POST', '{"nope": 1}');

        $this->assertSame(400, $response->status);
    }

    public function testUnknownFieldIsAGraphQLError(): void
    {
        $response = (new Server())->handle('POST', '{"query": "{ nope }"}');

        $this->assertSame(200, $response->status);
        $this->assertArrayHasKey('errors', $response->body);
    }
}
