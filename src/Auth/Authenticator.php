<?php

namespace FA\GraphQL\Auth;

use FA\GraphQL\Error\InvalidToken;

/**
 * The Authorization header to an identity. A header that is present and wrong is
 * an error, never silently anonymous: a client that thinks it is authenticated
 * should find out that it is not.
 */
class Authenticator
{
    private TokenService $tokens;

    public function __construct(TokenService $tokens)
    {
        $this->tokens = $tokens;
    }

    /**
     * @param string|null $header the Authorization header's value; null when the request has none
     */
    public function fromAuthorization(?string $header): ?Claims
    {
        $value = trim((string) $header);
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^Bearer\s+(\S+)$/i', $value, $m)) {
            throw new InvalidToken('The Authorization header must be "Bearer <token>".');
        }

        return $this->tokens->verify($m[1]);
    }
}
