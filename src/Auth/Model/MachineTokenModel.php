<?php

namespace FA\GraphQL\Auth\Model;

use Anorm\DataMapper;
use Anorm\Model;
use FA\GraphQL\Db\Connection;
use FA\GraphQL\Fa\CompanyContext;

/**
 * A row of <prefix>graphql_machine_token (spec §3.7). Internal: no GraphQL Type is
 * generated for it, and nothing but AnormMachineTokenRepository reads it.
 *
 * The datetimes stay UTC strings, as RefreshTokenModel's do and for the same reason
 * (spec §4.4, revised); the repository converts them with an explicit UTC zone.
 */
class MachineTokenModel extends Model
{
    /** The unprefixed table name, shared with the repository's raw statements. */
    public const TABLE = 'graphql_machine_token';

    /** @var int|null */
    public $id;

    /** @var string */
    public $jti = '';

    /** @var string FrontAccounting's user_id: the login name */
    public $login = '';

    /** @var string */
    public $label = '';

    /** @var string UTC, Y-m-d H:i:s */
    public $issuedAt;

    /** @var string UTC, Y-m-d H:i:s */
    public $expiresAt;

    /** @var string|null UTC, Y-m-d H:i:s */
    public $revokedAt;

    /** @var string|null UTC, Y-m-d H:i:s */
    public $lastUsedAt;

    public function __construct(?\PDO $pdo = null)
    {
        $pdo = $pdo ?: Connection::current();
        parent::__construct($pdo, DataMapper::create($pdo, CompanyContext::prefix() . self::TABLE, [
            'id' => 'id',
            'jti' => 'jti',
            'login' => 'login',
            'label' => 'label',
            'issuedAt' => 'issued_at',
            'expiresAt' => 'expires_at',
            'revokedAt' => 'revoked_at',
            'lastUsedAt' => 'last_used_at',
        ]));
    }
}
