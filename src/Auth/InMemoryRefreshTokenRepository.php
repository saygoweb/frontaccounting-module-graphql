<?php

namespace FA\GraphQL\Auth;

/**
 * For tests of the service's rules, which are the same whatever stores the rows.
 */
class InMemoryRefreshTokenRepository implements RefreshTokenRepository
{
    /** @var array<int, RefreshTokenRecord> */
    private array $rows = [];
    private int $nextId = 1;

    /** @return RefreshTokenRecord[] */
    public function all(): array
    {
        return array_values($this->rows);
    }

    public function insert(RefreshTokenRecord $record): int
    {
        $stored = clone $record;
        $stored->id = $this->nextId++;
        $this->rows[$stored->id] = $stored;

        return $stored->id;
    }

    public function findByHash(string $hash): ?RefreshTokenRecord
    {
        foreach ($this->rows as $row) {
            if (hash_equals($row->tokenHash, $hash)) {
                return clone $row;
            }
        }

        return null;
    }

    public function markRevoked(int $id, \DateTimeImmutable $at, ?int $replacedBy): bool
    {
        if (!isset($this->rows[$id]) || $this->rows[$id]->revokedAt !== null) {
            return false;
        }
        $this->rows[$id]->revokedAt = $at;
        $this->rows[$id]->replacedBy = $replacedBy;

        return true;
    }

    public function revokeAllForUser(int $userId, \DateTimeImmutable $at): int
    {
        $count = 0;
        foreach ($this->rows as $row) {
            if ($row->userId === $userId && $row->revokedAt === null) {
                $row->revokedAt = $at;
                $count++;
            }
        }

        return $count;
    }

    public function deleteExpired(\DateTimeImmutable $before, int $limit): int
    {
        $count = 0;
        foreach ($this->rows as $id => $row) {
            if ($count < $limit && $row->expiresAt < $before) {
                unset($this->rows[$id]);
                $count++;
            }
        }

        return $count;
    }

    public function transactional(callable $fn)
    {
        $snapshot = array_map(function (RefreshTokenRecord $r) {
            return clone $r;
        }, $this->rows);
        try {
            return $fn();
        } catch (\Throwable $e) {
            $this->rows = $snapshot;
            throw $e;
        }
    }
}
