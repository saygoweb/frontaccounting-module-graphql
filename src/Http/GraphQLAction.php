<?php

namespace FA\GraphQL\Http;

use FA\GraphQL\Api\DocumentRunner;
use FA\GraphQL\Auth\Claims;
use GraphQL\Type\Schema;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The GraphQL route. Reads the raw body whatever the Content-Type claims, refuses
 * what cannot be executed (400), and executes the rest: 200, with `errors` when a
 * resolver or validation failed.
 */
final class GraphQLAction
{
    private DocumentRunner $runner;
    private ContainerInterface $container;

    public function __construct(DocumentRunner $runner, ContainerInterface $container)
    {
        $this->runner = $runner;
        $this->container = $container;
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true);
        if (is_array($body) && $body !== [] && array_keys($body) === range(0, count($body) - 1)) {
            throw new RequestRejected(400, 'Batched requests are not supported.');
        }
        if (!is_array($body) || !isset($body['query']) || !is_string($body['query'])) {
            throw new RequestRejected(400, 'The request body must be JSON with a "query" string.');
        }

        $claims = $request->getAttribute(AuthenticationMiddleware::CLAIMS);
        $output = $this->runner->run(
            $this->container->get(Schema::class),
            $this->container,
            $body['query'],
            $this->variables($body['variables'] ?? null),
            isset($body['operationName']) && is_string($body['operationName']) ? $body['operationName'] : null,
            $claims instanceof Claims ? $claims : null
        );

        // JSON_THROW_ON_ERROR: a resolver value that cannot be encoded (a raw
        // INF/NAN from a custom scalar, say) must not produce a 200 with an empty
        // body. It throws instead, so JsonErrorMiddleware renders a 500 INTERNAL
        // JSON body — every response body is JSON (spec section 6).
        $response->getBody()->write(
            (string) json_encode($output, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)
        );

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    /**
     * An object, or the same object JSON-encoded into a string, as some clients send it.
     *
     * @param mixed $variables
     * @return array<string, mixed>|null
     */
    private function variables($variables): ?array
    {
        if ($variables === null || $variables === '') {
            return null;
        }
        if (is_string($variables)) {
            $variables = json_decode($variables, true);
            if (!is_array($variables)) {
                throw new RequestRejected(400, 'The "variables" string is not a JSON object.');
            }
        }
        if (!is_array($variables)) {
            throw new RequestRejected(400, '"variables" must be an object.');
        }

        return $variables;
    }
}
