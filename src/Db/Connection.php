<?php

namespace FA\GraphQL\Db;

/**
 * The request's PDO, for a model constructed without one. container.php registers
 * it; Anorm's QueryBuilder and anorm-graphql always pass a PDO, so this is only
 * reached by hand-written `new XModel()`.
 */
final class Connection
{
    private static ?\PDO $pdo = null;

    public static function set(?\PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function current(): \PDO
    {
        if (self::$pdo === null) {
            throw new \LogicException('No database connection has been registered for models.');
        }

        return self::$pdo;
    }
}
