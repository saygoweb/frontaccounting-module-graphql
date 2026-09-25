<?php

namespace FA\GraphQL\Auth;

/**
 * A row of graphql_refresh_token. The token itself is never here, only its hash.
 */
final class RefreshTokenRecord
{
    public ?int $id = null;
    public int $userId;
    public string $tokenHash;
    public \DateTimeImmutable $issuedAt;
    public \DateTimeImmutable $expiresAt;
    public ?\DateTimeImmutable $revokedAt = null;
    public ?int $replacedBy = null;
    public string $client = '';
}
