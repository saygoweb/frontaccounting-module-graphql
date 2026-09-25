<?php

namespace FA\GraphQL\Auth\Model;

use Anorm\DataMapper;
use Anorm\Model;
use FA\GraphQL\Db\Connection;
use FA\GraphQL\Fa\CompanyContext;

/**
 * A row of <prefix>graphql_refresh_token.
 *
 * The three datetimes stay strings, deliberately (spec §4.4, revised): the column
 * values are UTC, and Anorm's SqlDateTimeTransform reads and writes them in PHP's
 * default timezone, which FrontAccounting sets from its own configuration. The one
 * reader, AnormRefreshTokenRepository, converts them with an explicit UTC zone.
 */
class RefreshTokenModel extends Model
{
    /**
     * The unprefixed table name, shared with AnormRefreshTokenRepository's raw
     * statements so the two never drift apart.
     */
    public const TABLE = 'graphql_refresh_token';

    /** @var int|null */
    public $id;

    /** @var int */
    public $userId;

    /** @var string */
    public $tokenHash = '';

    /** @var string UTC, Y-m-d H:i:s */
    public $issuedAt;

    /** @var string UTC, Y-m-d H:i:s */
    public $expiresAt;

    /** @var string|null UTC, Y-m-d H:i:s */
    public $revokedAt;

    /** @var int|null */
    public $replacedBy;

    /** @var string */
    public $client = '';

    public function __construct(?\PDO $pdo = null)
    {
        $pdo = $pdo ?: Connection::current();
        parent::__construct($pdo, DataMapper::create($pdo, CompanyContext::prefix() . self::TABLE, [
            'id' => 'id',
            'userId' => 'user_id',
            'tokenHash' => 'token_hash',
            'issuedAt' => 'issued_at',
            'expiresAt' => 'expires_at',
            'revokedAt' => 'revoked_at',
            'replacedBy' => 'replaced_by',
            'client' => 'client',
        ]));
    }
}
