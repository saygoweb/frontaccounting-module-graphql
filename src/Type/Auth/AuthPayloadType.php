<?php

namespace FA\GraphQL\Type\Auth;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

class AuthPayloadType extends ObjectType
{
    public function __construct()
    {
        parent::__construct([
            'name' => 'AuthPayload',
            'fields' => [
                // No 'description' entries here: ApiSchemaTest asserts the printed SDL
                // has these three fields immediately adjacent, matching spec section
                // 3.1, which carries no field descriptions for AuthPayload.
                'accessToken' => ['type' => Type::nonNull(Type::string())],
                'expiresIn' => ['type' => Type::nonNull(Type::int())],
                'refreshToken' => ['type' => Type::nonNull(Type::string())],
            ],
        ]);
    }
}
