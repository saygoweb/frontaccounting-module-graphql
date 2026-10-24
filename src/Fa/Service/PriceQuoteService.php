<?php

namespace FA\GraphQL\Fa\Service;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\NotFound;

/**
 * What FrontAccounting would charge for an item (Items and prices spec §3.1): the
 * price is get_price()'s (sales/includes/sales_db.inc:73-139), called as order entry
 * calls it, so the fallback to the base list times the list's factor, the home
 * currency conversion and the rounding are all FrontAccounting's.
 *
 * get_price() answers 0 both for a price of 0 and for no price at all; hasPrice()
 * tells them apart, so that a client sees null for "not priced".
 */
class PriceQuoteService
{
    /**
     * The customer's list and currency; a given list wins over the customer's; with
     * neither, the base list in the home currency.
     *
     * @return array{stockId: string, price: ?float, currency: string, salesTypeId: int}
     */
    public function quote(string $stockId, ?int $customerId = null, ?int $salesTypeId = null): array
    {
        FaIncludes::customers();
        $item = StockItems::canonicalId($stockId);
        if ($item === null) {
            throw new NotFound("There is no item '$stockId'.");
        }
        $stockId = $item;

        $currency = get_company_currency();
        $listId = null;
        if ($customerId !== null) {
            $customer = db_fetch(db_query(
                'SELECT sales_type, curr_code FROM ' . TB_PREF . 'debtors_master WHERE debtor_no = '
                . db_escape($customerId),
                'could not read the customer'
            ));
            if (!$customer) {
                throw new NotFound("There is no customer $customerId.");
            }
            $currency = (string) $customer['curr_code'];
            $listId = (int) $customer['sales_type'];
        }
        if ($salesTypeId !== null) {
            if (!get_sales_type($salesTypeId)) {
                throw new NotFound("There is no price list $salesTypeId.");
            }
            $listId = $salesTypeId;
        }
        if ($listId === null) {
            $listId = (int) get_base_sales_type();
            if ($listId <= 0) {
                throw new BadInput('No base price list is set.', 'salesTypeId');
            }
        }

        // get_price() would convert at rate 1 when there is no rate, and only
        // display_error(); order entry refuses the order (SalesOrderService::
        // assertCurrencyRate()), so a quote does too.
        if ($currency !== get_company_currency() && !db_has_currency_rates($currency, \Today())) {
            throw new BadInput(
                sprintf('There is no exchange rate for %s as of %s.', $currency, \Today()),
                'customerId'
            );
        }

        return [
            'stockId' => $stockId,
            'price' => self::hasPrice($stockId, $currency, $listId)
                ? (float) get_price($stockId, $currency, $listId, null, \Today())
                : null,
            'currency' => $currency,
            'salesTypeId' => $listId,
        ];
    }

    /**
     * Whether get_price() finds a price rather than falling through to its 0. These
     * are its selection conditions, none of its arithmetic: one of its four lookups
     * hits (a row on the list or the base list, in the currency or the home
     * currency), or the item has no row in either currency and add_pct is not -1,
     * when FrontAccounting prices from cost (sales_db.inc:96-131).
     */
    private static function hasPrice(string $stockId, string $currency, int $listId): bool
    {
        $home = get_company_currency();
        $row = db_fetch_row(db_query(
            'SELECT COUNT(*), SUM(sales_type_id IN (' . db_escape($listId) . ', '
            . db_escape((int) get_base_sales_type()) . ')) FROM ' . TB_PREF . 'prices WHERE stock_id = '
            . db_escape($stockId) . ' AND curr_abrev IN (' . db_escape($currency) . ', ' . db_escape($home) . ')',
            'could not read the prices'
        ));
        if ((int) $row[1] > 0) {
            return true;
        }

        return (int) $row[0] === 0 && (int) get_company_pref('add_pct') !== -1;
    }
}
