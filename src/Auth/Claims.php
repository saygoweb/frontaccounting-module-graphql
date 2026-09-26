<?php

namespace FA\GraphQL\Auth;

/**
 * What a verified access token says: who, and in which company. $machine is true
 * for a machine token (typ "machine", spec §3.7), whose jti must also be found live
 * in the company's graphql_machine_token table before the request goes on.
 */
final class Claims
{
    public int $company;
    public string $login;
    public string $jti;
    public \DateTimeImmutable $expiresAt;
    public bool $machine;

    public function __construct(
        int $company,
        string $login,
        string $jti,
        \DateTimeImmutable $expiresAt,
        bool $machine = false
    ) {
        $this->company = $company;
        $this->login = $login;
        $this->jti = $jti;
        $this->expiresAt = $expiresAt;
        $this->machine = $machine;
    }
}
