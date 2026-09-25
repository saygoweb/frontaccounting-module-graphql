<?php

namespace FA\GraphQL\Tests\Unit;

use FA\GraphQL\Config;
use FA\GraphQL\RequestInfo;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

class RequestInfoTest extends TestCase
{
    private function config(bool $trustProxy): Config
    {
        return Config::fromArray(['secret' => '0123456789abcdef0123456789abcdef', 'trust_proxy' => $trustProxy]);
    }

    /**
     * @param array<string, string> $server
     * @param array<string, string> $headers
     */
    private function request(array $server, array $headers = []): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/', $server);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    public function testPlainHttp(): void
    {
        $info = RequestInfo::fromRequest(
            $this->request(['REMOTE_ADDR' => '10.0.0.1'], ['User-Agent' => 'Guzzle']),
            $this->config(false)
        );

        $this->assertFalse($info->https);
        $this->assertSame('Guzzle 10.0.0.1', $info->client);
    }

    public function testHttpsFromTheServer(): void
    {
        $this->assertTrue(RequestInfo::fromRequest($this->request(['HTTPS' => 'on']), $this->config(false))->https);
        $this->assertFalse(RequestInfo::fromRequest($this->request(['HTTPS' => 'off']), $this->config(false))->https);
    }

    public function testForwardedHeadersOnlyWhenTrusted(): void
    {
        $request = $this->request(
            ['REMOTE_ADDR' => '10.0.0.2'],
            ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.9, 10.0.0.2']
        );

        $untrusted = RequestInfo::fromRequest($request, $this->config(false));
        $trusted = RequestInfo::fromRequest($request, $this->config(true));

        $this->assertFalse($untrusted->https);
        $this->assertTrue($trusted->https);
        $this->assertStringContainsString('203.0.113.9', $trusted->client);
        $this->assertStringContainsString('10.0.0.2', $untrusted->client);
        $this->assertStringNotContainsString('203.0.113.9', $untrusted->client);
    }

    public function testClientIsTruncated(): void
    {
        $request = $this->request([], ['User-Agent' => str_repeat('a', 400)]);
        $info = RequestInfo::fromRequest($request, $this->config(false));

        $this->assertLessThanOrEqual(255, strlen($info->client));
    }
}
