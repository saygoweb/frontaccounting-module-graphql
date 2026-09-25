<?php

namespace FA\GraphQL\Tests\Unit\Http;

use FA\GraphQL\Auth\Claims;
use FA\GraphQL\Error\Forbidden;
use FA\GraphQL\Http\AuthenticationMiddleware;
use FA\GraphQL\Http\FaSessionMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

class FaSessionMiddlewareTest extends TestCase
{
    private FakeSessionGate $gate;
    private int $handled = 0;

    protected function setUp(): void
    {
        $this->gate = new FakeSessionGate();
    }

    private function process(?Claims $claims): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/')
            ->withAttribute(AuthenticationMiddleware::CLAIMS, $claims);
        $handler = new class ($this->handled) implements RequestHandlerInterface {
            /** @var int */
            private $handled;

            public function __construct(int &$handled)
            {
                $this->handled = &$handled;
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->handled++;

                return new Response(200);
            }
        };
        (new FaSessionMiddleware($this->gate))->process($request, $handler);
    }

    private function claims(): Claims
    {
        return new Claims(0, 'apitest', 'jti', new \DateTimeImmutable('+5 minutes'));
    }

    public function testAnonymousBootsButDoesNotEnter(): void
    {
        $this->process(null);

        $this->assertSame(1, $this->gate->boots);
        $this->assertSame([], $this->gate->entered);
        $this->assertSame(1, $this->handled);
    }

    public function testClaimsAreEntered(): void
    {
        $claims = $this->claims();

        $this->process($claims);

        $this->assertSame(1, $this->gate->boots);
        $this->assertSame([$claims], $this->gate->entered);
        $this->assertSame(1, $this->handled);
    }

    public function testTheGatesRefusalPropagates(): void
    {
        $this->gate->throwOnEnter = new Forbidden('No GraphQL API access.');

        try {
            $this->process($this->claims());
            $this->fail('expected Forbidden');
        } catch (Forbidden $e) {
            $this->assertSame(0, $this->handled);
        }
    }
}
