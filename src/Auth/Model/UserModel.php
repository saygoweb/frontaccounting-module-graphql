<?php

namespace FA\GraphQL\Auth\Model;

use Anorm\DataMapper;
use Anorm\Model;
use Anorm\Transform\BooleanTransform;
use FA\GraphQL\Db\Connection;
use FA\GraphQL\Fa\CompanyContext;

/**
 * A FrontAccounting user, read-only, without the password column: what is not
 * mapped cannot be selected into a response by accident.
 */
class UserModel extends Model
{
    /** @var int */
    public $id;

    /** @var string FrontAccounting's user_id: the login name */
    public $login = '';

    /** @var string */
    public $realName = '';

    /** @var string|null */
    public $email;

    /** @var int */
    public $roleId;

    /** @var bool */
    public $inactive = false;

    public function __construct(?\PDO $pdo = null)
    {
        $pdo = $pdo ?: Connection::current();
        $mapper = DataMapper::create($pdo, CompanyContext::prefix() . 'users', [
            'id' => 'id',
            'login' => 'user_id',
            'realName' => 'real_name',
            'email' => 'email',
            'roleId' => 'role_id',
            'inactive' => 'inactive',
        ]);
        // Spec §4.4: booleans are booleans. inactive is tinyint(1).
        $mapper->transformers = ['inactive' => new BooleanTransform()];
        parent::__construct($pdo, $mapper);
    }

    public static function findById(\PDO $pdo, int $id): ?self
    {
        $user = DataMapper::find(self::class, $pdo)->where('`id` = :id', [':id' => $id])->one();

        return $user instanceof self ? $user : null;
    }
}
