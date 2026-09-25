<?php

namespace FA\GraphQL\Error;

/**
 * The request is malformed or violates a constraint.
 */
class BadInput extends ApiError
{
    public function code(): string
    {
        return 'BAD_INPUT';
    }

    public function httpStatus(): int
    {
        return 400;
    }
}
