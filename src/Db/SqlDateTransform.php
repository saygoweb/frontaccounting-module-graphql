<?php

namespace FA\GraphQL\Db;

use Anorm\TransformInterface;

/**
 * A DATE column as a \DateTimeImmutable (the Date scalar's value), and
 * FrontAccounting's zero date — the default of its NOT NULL date columns — as null.
 * Anorm's SqlDateTimeTransform would read '0000-00-00' as -0001-11-30.
 */
final class SqlDateTransform implements TransformInterface
{
    public function txDatabaseToModel($value)
    {
        if ($value === null || $value === '' || strpos((string) $value, '0000-00-00') === 0) {
            return null;
        }

        return new \DateTimeImmutable(substr((string) $value, 0, 10), new \DateTimeZone('UTC'));
    }

    public function txModelToDatabase($value)
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return $value;
    }
}
