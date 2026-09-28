<?php

namespace FA\GraphQL\Fa\Service;

use FA\GraphQL\Fa\Bootstrap;

/**
 * The FrontAccounting files a write path needs, included on first use: Bootstrap
 * loads only what every request needs.
 */
final class FaIncludes
{
    /**
     * sales_db.inc brings customers_db.inc, branches_db.inc and banking.inc
     * (get_company_currency); crm_contacts_db.inc is included by nothing Bootstrap
     * loads. key_in_foreign_table() and get_company_pref() come with sysprefs.inc.
     */
    public static function customers(): void
    {
        Bootstrap::includeFa('sales/includes/sales_db.inc');
        Bootstrap::includeFa('includes/db/crm_contacts_db.inc');
    }

    /**
     * What a Cart needs beyond boot. includes/ui.inc is taken for granted by
     * FrontAccounting's sales code (count_array() in sales_db.inc, for one); other
     * extensions include it for the same reason. sales_order_ui.inc is for
     * get_customer_details_to_order(); its display functions are never called.
     */
    public static function orders(): void
    {
        self::customers();
        foreach (
            [
            'includes/ui.inc',
            'includes/db/inventory_db.inc',
            'sales/includes/cart_class.inc',
            'sales/includes/ui/sales_order_ui.inc',
            'inventory/includes/db/items_codes_db.inc',
            'inventory/includes/db/items_locations_db.inc',
            'admin/db/fiscalyears_db.inc',
            'admin/db/shipping_db.inc',
            ] as $file
        ) {
            Bootstrap::includeFa($file);
        }
    }

    /**
     * Deliveries, invoices, payments, allocations and voids beyond orders():
     * sales/customer_payments.php:19-24, sales/allocations/customer_allocate.php:17-21,
     * admin/void_transaction.php:18-22. Listed whether or not sales_db.inc already
     * brings them (includeFa is include_once): payment_db.inc and custalloc_db.inc
     * (payments, allocations — Task 5), gl_db.inc (postings), allocations_db.inc and
     * allocation_cart.inc (the allocation class), voiding_db.inc and
     * audit_trail_db.inc (voids).
     */
    public static function billing(): void
    {
        self::orders();
        foreach (
            [
            'sales/includes/sales_db.inc',
            'sales/includes/db/payment_db.inc',
            'sales/includes/db/custalloc_db.inc',
            'gl/includes/gl_db.inc',
            'includes/db/allocations_db.inc',
            'includes/ui/allocation_cart.inc',
            'admin/db/voiding_db.inc',
            'includes/db/audit_trail_db.inc',
            ] as $file
        ) {
            Bootstrap::includeFa($file);
        }
    }
}
