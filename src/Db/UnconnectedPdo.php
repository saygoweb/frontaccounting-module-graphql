<?php

namespace FA\GraphQL\Db;

/**
 * A PDO that never connects: for building a model only to read its property map,
 * when the schema is built (issue #21) and there may be no database at all, as in
 * the unit tests. Anorm's Model constructor sets the error mode, which this accepts
 * and ignores; any statement fails, as an unconstructed PDO's do.
 */
final class UnconnectedPdo extends \PDO
{
    public function __construct()
    {
    }

    /**
     * @param mixed $attribute
     * @param mixed $value
     */
    #[\ReturnTypeWillChange]
    public function setAttribute($attribute, $value): bool
    {
        return true;
    }
}
