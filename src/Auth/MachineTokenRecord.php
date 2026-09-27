<?php

namespace FA\GraphQL\Auth;

/**
 * A row of graphql_machine_token. The token itself is never here: a machine token
 * is a signed JWT, so its jti is enough to find, and revoke, it.
 */
final class MachineTokenRecord
{
    public ?int $id = null;
    public string $jti;
    public string $login;
    public string $label = '';
    public \DateTimeImmutable $issuedAt;
    public \DateTimeImmutable $expiresAt;
    public ?\DateTimeImmutable $revokedAt = null;
    public ?\DateTimeImmutable $lastUsedAt = null;
}
