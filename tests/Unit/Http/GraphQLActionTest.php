<?php

namespace FA\GraphQL\Tests\Unit\Http;

use DI\Container;
use FA\GraphQL\Config;
use FA\GraphQL\Error\ErrorFormatter;
use FA\GraphQL\Http\GraphQLAction;
use FA\GraphQL\Http\RequestRejected;
use GraphQL\Type\Definition\CustomScalarType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Response;

class GraphQLActionTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $node = new ObjectType(['name' => 'Node', 'fields' => function () use (&$node) {
            return ['child' => ['type' => $node, 'resolve' => function () {
                return [];
            }], 'leaf' => ['type' => Type::string()]];
        }]);
        $schema = new Schema(['query' => new ObjectType(['name' => 'Query', 'fields' => [
            'apiVersion' => ['type' => Type::nonNull(Type::string()), 'resolve' => function () {
                return '0.1.0';
            }],
            'contextIsContainer' => ['type' => Type::boolean(), 'resolve' => function ($root, $args, $context) {
                return $context instanceof Container;
            }],
            'echo' => [
                'type' => Type::string(),
                'args' => ['text' => ['type' => Type::string()]],
                'resolve' => function ($root, array $args) {
                    return $args['text'] ?? null;
                },
            ],
            'boom' => ['type' => Type::string(), 'resolve' => function () {
                throw new \RuntimeException('secret detail');
            }],
            'node' => ['type' => $node, 'resolve' => function () {
                return [];
            }],
        ]])]);

        $this->container = new Container();
        $this->container->set(Schema::class, $schema);
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function execute(string $body, array $config = [], array $headers = []): array
    {
        $action = new GraphQLAction(
            Config::fromArray($config + ['secret' => '0123456789abcdef0123456789abcdef']),
            new ErrorFormatter(false),
            $this->container
        );
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/')
            ->withBody((new StreamFactory())->createStream($body));
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $response = $action($request, new Response());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $decoded = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function rejected(string $body): RequestRejected
    {
        try {
            $this->execute($body);
        } catch (RequestRejected $e) {
            return $e;
        }
        $this->fail('expected RequestRejected');
    }

    public function testExecutesTheQuery(): void
    {
        $this->assertSame(['data' => ['apiVersion' => '0.1.0']], $this->execute('{"query": "{ apiVersion }"}'));
    }

    public function testResolversReceiveTheContainer(): void
    {
        $this->assertTrue($this->execute('{"query": "{ contextIsContainer }"}')['data']['contextIsContainer']);
    }

    public function testNotJsonIs400(): void
    {
        $this->assertSame(400, $this->rejected('not json')->status());
    }

    public function testBodyWithoutQueryIs400(): void
    {
        $this->assertSame(400, $this->rejected('{"nope": 1}')->status());
    }

    public function testBatchIs400(): void
    {
        $e = $this->rejected('[{"query": "{ apiVersion }"}]');

        $this->assertSame(400, $e->status());
        $this->assertStringContainsString('atch', $e->getMessage());
    }

    public function testVariablesAsAnObject(): void
    {
        $body = json_encode(['query' => 'query ($t: String) { echo(text: $t) }', 'variables' => ['t' => 'hi']]);

        $this->assertSame('hi', $this->execute((string) $body)['data']['echo']);
    }

    public function testVariablesAsAJsonString(): void
    {
        $body = json_encode(['query' => 'query ($t: String) { echo(text: $t) }', 'variables' => '{"t": "hi"}']);

        $this->assertSame('hi', $this->execute((string) $body)['data']['echo']);
    }

    public function testVariablesAsAnUndecodableStringIs400(): void
    {
        $body = json_encode(['query' => '{ apiVersion }', 'variables' => '{not json']);

        $this->assertSame(400, $this->rejected((string) $body)->status());
    }

    public function testTheContentTypeDoesNotMatter(): void
    {
        $query = '{"query": "{ apiVersion }"}';

        $graphql = $this->execute($query, [], ['Content-Type' => 'application/graphql']);
        $this->assertSame('0.1.0', $graphql['data']['apiVersion']);
        $text = $this->execute($query, [], ['Content-Type' => 'text/plain']);
        $this->assertSame('0.1.0', $text['data']['apiVersion']);
        $this->assertSame('0.1.0', $this->execute($query)['data']['apiVersion']);
    }

    public function testOperationName(): void
    {
        $body = json_encode([
            'query' => 'query A { apiVersion } query B { contextIsContainer }',
            'operationName' => 'B',
        ]);

        $this->assertSame(['contextIsContainer' => true], $this->execute((string) $body)['data']);
    }

    public function testDepthLimit(): void
    {
        $body = (string) json_encode(['query' => '{ node { child { child { child { leaf } } } } }']);

        $this->assertArrayNotHasKey('errors', $this->execute($body, ['max_depth' => 10]));
        $tooDeep = $this->execute($body, ['max_depth' => 2]);
        $this->assertStringContainsString('depth', strtolower($tooDeep['errors'][0]['message']));
    }

    public function testComplexityLimit(): void
    {
        $response = $this->execute(
            '{"query": "{ apiVersion contextIsContainer node { leaf } }"}',
            ['max_complexity' => 2]
        );

        $this->assertStringContainsString('complexity', strtolower($response['errors'][0]['message']));
    }

    public function testUnexpectedExceptionIsMasked(): void
    {
        $response = $this->execute('{"query": "{ boom }"}');

        $this->assertSame('INTERNAL', $response['errors'][0]['extensions']['code']);
        $this->assertStringNotContainsString('secret detail', (string) json_encode($response));
    }

    /**
     * M-3: an encoding failure (a resolver's value cannot be JSON-encoded, for
     * example a raw INF/NAN) must not produce an empty body. It must throw, so
     * JsonErrorMiddleware turns it into a 500 INTERNAL JSON body: every response
     * body is JSON (spec section 6).
     */
    public function testAnUnencodableResultThrowsInsteadOfWritingAnEmptyBody(): void
    {
        $raw = new CustomScalarType([
            'name' => 'Raw',
            'serialize' => function ($value) {
                // Unlike webonyx's own FloatType, this bypasses is_finite() validation,
                // the way a hand-written custom scalar could.
                return $value;
            },
        ]);
        $schema = new Schema(['query' => new ObjectType(['name' => 'Query', 'fields' => [
            'raw' => ['type' => $raw, 'resolve' => function () {
                return NAN;
            }],
        ]])]);
        $container = new Container();
        $container->set(Schema::class, $schema);

        $action = new GraphQLAction(
            Config::fromArray(['secret' => '0123456789abcdef0123456789abcdef']),
            new ErrorFormatter(false),
            $container
        );
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/')
            ->withBody((new StreamFactory())->createStream('{"query": "{ raw }"}'));

        $this->expectException(\JsonException::class);
        $action($request, new Response());
    }
}
