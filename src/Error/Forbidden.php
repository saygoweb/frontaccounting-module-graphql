<?php

namespace FA\GraphQL\Error;

/**
 * The authenticated user does not have access to the resource.
 */
class Forbidden extends ApiError
{
    public function code(): string
    {
        return 'FORBIDDEN';
    }

    public function httpStatus(): int
    {
        return 403;
    }
}
