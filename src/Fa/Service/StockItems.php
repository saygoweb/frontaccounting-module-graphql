<?php

namespace FA\GraphQL\Fa\Service;

/**
 * Items as stock_master holds them. A code is looked up with MySQL's collation, so
 * '103 ' or a different case finds '103'; the answer is stock_master's own spelling,
 * for comparing with a stored code and for writing. A kit or a foreign code has
 * item_codes rows but no stock_master row, and is not an item here.
 */
final class StockItems
{
    /**
     * The stock_id as stock_master spells it, or null for an unknown code, a kit or
     * a foreign code. Callers throw their own error for null.
     */
    public static function canonicalId(string $code): ?string
    {
        $row = db_fetch(db_query(
            'SELECT stock_id FROM ' . TB_PREF . 'stock_master WHERE stock_id = ' . db_escape($code),
            'could not read the item'
        ));

        return $row ? (string) $row['stock_id'] : null;
    }
}
