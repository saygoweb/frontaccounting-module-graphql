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
}
