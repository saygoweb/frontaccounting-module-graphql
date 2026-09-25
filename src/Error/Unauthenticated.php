<?php

namespace FA\GraphQL\Error;

/**
 * No valid identity: no token, bad credentials, or a refresh token that is not live.
 */
class Unauthenticated extends ApiError
{
    public function code(): string
    {
        return 'UNAUTHENTICATED';
    }

    public function httpStatus(): int
    {
        return 401;
    }
}
