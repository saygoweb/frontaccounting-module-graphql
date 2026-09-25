<?php

namespace FA\GraphQL\Tests\Unit\Http;

use FA\GraphQL\ConfigException;
use FA\GraphQL\Error\FaRejected;
use FA\GraphQL\Error\Forbidden;
use FA\GraphQL\Error\InvalidToken;
use FA\GraphQL\Error\Unauthenticated;
use FA\GraphQL\Http\JsonErrorMiddleware;
use FA\GraphQL\Http\RequestRejected;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

class JsonErrorMiddlewareTest extends TestCase
{
    /** @var \Throwable[] */
    private array $logged = [];

    private function execute(\Throwable $thrown, bool $debug = false): ResponseInterface
    {
        $middleware = new JsonErrorMiddleware($debug, function (\Throwable $e) {
            $this->logged[] = $e;
        });

        return $middleware->process($this->request(), new class ($thrown) implements RequestHandlerInterface {
            private \Throwable $thrown;

            public function __construct(\Throwable $thrown)
            {
                $this->thrown = $thrown;
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw $this->thrown;
            }
        });
    }

    private function request(): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/');
    }

    /**
     * @return array<string, mixed>
     */
    private function error(ResponseInterface $response): array
    {
        $this->assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertCount(1, $body['errors']);

        return $body['errors'][0];
    }

    public function testASuccessfulResponsePassesThroughUntouched(): void
    {
        $ok = (new Response(200))->withHeader('X-Test', 'yes');
        $handler = new class ($ok) implements RequestHandlerInterface {
            private ResponseInterface $ok;

            public function __construct(ResponseInterface $ok)
            {
                $this->ok = $ok;
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->ok;
            }
        };

        $this->assertSame($ok, (new JsonErrorMiddleware(false))->process($this->request(), $handler));
    }

    public function testNotFound(): void
    {
        $response = $this->execute(new HttpNotFoundException($this->request()));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('NOT_FOUND', $this->error($response)['extensions']['code']);
    }

    public function testMethodNotAllowedNamesTheAllowedMethods(): void
    {
        $e = new HttpMethodNotAllowedException($this->request());
        $e->setAllowedMethods(['POST']);

        $response = $this->execute($e);

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('POST', $response->getHeaderLine('Allow'));
        $this->assertSame('METHOD_NOT_ALLOWED', $this->error($response)['extensions']['code']);
    }

    public function testRejectedRequestsKeepTheirStatusAndMessage(): void
    {
        $tooLarge = $this->execute(new RequestRejected(413, 'The request body is too large.'));
        $bad = $this->execute(new RequestRejected(400, 'Batched requests are not supported.'));

        $this->assertSame(413, $tooLarge->getStatusCode());
        $this->assertSame('PAYLOAD_TOO_LARGE', $this->error($tooLarge)['extensions']['code']);
        $this->assertSame(400, $bad->getStatusCode());
        $this->assertSame(
            ['message' => 'Batched requests are not supported.', 'extensions' => ['code' => 'BAD_REQUEST']],
            $this->error($bad)
        );
    }

    public function testInvalidTokenIs401Unauthenticated(): void
    {
        $response = $this->execute(new InvalidToken('The access token is invalid or has expired.'));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(
            ['message' => 'The access token is invalid or has expired.', 'extensions' => ['code' => 'UNAUTHENTICATED']],
            $this->error($response)
        );
    }

    public function testApiErrorsUseTheirOwnStatusAndCode(): void
    {
        $unauthenticated = $this->execute(new Unauthenticated('Gone.'));
        $forbidden = $this->execute(new Forbidden('No GraphQL API access.'));
        $rejected = $this->execute(new FaRejected('Refused.', ['Credit limit exceeded']));

        $this->assertSame(401, $unauthenticated->getStatusCode());
        $this->assertSame('UNAUTHENTICATED', $this->error($unauthenticated)['extensions']['code']);
        $this->assertSame(403, $forbidden->getStatusCode());
        $this->assertSame('FORBIDDEN', $this->error($forbidden)['extensions']['code']);
        $this->assertSame(422, $rejected->getStatusCode());
        $this->assertSame(['Credit limit exceeded'], $this->error($rejected)['extensions']['messages']);
        $this->assertSame([], $this->logged);
    }

    public function testAnythingElseIsMaskedAndLogged(): void
    {
        $response = $this->execute(new \RuntimeException('SELECT secret FROM t'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(
            ['message' => 'Internal server error', 'extensions' => ['code' => 'INTERNAL']],
            $this->error($response)
        );
        $this->assertCount(1, $this->logged);
        $this->assertSame('SELECT secret FROM t', $this->logged[0]->getMessage());
    }

    /**
     * A setup problem found inside the pipeline (Bootstrap::assertRoot(): no
     * FrontAccounting there, or not the fork) fails closed with a message saying
     * so, as index.php does for one found before it.
     */
    public function testAConfigurationProblemSaysWhatItIs(): void
    {
        $response = $this->execute(new ConfigException('This FrontAccounting is not the fork.'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(
            [
                'message' => 'The GraphQL module is not configured: This FrontAccounting is not the fork.',
                'extensions' => ['code' => 'INTERNAL'],
            ],
            $this->error($response)
        );
        $this->assertCount(1, $this->logged);
    }

    public function testAPhpErrorIsCaughtToo(): void
    {
        $response = $this->execute(new \TypeError('Argument 1 must be of type int'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('INTERNAL', $this->error($response)['extensions']['code']);
        $this->assertCount(1, $this->logged);
    }

    public function testDebugIncludesTheDetail(): void
    {
        $error = $this->error($this->execute(new \RuntimeException('boom'), true));

        $this->assertSame('INTERNAL', $error['extensions']['code']);
        $this->assertSame('boom', $error['extensions']['debugMessage']);
        $this->assertIsArray($error['extensions']['trace']);
    }

    public function testInvalidUtf8StillMakesJson(): void
    {
        $response = $this->execute(new Unauthenticated("Unknown user caf\xE9"));

        $this->assertStringContainsString('Unknown user caf', $this->error($response)['message']);
    }
}
