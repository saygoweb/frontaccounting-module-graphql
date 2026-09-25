<?php

namespace FA\GraphQL\Auth;

/**
 * What a verified access token says: who, and in which company.
 */
final class Claims
{
    public int $company;
    public string $login;
    public string $jti;
    public \DateTimeImmutable $expiresAt;

    public function __construct(int $company, string $login, string $jti, \DateTimeImmutable $expiresAt)
    {
        $this->company = $company;
        $this->login = $login;
        $this->jti = $jti;
        $this->expiresAt = $expiresAt;
    }
}
