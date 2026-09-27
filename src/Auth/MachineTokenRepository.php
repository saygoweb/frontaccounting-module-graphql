<?php

namespace FA\GraphQL\Auth;

/**
 * One company's machine tokens: the repository is built on that company's
 * connection and table prefix.
 */
interface MachineTokenRepository
{
    /** @return int the new row's id */
    public function insert(MachineTokenRecord $record): int;

    public function findByJti(string $jti): ?MachineTokenRecord;

    /** @return MachineTokenRecord[] every row, oldest first */
    public function all(): array;

    /**
     * Revoke the token only if it is not revoked yet: one conditional UPDATE.
     *
     * @return bool true only if this call revoked it
     */
    public function revoke(string $jti, \DateTimeImmutable $at): bool;

    /**
     * Set last_used_at to $at only if it is null or not after $staleBefore: one
     * conditional UPDATE, so concurrent requests write it at most once between them.
     *
     * @return bool true if a row was written
     */
    public function touch(string $jti, \DateTimeImmutable $at, \DateTimeImmutable $staleBefore): bool;
}
