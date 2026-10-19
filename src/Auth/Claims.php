<?php

namespace FA\GraphQL\Auth;

/**
 * What a verified access token says: who, and in which company. $machine is true
 * for a machine token (typ "machine", spec §3.7), whose jti must also be found live
 * in the company's graphql_machine_token table before the request goes on.
 * $scope is the token's scp claim: 'read' runs queries only, 'write' runs anything.
 */
final class Claims
{
    public const SCOPE_READ = 'read';
    public const SCOPE_WRITE = 'write';

    public int $company;
    public string $login;
    public string $jti;
    public \DateTimeImmutable $expiresAt;
    public bool $machine;
    public string $scope;

    public function __construct(
        int $company,
        string $login,
        string $jti,
        \DateTimeImmutable $expiresAt,
        bool $machine = false,
        string $scope = self::SCOPE_WRITE
    ) {
        $this->company = $company;
        $this->login = $login;
        $this->jti = $jti;
        $this->expiresAt = $expiresAt;
        $this->machine = $machine;
        $this->scope = $scope;
    }
}
