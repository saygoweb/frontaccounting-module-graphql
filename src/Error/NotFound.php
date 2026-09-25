<?php

namespace FA\GraphQL\Error;

/**
 * The requested resource does not exist.
 */
class NotFound extends ApiError
{
    public function code(): string
    {
        return 'NOT_FOUND';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
