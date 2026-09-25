<?php

namespace FA\GraphQL\Tests\Unit\Http;

use FA\GraphQL\Http\BodyLimitMiddleware;
use FA\GraphQL\Http\RequestRejected;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Response;
use Slim\Psr7\Stream;

class BodyLimitMiddlewareTest extends TestCase
{
    /** @var string|null what the next handler read from the body */
    private ?string $seen = null;

    private function handler(): RequestHandlerInterface
    {
        return new class ($this->seen) implements RequestHandlerInterface {
            /** @var string|null */
            private $seen;

            public function __construct(?string &$seen)
            {
                $this->seen = &$seen;
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->seen = (string) $request->getBody();

                return new Response(200);
            }
        };
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(StreamInterface $body, array $headers = []): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/')->withBody($body);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    private function status(\Closure $run): int
    {
        try {
            $run();
        } catch (RequestRejected $e) {
            return $e->status();
        }
        $this->fail('expected RequestRejected');
    }

    public function testABodyWithinTheLimitReachesTheHandlerWhole(): void
    {
        $body = str_repeat('a', 100);

        $response = (new BodyLimitMiddleware(100))
            ->process($this->request((new StreamFactory())->createStream($body)), $this->handler());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($body, $this->seen);
    }

    public function testContentLengthOverTheLimitIsRefusedUnread(): void
    {
        $status = $this->status(function () {
            (new BodyLimitMiddleware(100))->process(
                $this->request((new StreamFactory())->createStream('{}'), ['Content-Length' => '5000']),
                $this->handler()
            );
        });

        $this->assertSame(413, $status);
        $this->assertNull($this->seen);
    }

    public function testABodyLargerThanItsContentLengthIsStillRefused(): void
    {
        $status = $this->status(function () {
            (new BodyLimitMiddleware(100))->process(
                $this->request((new StreamFactory())->createStream(str_repeat('a', 101)), ['Content-Length' => '10']),
                $this->handler()
            );
        });

        $this->assertSame(413, $status);
    }

    public function testAStreamOfUnknownSizeIsMeasuredByReading(): void
    {
        $pipe = popen('head -c 5000 /dev/zero', 'r');
        $this->assertIsResource($pipe);
        $stream = new Stream($pipe);
        $this->assertNull($stream->getSize(), 'the premise: a pipe has no size');

        $status = $this->status(function () use ($stream) {
            (new BodyLimitMiddleware(100))->process($this->request($stream), $this->handler());
        });

        $this->assertSame(413, $status);
        $this->assertNull($this->seen);
    }
}
