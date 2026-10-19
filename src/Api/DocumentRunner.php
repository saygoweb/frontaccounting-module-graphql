<?php

namespace FA\GraphQL\Api;

use FA\GraphQL\Auth\Claims;
use FA\GraphQL\Config;
use FA\GraphQL\Error\ErrorFormatter;
use FA\GraphQL\Fa\Warnings;
use GraphQL\Error\SyntaxError;
use GraphQL\GraphQL;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\Parser;
use GraphQL\Type\Schema;
use GraphQL\Validator\DocumentValidator;
use GraphQL\Validator\Rules\QueryComplexity;
use GraphQL\Validator\Rules\QueryDepth;

/**
 * One GraphQL document, executed the way the API executes every document: the
 * token's scope, the depth and complexity limits, the error formatter, and
 * FrontAccounting's warnings beside `data`. GraphQLAction (HTTP) and Executor
 * (in-process) both run documents through this, so the two cannot drift.
 */
final class DocumentRunner
{
    public const READ_ONLY = 'This token is read-only.';

    private Config $config;
    private ErrorFormatter $formatter;

    public function __construct(Config $config, ErrorFormatter $formatter)
    {
        $this->config = $config;
        $this->formatter = $formatter;
    }

    /**
     * @param mixed $context the resolvers' context value (the request's container)
     * @param array<string, mixed>|null $variables
     * @return array<string, mixed> the response envelope: data, errors, extensions
     */
    public function run(
        Schema $schema,
        $context,
        string $query,
        ?array $variables,
        ?string $operationName,
        ?Claims $claims
    ): array {
        if ($claims !== null && $claims->scope === Claims::SCOPE_READ && self::isMutation($query, $operationName)) {
            return ['errors' => [['message' => self::READ_ONLY, 'extensions' => ['code' => 'FORBIDDEN']]]];
        }

        // Per document: a warning belongs to the request whose work committed.
        Warnings::reset();

        $rules = DocumentValidator::allRules();
        $rules[QueryDepth::class] = new QueryDepth($this->config->maxDepth);
        $rules[QueryComplexity::class] = new QueryComplexity($this->config->maxComplexity);

        $result = GraphQL::executeQuery($schema, $query, null, $context, $variables, $operationName, null, $rules);
        $result->setErrorFormatter($this->formatter);
        $output = $result->toArray();

        // FrontAccounting's warnings about work that committed (Release 2 spec
        // section 3.2): the generated mutations return [<Entity>Type!]!, so they
        // travel beside `data`, not in it.
        $warnings = Warnings::all();
        if ($warnings !== []) {
            $output['extensions']['warnings'] = $warnings;
        }

        return $output;
    }

    /**
     * Whether the operation that would run may be a mutation. Every operation the
     * name could select counts (all of them when no name is given): webonyx picks
     * the last of several with one name, so the guard fails closed rather than
     * guess. A document that does not parse, or names no operation it has, is
     * left to executeQuery to report.
     */
    private static function isMutation(string $query, ?string $operationName): bool
    {
        try {
            $document = Parser::parse($query);
        } catch (SyntaxError $e) {
            return false;
        }
        foreach ($document->definitions as $definition) {
            if (!$definition instanceof OperationDefinitionNode) {
                continue;
            }
            $name = $definition->name !== null ? $definition->name->value : null;
            if (($operationName === null || $name === $operationName) && $definition->operation === 'mutation') {
                return true;
            }
        }

        return false;
    }
}
