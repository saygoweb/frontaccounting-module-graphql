<?php

namespace FA\GraphQL\Fa\Service;

use FA\GraphQL\Error\FaRejected;
use FA\GraphQL\Error\NotFound;

/**
 * A sales order's row lock and version check (Release 2 spec §4.4), shared by order
 * updates, deliveries and invoicing an order in one step (Release 3 spec §3, §4):
 * each writes the order (a delivery bumps its version, sales_delivery_db.inc:34),
 * so each must hold it and must have read the current version.
 */
final class OrderLock
{
    /**
     * The row lock and the version check, inside the caller's transaction: the lock
     * holds until it commits, so nobody writes between this check and ours.
     * FrontAccounting's own check (update_sales_order()'s WHERE version = …) ignores a
     * zero-row update and rewrites the lines anyway.
     *
     * sales_orders.version is tinyint unsigned; FrontAccounting connects in strict
     * mode, so a write taking it past 255 would fail as INTERNAL and the order could
     * never change again (Release 2 Checkpoint C review M-1) — refused here first.
     */
    public static function version(int $orderId, int $version): void
    {
        $current = self::lock($orderId);
        if ($current >= SalesOrderService::VERSION_LIMIT) {
            throw new FaRejected(SalesOrderService::EDIT_LIMIT, [SalesOrderService::EDIT_LIMIT]);
        }
        if ($current !== $version) {
            throw new FaRejected(SalesOrderService::STALE, [SalesOrderService::STALE]);
        }
    }

    /**
     * @return int the order's version
     */
    public static function lock(int $orderId): int
    {
        $result = db_query(
            'SELECT version FROM ' . TB_PREF . 'sales_orders WHERE order_no = ' . db_escape($orderId)
            . ' AND trans_type = ' . ST_SALESORDER . ' FOR UPDATE',
            'could not lock the sales order'
        );
        $row = db_fetch($result);
        if (!$row) {
            throw new NotFound("There is no sales order $orderId.");
        }

        return (int) $row['version'];
    }
}
