<?php

namespace FA\GraphQL\Db;

/**
 * Fields a Type works out from a row, made columns, so that query.selector and
 * query.sort can use them like any other (issue #27): an invoice's outstanding,
 * whether a payment is voided.
 *
 * Anorm reads and counts a model's rows `FROM \`<table>\``, and turns a selector's or
 * a sort's field into `\`<column>\``. So a model that names, as its table, the string
 * debtorTrans() returns reads its table joined to a derived table of the same rows
 * that adds the computed columns; its property map then names those columns like any
 * other, and the value a selector compares is the value the row carries. The derived
 * table is plain SELECT and JOIN, which MySQL and MariaDB merge into the outer query:
 * a join on the primary key, not a copy of the table.
 *
 * Fixed SQL, never input: what a client sends still goes through the map and is bound.
 * Only reads work this way: an Anorm write to such a model would name the join as its
 * table, and FaModelType refuses every generated write anyway.
 */
final class ComputedColumns
{
    /** FrontAccounting's FLOAT_COMP_DELTA (includes/current_user.inc). */
    public const FLOAT_COMP_DELTA = 0.004;

    /**
     * debtor_trans, with $columns added. Each is SQL over `t`, the debtor_trans row,
     * and `v`, its voided row if it has one (LEFT JOIN; voided is unique on type, id).
     *
     * @param array<string, string> $columns column name => SQL expression
     */
    public static function debtorTrans(string $prefix, array $columns): string
    {
        $table = $prefix . 'debtor_trans';
        $select = [];
        foreach ($columns as $name => $sql) {
            $select[] = "$sql AS `$name`";
        }

        // Anorm puts this between backticks: it opens with the table's name and
        // closes with the derived table's.
        return $table . '` NATURAL JOIN (SELECT t.`type`, t.`trans_no`, t.`debtor_no`, ' . implode(', ', $select)
            . " FROM `$table` t LEFT JOIN `{$prefix}voided` v ON v.`type` = t.`type` AND v.`id` = t.`trans_no`)"
            . " AS `{$table}_computed";
    }

    /** Whether the row has a voided entry: get_voided_entry() (admin/db/voiding_db.inc). */
    public static function voided(): string
    {
        return 'v.`id` IS NOT NULL';
    }

    /**
     * bank_trans, with $columns added: the same derived-table join as debtorTrans(),
     * on bank_trans' primary key `id`. Each column is SQL over `t`, the bank_trans row.
     *
     * @param array<string, string> $columns column name => SQL expression
     */
    public static function bankTrans(string $prefix, array $columns): string
    {
        $table = $prefix . 'bank_trans';
        $select = [];
        foreach ($columns as $name => $sql) {
            $select[] = "$sql AS `$name`";
        }

        return $table . '` NATURAL JOIN (SELECT t.`id`, ' . implode(', ', $select)
            . " FROM `$table` t) AS `{$table}_computed";
    }

    /** A voided bank entry: FrontAccounting voids one by zeroing it (void_bank_trans()). */
    public static function bankTransVoided(): string
    {
        return 't.`amount` = 0';
    }

    /**
     * FrontAccounting's Total of an invoice (custalloc_db.inc): its prepayment amount
     * if it has one, else items, tax, freight and freight tax plus ov_discount, which
     * FrontAccounting adds; to the cent.
     */
    public static function invoiceTotal(): string
    {
        return 'ROUND(IF(t.`prep_amount` > 0, t.`prep_amount`,'
            . ' t.`ov_amount` + t.`ov_gst` + t.`ov_freight` + t.`ov_freight_tax` + t.`ov_discount`), 2)';
    }

    /** The invoice's total less what is allocated to it, settled(). */
    public static function invoiceOutstanding(): string
    {
        return self::settled(self::invoiceTotal() . ' - t.`alloc`');
    }

    /** A payment's amount plus discount, less what is allocated, settled(). */
    public static function paymentUnallocated(): string
    {
        return self::settled('t.`ov_amount` + t.`ov_discount` - t.`alloc`');
    }

    /**
     * $sql to the cent, and 0 within FLOAT_COMP_DELTA of it, as FrontAccounting
     * compares amounts: what rounding leaves open is not open, so {"$gt": 0} does not
     * find it and the field reads 0.
     */
    private static function settled(string $sql): string
    {
        return "IF(ABS($sql) <= " . self::FLOAT_COMP_DELTA . ", 0, ROUND($sql, 2))";
    }
}
