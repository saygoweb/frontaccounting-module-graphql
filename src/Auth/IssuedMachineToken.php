<?php

namespace FA\GraphQL\Auth;

/**
 * A machine token as it is issued: the JWT, shown once and never stored, and what
 * is stored about it.
 */
final class IssuedMachineToken
{
    public string $token;
    public string $jti;
    public \DateTimeImmutable $issuedAt;
    public \DateTimeImmutable $expiresAt;

    public function __construct(string $token, string $jti, \DateTimeImmutable $issuedAt, \DateTimeImmutable $expiresAt)
    {
        $this->token = $token;
        $this->jti = $jti;
        $this->issuedAt = $issuedAt;
        $this->expiresAt = $expiresAt;
    }
}
