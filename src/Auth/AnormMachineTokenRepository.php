<?php

namespace FA\GraphQL\Auth;

use Anorm\DataMapper;
use FA\GraphQL\Auth\Model\MachineTokenModel;
use FA\GraphQL\Fa\CompanyContext;

/**
 * Inserts and lookups through the Anorm model; the conditional updates are plain
 * prepared statements, as AnormRefreshTokenRepository's are.
 */
class AnormMachineTokenRepository implements MachineTokenRepository
{
    private const FORMAT = 'Y-m-d H:i:s';

    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function insert(MachineTokenRecord $record): int
    {
        $model = new MachineTokenModel($this->pdo);
        $model->jti = $record->jti;
        $model->login = $record->login;
        $model->label = $record->label;
        $model->issuedAt = $record->issuedAt->format(self::FORMAT);
        $model->expiresAt = $record->expiresAt->format(self::FORMAT);
        $model->revokedAt = self::format($record->revokedAt);
        $model->lastUsedAt = self::format($record->lastUsedAt);
        $model->write();

        return (int) $model->id;
    }

    public function findByJti(string $jti): ?MachineTokenRecord
    {
        $model = DataMapper::find(MachineTokenModel::class, $this->pdo)
            ->where('`jti` = :jti', [':jti' => $jti])
            ->one();

        return $model instanceof MachineTokenModel ? self::record($model) : null;
    }

    public function all(): array
    {
        $records = [];
        $models = DataMapper::find(MachineTokenModel::class, $this->pdo)->orderBy('`id`')->some();
        foreach ($models as $model) {
            if ($model instanceof MachineTokenModel) {
                $records[] = self::record($model);
            }
        }

        return $records;
    }

    public function revoke(string $jti, \DateTimeImmutable $at): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE ' . $this->table() . ' SET `revoked_at` = ? WHERE `jti` = ? AND `revoked_at` IS NULL'
        );
        $statement->execute([$at->format(self::FORMAT), $jti]);

        return $statement->rowCount() === 1;
    }

    public function touch(string $jti, \DateTimeImmutable $at, \DateTimeImmutable $staleBefore): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE ' . $this->table() . ' SET `last_used_at` = ?'
            . ' WHERE `jti` = ? AND (`last_used_at` IS NULL OR `last_used_at` <= ?)'
        );
        $statement->execute([$at->format(self::FORMAT), $jti, $staleBefore->format(self::FORMAT)]);

        return $statement->rowCount() === 1;
    }

    private static function record(MachineTokenModel $model): MachineTokenRecord
    {
        $record = new MachineTokenRecord();
        $record->id = (int) $model->id;
        $record->jti = (string) $model->jti;
        $record->login = (string) $model->login;
        $record->label = (string) $model->label;
        $record->issuedAt = self::parse((string) $model->issuedAt);
        $record->expiresAt = self::parse((string) $model->expiresAt);
        $record->revokedAt = $model->revokedAt === null ? null : self::parse((string) $model->revokedAt);
        $record->lastUsedAt = $model->lastUsedAt === null ? null : self::parse((string) $model->lastUsedAt);

        return $record;
    }

    private static function parse(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    private static function format(?\DateTimeImmutable $value): ?string
    {
        return $value === null ? null : $value->format(self::FORMAT);
    }

    /**
     * The prefix comes from FrontAccounting's own configuration, never from a client.
     */
    private function table(): string
    {
        return '`' . str_replace('`', '', CompanyContext::prefix()) . MachineTokenModel::TABLE . '`';
    }
}
