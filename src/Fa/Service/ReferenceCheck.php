<?php

namespace FA\GraphQL\Fa\Service;

use FA\GraphQL\Error\BadInput;

/**
 * FrontAccounting's pages offer references from lists; an API must check what it
 * is sent. Through FrontAccounting's own key_in_foreign_table()
 * (admin/db/company_db.inc:146), which db_escape()s the value.
 */
final class ReferenceCheck
{
    /**
     * @param mixed $value
     */
    public static function exists(string $table, string $column, $value): bool
    {
        return (int) key_in_foreign_table($value, $table, $column) > 0;
    }

    /**
     * An integer-keyed reference must be a whole number first (IntKey): the
     * existence check alone would let MySQL cast "1 x" to 1.
     *
     * @param array<string, mixed> $values field => value
     * @param array<string, array{0: string, 1: string, 2: string, 3: bool}> $refs
     *        field => [table, column, noun, whether the column is an integer]
     */
    public static function requireAll(array $values, array $refs, string $fieldPrefix = ''): void
    {
        foreach ($refs as $field => [$table, $column, $noun, $integer]) {
            $value = $values[$field] ?? null;
            if ($value === null || $value === '') {
                throw new BadInput("$fieldPrefix$field is required.", $fieldPrefix . $field);
            }
            if ($integer) {
                IntKey::parse($value, $fieldPrefix . $field);
            }
            if (!self::exists($table, $column, $value)) {
                throw new BadInput("There is no $noun '$value'.", $fieldPrefix . $field);
            }
        }
    }
}
