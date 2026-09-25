<?php

namespace FA\GraphQL;

use Anorm\GraphQL\GraphQLUtils;
use Anorm\GraphQL\Type\MangoInput;
use DI\Container;
use FA\GraphQL\Type\Auth\AuthMutations;
use FA\GraphQL\Type\Auth\AuthPayloadType;
use FA\GraphQL\Type\SalesType\SalesTypeType;
use FA\GraphQL\Type\Viewer\ViewerType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;

/**
 * Yours to edit. anorm-graphql maintains only the entries under an
 * `// anorm-graphql` comment; remove the comment to take an entry over.
 * Keep the entries of each fields array in alphabetical order.
 *
 * Scaffolded by `bin/generate`. apiVersion, me and the auth mutations are written by
 * hand; everything marked `// anorm-graphql` is the generator's.
 */
class ApiSchema extends Schema
{
    public const VERSION = '0.1.0';

    /** @var Container */
    public $context;

    public function __construct(Container $context)
    {
        $this->context = $context;

        $object = [
            'query' => new ObjectType([
                'name' => 'Query',
                'fields' => [
                    'apiVersion' => [
                        'type' => Type::nonNull(Type::string()),
                        'description' => 'The version of the GraphQL module. Needs no token.',
                        'resolve' => function () {
                            return self::VERSION;
                        },
                    ],
                    'me' => [
                        'type' => Type::nonNull($this->type(ViewerType::class)),
                        'description' => 'The signed-in user.',
                        'resolve' => function () {
                            return $this->type(ViewerType::class)->resolveMe();
                        },
                    ],
                    // anorm-graphql
                    GraphQLUtils::createListField('salesTypeList', $this->type(SalesTypeType::class), 'resolveList')
                        ->addArgument('query', $this->type(MangoInput::class))
                        ->build(),
                ],
            ]),
            'mutation' => new ObjectType([
                'name' => 'Mutation',
                'fields' => [
                    'login' => [
                        'type' => Type::nonNull($this->type(AuthPayloadType::class)),
                        'description' => 'Sign in as a FrontAccounting user. Needs no token.',
                        'args' => [
                            'company' => ['type' => Type::int(), 'defaultValue' => 0],
                            'user' => ['type' => Type::nonNull(Type::string())],
                            'password' => ['type' => Type::nonNull(Type::string())],
                        ],
                        'resolve' => function ($root, array $args) {
                            return $this->type(AuthMutations::class)->resolveLogin($root, $args);
                        },
                    ],
                    'tokenRefresh' => [
                        'type' => Type::nonNull($this->type(AuthPayloadType::class)),
                        'description' => 'Exchange a refresh token for a new pair. Needs no token.',
                        'args' => [
                            'refreshToken' => ['type' => Type::nonNull(Type::string())],
                        ],
                        'resolve' => function ($root, array $args) {
                            return $this->type(AuthMutations::class)->resolveTokenRefresh($root, $args);
                        },
                    ],
                    'tokenRevoke' => [
                        'type' => Type::nonNull(Type::boolean()),
                        'description' => 'Revoke one of your refresh tokens, or all of them when none is given.',
                        'args' => [
                            'refreshToken' => ['type' => Type::string()],
                        ],
                        'resolve' => function ($root, array $args) {
                            return $this->type(AuthMutations::class)->resolveTokenRevoke($root, $args);
                        },
                    ],
                ],
            ]),
        ];
        parent::__construct($object);
    }

    private function type(string $name)
    {
        return $this->context->get($name);
    }
}
