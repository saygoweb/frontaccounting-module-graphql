<?php

namespace FA\GraphQL\Auth;

interface RefreshTokenRepository
{
    /** @return int the new row's id */
    public function insert(RefreshTokenRecord $record): int;

    public function findByHash(string $hash): ?RefreshTokenRecord;

    /**
     * Revoke row $id only if it is still live: `UPDATE ... SET revoked_at = ?,
     * replaced_by = ? WHERE id = ? AND revoked_at IS NULL`, one statement, so two
     * concurrent callers cannot both succeed.
     *
     * @return bool true only if this call revoked a row whose revoked_at was null
     */
    public function markRevoked(int $id, \DateTimeImmutable $at, ?int $replacedBy): bool;

    /** @return int how many live tokens were revoked */
    public function revokeAllForUser(int $userId, \DateTimeImmutable $at): int;

    /** @return int how many rows were deleted */
    public function deleteExpired(\DateTimeImmutable $before, int $limit): int;

    /**
     * Run $fn atomically: commit when it returns, roll back when it throws.
     *
     * @return mixed what $fn returned
     */
    public function transactional(callable $fn);
}
