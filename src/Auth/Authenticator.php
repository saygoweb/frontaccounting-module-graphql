<?php

namespace FA\GraphQL\Auth;

use FA\GraphQL\Error\InvalidToken;

/**
 * The Authorization header to an identity. A header that is present and wrong is
 * an error, never silently anonymous: a client that thinks it is authenticated
 * should find out that it is not.
 *
 * An access token is accepted on its signature alone. A machine token (spec §3.7)
 * is also looked up, on every request, by the MachineTokenCheck; without one it is
 * refused.
 */
class Authenticator
{
    private TokenService $tokens;
    private ?MachineTokenCheck $machine;

    public function __construct(TokenService $tokens, ?MachineTokenCheck $machine = null)
    {
        $this->tokens = $tokens;
        $this->machine = $machine;
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

        $claims = $this->tokens->verify($m[1]);
        if ($claims->machine) {
            if ($this->machine === null) {
                throw new InvalidToken('Machine tokens are not accepted here.');
            }
            $this->machine->check($claims);
        }

        return $claims;
    }
}
