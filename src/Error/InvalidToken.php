<?php

namespace FA\GraphQL\Error;

/**
 * A bearer token was presented and is not acceptable. JsonErrorMiddleware answers
 * it with HTTP 401 before GraphQL runs, so a client can refresh and retry without
 * parsing a body.
 */
class InvalidToken extends \RuntimeException
{
}
