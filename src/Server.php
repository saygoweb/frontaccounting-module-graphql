<?php

namespace FA\GraphQL;

use GraphQL\Error\DebugFlag;
use GraphQL\GraphQL;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;

/**
 * Turns an HTTP request body into a GraphQL response, with no dependence on
 * PHP's globals, so it can be driven from a test as easily as from index.php.
 */
class Server
{
    public const VERSION = '0.1.0';

    /** @var bool */
    private $debug;

    public function __construct(bool $debug = false)
    {
        $this->debug = $debug;
    }

    public function handle(string $method, string $body): Response
    {
        if ($method !== 'POST') {
            return Response::error(405, 'GraphQL requests must be sent with POST.');
        }

        $request = json_decode($body, true);
        if (!is_array($request) || !isset($request['query']) || !is_string($request['query'])) {
            return Response::error(400, 'The request body must be JSON with a "query" string.');
        }

        $variables = isset($request['variables']) && is_array($request['variables'])
            ? $request['variables']
            : null;
        $operationName = isset($request['operationName']) && is_string($request['operationName'])
            ? $request['operationName']
            : null;

        $result = GraphQL::executeQuery(
            $this->schema(),
            $request['query'],
            null,
            null,
            $variables,
            $operationName
        );

        $flags = $this->debug
            ? DebugFlag::INCLUDE_DEBUG_MESSAGE | DebugFlag::INCLUDE_TRACE
            : DebugFlag::NONE;

        return new Response(200, $result->toArray($flags));
    }

    /**
     * A placeholder. The real schema is src/ApiSchema.php, scaffolded and then
     * maintained by `anorm-graphql make`.
     */
    private function schema(): Schema
    {
        return new Schema([
            'query' => new ObjectType([
                'name' => 'Query',
                'fields' => [
                    'apiVersion' => [
                        'type' => Type::nonNull(Type::string()),
                        'description' => 'The version of the GraphQL module.',
                        'resolve' => function () {
                            return self::VERSION;
                        },
                    ],
                ],
            ]),
        ]);
    }
}
