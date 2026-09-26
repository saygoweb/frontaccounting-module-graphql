<?php

namespace FA\GraphQL\Fa\Service;

use FA\GraphQL\Error\BadInput;

/**
 * A client-supplied value naming an integer key: a row's own id, or a reference to
 * another table's integer key. GraphQL hands IDs over as strings, and MySQL compares
 * a string with an integer column by casting it — "5 anything" finds row 5, and
 * FrontAccounting's strict SQL mode then refuses the write as INTERNAL. So anything
 * but a canonical positive whole number is BAD_INPUT, naming the field.
 */
final class IntKey
{
    /**
     * @param mixed $value
     */
    public static function parse($value, string $field): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,9}$/', $value) === 1 && (int) $value <= 2147483647) {
            return (int) $value;
        }
        throw new BadInput("$field must be a positive whole number.", $field);
    }
}
