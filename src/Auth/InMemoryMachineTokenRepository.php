<?php

namespace FA\GraphQL\Auth;

/**
 * For tests of the service's rules, which are the same whatever stores the rows.
 */
class InMemoryMachineTokenRepository implements MachineTokenRepository
{
    /** @var array<int, MachineTokenRecord> */
    private array $rows = [];
    private int $nextId = 1;

    /** How many times touch() wrote a row. */
    public int $touches = 0;

    public function insert(MachineTokenRecord $record): int
    {
        $stored = clone $record;
        $stored->id = $this->nextId++;
        $this->rows[$stored->id] = $stored;

        return $stored->id;
    }

    /**
     * For tests: overwrite a stored row with $record (matched by id).
     */
    public function replace(MachineTokenRecord $record): void
    {
        if ($record->id !== null && isset($this->rows[$record->id])) {
            $this->rows[$record->id] = clone $record;
        }
    }

    public function findByJti(string $jti): ?MachineTokenRecord
    {
        foreach ($this->rows as $row) {
            if ($row->jti === $jti) {
                return clone $row;
            }
        }

        return null;
    }

    public function all(): array
    {
        return array_map(function (MachineTokenRecord $row): MachineTokenRecord {
            return clone $row;
        }, array_values($this->rows));
    }

    public function revoke(string $jti, \DateTimeImmutable $at): bool
    {
        foreach ($this->rows as $row) {
            if ($row->jti === $jti && $row->revokedAt === null) {
                $row->revokedAt = $at;

                return true;
            }
        }

        return false;
    }

    public function touch(string $jti, \DateTimeImmutable $at, \DateTimeImmutable $staleBefore): bool
    {
        foreach ($this->rows as $row) {
            if ($row->jti === $jti && ($row->lastUsedAt === null || $row->lastUsedAt <= $staleBefore)) {
                $row->lastUsedAt = $at;
                $this->touches++;

                return true;
            }
        }

        return false;
    }
}
