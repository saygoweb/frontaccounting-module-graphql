<?php

namespace FA\GraphQL\Tests\Unit\Http;

use FA\GraphQL\Auth\Authenticator;
use FA\GraphQL\Auth\Claims;
use FA\GraphQL\Auth\TokenService;
use FA\GraphQL\Config;
use FA\GraphQL\Error\InvalidToken;
use FA\GraphQL\Http\AuthenticationMiddleware;
use Lcobucci\Clock\SystemClock;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

class AuthenticationMiddlewareTest extends TestCase
{
    private TokenService $tokens;
    private ?ServerRequestInterface $passed = null;

    protected function setUp(): void
    {
        $this->tokens = new TokenService(
            Config::fromArray(['secret' => '0123456789abcdef0123456789abcdef']),
            new SystemClock(new \DateTimeZone('UTC'))
        );
    }

    private function process(ServerRequestInterface $request): void
    {
        $handler = new class ($this->passed) implements RequestHandlerInterface {
            /** @var ServerRequestInterface|null */
            private $passed;

            public function __construct(?ServerRequestInterface &$passed)
            {
                $this->passed = &$passed;
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->passed = $request;

                return new Response(200);
            }
        };
        (new AuthenticationMiddleware(new Authenticator($this->tokens)))->process($request, $handler);
    }

    private function request(): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/');
    }

    public function testNoHeaderSetsNullClaims(): void
    {
        $this->process($this->request());

        $this->assertNotNull($this->passed);
        $this->assertTrue(array_key_exists(AuthenticationMiddleware::CLAIMS, $this->passed->getAttributes()));
        $this->assertNull($this->passed->getAttribute(AuthenticationMiddleware::CLAIMS));
    }

    public function testBearerTokenSetsClaims(): void
    {
        $jwt = $this->tokens->issueAccess(1, 'apitest');

        $this->process($this->request()->withHeader('authorization', "Bearer $jwt"));

        $claims = $this->passed->getAttribute(AuthenticationMiddleware::CLAIMS);
        $this->assertInstanceOf(Claims::class, $claims);
        $this->assertSame(1, $claims->company);
    }

    public function testBadTokenStopsTheRequest(): void
    {
        try {
            $this->process($this->request()->withHeader('Authorization', 'Bearer nonsense'));
            $this->fail('expected InvalidToken');
        } catch (InvalidToken $e) {
            $this->assertNull($this->passed);
        }
    }
}
