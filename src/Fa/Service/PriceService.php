<?php

namespace FA\GraphQL\Fa\Service;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\NotFound;

/**
 * Item prices as FrontAccounting's sales pricing page writes them
 * (inventory/prices.php): a price is one row per item, price list and currency, added
 * with add_item_price() or, when that key has a row, changed with update_item_price();
 * removed with delete_item_price(). The key is resolved to the spellings stock_master,
 * sales_types and currencies hold.
 */
class PriceService
{
    /**
     * Add or change the price for an item, list and currency.
     *
     * @param array<string, mixed> $input stockId, salesTypeId, currency, price
     * @return int the price row's id
     */
    public function set(array $input): int
    {
        FaIncludes::items();
        [$stock, $list, $currency] = $this->key($input);
        $price = $input['price'] ?? null;
        if (!is_numeric($price) || (float) $price < 0) {
            throw new BadInput('The price entered must be numeric.', 'price');
        }

        $row = get_stock_price_type_currency($stock, $list, $currency);
        if ($row) {
            update_item_price($row['id'], $list, $currency, $price);

            return (int) $row['id'];
        }
        add_item_price($stock, $list, $currency, $price);

        return (int) db_insert_id();
    }

    /**
     * The id of the price row for this key.
     *
     * @param array<string, mixed> $input stockId, salesTypeId, currency
     * @throws NotFound when the key has no row
     */
    public function find(array $input): int
    {
        FaIncludes::items();
        [$stock, $list, $currency] = $this->key($input);
        $row = get_stock_price_type_currency($stock, $list, $currency);
        if (!$row) {
            throw new NotFound("There is no price for '$stock' on list $list in $currency.");
        }

        return (int) $row['id'];
    }

    /**
     * Delete a price row by the id find() gave.
     */
    public function removeById(int $id): void
    {
        FaIncludes::items();
        delete_item_price($id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: string, 1: int, 2: string} stock_id, list id, currency, as stored
     */
    private function key(array $input): array
    {
        $code = (string) ($input['stockId'] ?? '');
        $stock = StockItems::canonicalId($code);
        if ($stock === null) {
            throw new BadInput("There is no item '$code'.", 'stockId');
        }

        $list = IntKey::parse($input['salesTypeId'] ?? null, 'salesTypeId');
        if (!get_sales_type($list)) {
            throw new BadInput("There is no sales type '$list'.", 'salesTypeId');
        }

        $abbreviation = (string) ($input['currency'] ?? '');
        $row = get_currency($abbreviation);
        if (!$row) {
            throw new BadInput("There is no currency '$abbreviation'.", 'currency');
        }

        return [$stock, $list, (string) $row['curr_abrev']];
    }
}
