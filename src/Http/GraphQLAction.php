<?php

namespace FA\GraphQL\Http;

use FA\GraphQL\Config;
use FA\GraphQL\Error\ErrorFormatter;
use FA\GraphQL\Fa\Warnings;
use GraphQL\GraphQL;
use GraphQL\Type\Schema;
use GraphQL\Validator\DocumentValidator;
use GraphQL\Validator\Rules\QueryComplexity;
use GraphQL\Validator\Rules\QueryDepth;
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
    private Config $config;
    private ErrorFormatter $formatter;
    private ContainerInterface $container;

    public function __construct(Config $config, ErrorFormatter $formatter, ContainerInterface $container)
    {
        $this->config = $config;
        $this->formatter = $formatter;
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

        // Per request: a warning belongs to the request whose work committed.
        Warnings::reset();

        $rules = DocumentValidator::allRules();
        $rules[QueryDepth::class] = new QueryDepth($this->config->maxDepth);
        $rules[QueryComplexity::class] = new QueryComplexity($this->config->maxComplexity);

        $result = GraphQL::executeQuery(
            $this->container->get(Schema::class),
            $body['query'],
            null,
            $this->container,
            $this->variables($body['variables'] ?? null),
            isset($body['operationName']) && is_string($body['operationName']) ? $body['operationName'] : null,
            null,
            $rules
        );
        $result->setErrorFormatter($this->formatter);
        $output = $result->toArray();

        // FrontAccounting's warnings about work that committed (Release 2 spec
        // section 3.2): the generated mutations return [<Entity>Type!]!, so they
        // travel beside `data`, not in it.
        $warnings = Warnings::all();
        if ($warnings !== []) {
            $output['extensions']['warnings'] = $warnings;
        }

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
