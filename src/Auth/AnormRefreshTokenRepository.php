<?php

namespace FA\GraphQL\Auth;

use Anorm\DataMapper;
use FA\GraphQL\Auth\Model\RefreshTokenModel;
use FA\GraphQL\Fa\CompanyContext;

/**
 * Inserts and lookups through the Anorm model; the set-based updates and deletes are
 * plain prepared statements, which is what they are.
 */
class AnormRefreshTokenRepository implements RefreshTokenRepository
{
    private const FORMAT = 'Y-m-d H:i:s';

    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function insert(RefreshTokenRecord $record): int
    {
        $model = new RefreshTokenModel($this->pdo);
        $model->userId = $record->userId;
        $model->tokenHash = $record->tokenHash;
        $model->issuedAt = $record->issuedAt->format(self::FORMAT);
        $model->expiresAt = $record->expiresAt->format(self::FORMAT);
        $model->revokedAt = $record->revokedAt === null ? null : $record->revokedAt->format(self::FORMAT);
        $model->replacedBy = $record->replacedBy;
        $model->client = $record->client;
        $model->write();

        return (int) $model->id;
    }

    public function findByHash(string $hash): ?RefreshTokenRecord
    {
        $model = DataMapper::find(RefreshTokenModel::class, $this->pdo)
            ->where('`token_hash` = :hash', [':hash' => $hash])
            ->one();
        if (!$model instanceof RefreshTokenModel) {
            return null;
        }

        $utc = new \DateTimeZone('UTC');
        $record = new RefreshTokenRecord();
        $record->id = (int) $model->id;
        $record->userId = (int) $model->userId;
        $record->tokenHash = (string) $model->tokenHash;
        $record->issuedAt = new \DateTimeImmutable($model->issuedAt, $utc);
        $record->expiresAt = new \DateTimeImmutable($model->expiresAt, $utc);
        $record->revokedAt = $model->revokedAt === null ? null : new \DateTimeImmutable($model->revokedAt, $utc);
        $record->replacedBy = $model->replacedBy === null ? null : (int) $model->replacedBy;
        $record->client = (string) $model->client;

        return $record;
    }

    /**
     * A single bound statement, conditional on the row still being live, so two
     * concurrent callers cannot both revoke the same row: only the first's UPDATE
     * changes a row, and only it gets true back.
     */
    public function markRevoked(int $id, \DateTimeImmutable $at, ?int $replacedBy): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE ' . $this->table() . ' SET `revoked_at` = ?, `replaced_by` = ?'
            . ' WHERE `id` = ? AND `revoked_at` IS NULL'
        );
        $statement->execute([$at->format(self::FORMAT), $replacedBy, $id]);

        return $statement->rowCount() === 1;
    }

    public function revokeAllForUser(int $userId, \DateTimeImmutable $at): int
    {
        $statement = $this->pdo->prepare(
            'UPDATE ' . $this->table() . ' SET `revoked_at` = ? WHERE `user_id` = ? AND `revoked_at` IS NULL'
        );
        $statement->execute([$at->format(self::FORMAT), $userId]);

        return $statement->rowCount();
    }

    public function deleteExpired(\DateTimeImmutable $before, int $limit): int
    {
        // Matches InMemoryRefreshTokenRepository: a non-positive limit deletes
        // nothing rather than the one row `max(1, $limit)` used to force.
        if ($limit <= 0) {
            return 0;
        }
        $statement = $this->pdo->prepare(
            'DELETE FROM ' . $this->table() . ' WHERE `expires_at` < ? ORDER BY `id` LIMIT ' . $limit
        );
        $statement->execute([$before->format(self::FORMAT)]);

        return $statement->rowCount();
    }

    public function transactional(callable $fn)
    {
        if ($this->pdo->inTransaction()) {
            return $fn();
        }
        $this->pdo->beginTransaction();
        try {
            $result = $fn();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * The prefix comes from FrontAccounting's own configuration, never from a client.
     */
    private function table(): string
    {
        return '`' . str_replace('`', '', CompanyContext::prefix()) . RefreshTokenModel::TABLE . '`';
    }
}
