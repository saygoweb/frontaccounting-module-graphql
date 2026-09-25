<?php

namespace FA\GraphQL\Tests\Unit\Http;

use FA\GraphQL\Auth\TokenService;
use FA\GraphQL\Config;
use FA\GraphQL\RequestInfo;
use FA\GraphQL\SessionGate;
use Lcobucci\Clock\SystemClock;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * The real container.php and app.php, driven by $app->handle() with no web server
 * and no FrontAccounting: the session gate is a fake unless a test gives another.
 */
abstract class ApplicationTestCase extends TestCase
{
    protected const SECRET = '0123456789abcdef0123456789abcdef';

    protected ?App $app = null;

    /**
     * @param array<string, mixed> $config
     */
    protected function config(array $config = []): Config
    {
        return Config::fromArray($config + ['secret' => self::SECRET]);
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function createApp(?SessionGate $gate = null, array $config = []): App
    {
        $root = dirname(__DIR__, 3);
        $factory = require $root . '/container.php';
        $container = $factory($this->config($config), new RequestInfo(false, 'phpunit 127.0.0.1'));
        $container->set(SessionGate::class, $gate ?? new FakeSessionGate());

        $build = require $root . '/app.php';
        $this->app = $build($container);

        return $this->app;
    }

    /**
     * @param array<string, string> $headers
     */
    protected function request(string $method, string $path, string $body = '', array $headers = []): ResponseInterface
    {
        if ($this->app === null) {
            $this->createApp();
        }
        $request = (new ServerRequestFactory())->createServerRequest($method, $path);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->app->handle($request->withBody((new StreamFactory())->createStream($body)));
    }

    /**
     * @return array<string, mixed>
     */
    protected function json(ResponseInterface $response): array
    {
        $this->assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        $decoded = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($decoded, 'not JSON: ' . $response->getBody());

        return $decoded;
    }

    protected function token(int $company = 0, string $login = 'apitest'): string
    {
        return (new TokenService($this->config(), new SystemClock(new \DateTimeZone('UTC'))))
            ->issueAccess($company, $login);
    }
}
