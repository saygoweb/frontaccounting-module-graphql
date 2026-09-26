<?php

namespace FA\GraphQL\Fa;

use FA\GraphQL\Error\BadInput;

/**
 * The API's dates are ISO YYYY-MM-DD (the Date scalar). FrontAccounting's functions
 * take dates in the signed-in user's format and call date2sql() on them themselves
 * (includes/date_functions.inc), so a service converts on the way in, and reads
 * back either from SQL (fromSql) or from a Cart (fromFa).
 */
final class DateConversion
{
    private const ISO = '/^(\d{4})-(\d{2})-(\d{2})$/';

    /**
     * @param mixed $date a \DateTimeInterface (the Date scalar's value) or a Y-m-d string
     */
    public static function iso($date, ?string $field = null): string
    {
        if ($date instanceof \DateTimeInterface) {
            return $date->format('Y-m-d');
        }
        // \z, not $: a trailing newline is not a date.
        if (
            is_string($date) && preg_match('/^(\d{4})-(\d{2})-(\d{2})\z/', $date, $m)
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1])
        ) {
            return $date;
        }
        throw new BadInput('Expected a date as YYYY-MM-DD.', $field);
    }

    /**
     * @param mixed $date
     */
    public static function toFa($date, ?string $field = null): string
    {
        return sql2date(self::iso($date, $field));
    }

    public static function fromFa(string $faDate): string
    {
        return date2sql($faDate);
    }

    public static function fromSql(?string $sqlDate): ?string
    {
        if ($sqlDate === null) {
            return null;
        }
        $date = substr(trim($sqlDate), 0, 10);
        if ($date === '' || $date === '0000-00-00') {
            return null;
        }
        if (!preg_match(self::ISO, $date)) {
            throw new \UnexpectedValueException('Not an SQL date: ' . $sqlDate);
        }

        return $date;
    }
}
