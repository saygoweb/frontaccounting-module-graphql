<?php

namespace FA\GraphQL\Fa\Service;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\NotFound;
use FA\GraphQL\Fa\FaText;

/**
 * Items, written through FrontAccounting's own add_item() and update_item() the way
 * inventory/manage/items.php writes them (Items and prices spec §4.1; upstream master
 * line numbers).
 *
 * Run it inside ServiceCall::each(): it opens no transaction of its own. Input is the
 * trimmed StockItemCreateInput / StockItemUpdateInput as an array, its strings
 * already cleaned as FrontAccounting's POST is; db_escape() does the escaping.
 */
final class StockItemService
{
    /** The page's forbidden characters (items.php:230-232). */
    private const BAD_CHARS = [' ', "'", '+', '"', '&', "\t"];

    /** stock_master.stock_id is varchar(20): twenty characters. */
    private const CODE_LENGTH = 20;

    /** stock_master.description is varchar(200). */
    private const DESCRIPTION_LENGTH = 200;

    /** stock_master.long_description is tinytext. */
    private const LONG_DESCRIPTION_BYTES = 255;

    private const REFS = [
        'categoryId' => ['stock_category', 'category_id', 'item category', true],
        'taxTypeId' => ['item_tax_types', 'id', 'tax type', true],
        'units' => ['item_units', 'abbr', 'unit', false],
    ];

    /** Update fields that may not be set to null: each names a NOT NULL column. */
    private const NOT_NULL = ['description', 'categoryId', 'taxTypeId', 'units', 'editable'];

    /**
     * @param array<string, mixed> $input
     * @return string the stock_id stored
     */
    public function create(array $input): string
    {
        FaIncludes::items();
        $code = (string) ($input['stockId'] ?? '');
        $description = (string) ($input['description'] ?? '');
        // items.php:218-244, in its order and with its messages.
        self::checkDescription($description);
        self::checkLongDescription((string) ($input['longDescription'] ?? ''));
        if (strlen($code) === 0) {
            throw new BadInput('The item code cannot be empty', 'stockId');
        }
        foreach (self::BAD_CHARS as $char) {
            if (strpos($code, $char) !== false) {
                throw new BadInput(
                    'The item code cannot contain any of the following characters -  & + OR a space OR quotes',
                    'stockId'
                );
            }
        }
        if (mb_strlen($code, FaText::charset()) > self::CODE_LENGTH) {
            throw new BadInput('The item code cannot be longer than 20 characters.', 'stockId');
        }
        if (db_num_rows(get_item_kit($code)) > 0) {
            throw new BadInput('This item code is already assigned to stock item or sale kit.', 'stockId');
        }

        // items.php:397-411: a new item's defaults are its category's; the overrides win.
        $category = $this->category($input['categoryId'] ?? null);
        $item = [
            'categoryId' => (int) $category['category_id'],
            'taxTypeId' => $input['taxTypeId'] ?? $category['dflt_tax_type'],
            'units' => $input['units'] ?? $category['dflt_units'],
        ];
        ReferenceCheck::requireAll($item, self::REFS);
        $editable = array_key_exists('editable', $input) && $input['editable'] !== null
            ? (bool) $input['editable']
            : true;

        add_item(
            $code,
            $description,
            (string) ($input['longDescription'] ?? ''),
            $item['categoryId'],
            (int) $item['taxTypeId'],
            (string) $item['units'],
            $category['dflt_mb_flag'],
            $category['dflt_sales_act'],
            $category['dflt_inventory_act'],
            $category['dflt_cogs_act'],
            $category['dflt_adjustment_act'],
            $category['dflt_wip_act'],
            $category['dflt_dim1'],
            $category['dflt_dim2'],
            $category['dflt_no_sale'],
            $editable ? 1 : 0,
            $category['dflt_no_purchase']
        );
        if (!empty($input['inactive'])) {
            self::setInactive($code, true);
        }

        return $code;
    }

    /**
     * update_item() rewrites every column, so each one not given is passed as the
     * row has it. A new category re-defaults nothing: the page re-defaults only for a
     * new item.
     *
     * Only the references the input gives are checked: an item whose stored unit has
     * since gone can still be edited.
     *
     * @param array<string, mixed> $input with a string 'id'
     * @return string the stock_id stored, in stock_master's spelling
     */
    public function update(array $input): string
    {
        FaIncludes::items();
        $id = (string) ($input['id'] ?? '');
        // get_item() has no row for a kit or a foreign code: no stock_master row.
        $row = get_item($id);
        // FA reserves fixed assets for SA_ASSET (items.php:24): not served as items here.
        if (!$row || $row['mb_flag'] === 'F') {
            throw new NotFound("Stock item id '$id' not found");
        }
        foreach (self::NOT_NULL as $field) {
            if (array_key_exists($field, $input) && $input[$field] === null) {
                throw new BadInput("$field cannot be null.", $field);
            }
        }
        $item = [
            'description' => (string) ($input['description'] ?? $row['description']),
            'longDescription' => array_key_exists('longDescription', $input)
                ? (string) $input['longDescription']
                : (string) $row['long_description'],
            'categoryId' => $input['categoryId'] ?? $row['category_id'],
            'taxTypeId' => $input['taxTypeId'] ?? $row['tax_type_id'],
            'units' => $input['units'] ?? $row['units'],
            'editable' => array_key_exists('editable', $input) ? (bool) $input['editable'] : (bool) $row['editable'],
        ];
        self::checkDescription($item['description']);
        self::checkLongDescription($item['longDescription']);
        if (array_key_exists('categoryId', $input)) {
            $category = $this->category($item['categoryId']);
            $item['categoryId'] = (int) $category['category_id'];
        }
        ReferenceCheck::requireAll($item, array_intersect_key(self::REFS, $input));

        // stock_master's own spelling of the code: get_item() matched it by collation.
        $code = (string) $row['stock_id'];
        update_item(
            $code,
            $item['description'],
            $item['longDescription'],
            $item['categoryId'],
            (int) $item['taxTypeId'],
            (string) $item['units'],
            $row['mb_flag'],
            $row['sales_account'],
            $row['inventory_account'],
            $row['cogs_account'],
            $row['adjustment_account'],
            $row['wip_account'],
            $row['dimension_id'],
            $row['dimension2_id'],
            $row['no_sale'],
            $item['editable'] ? 1 : 0,
            $row['no_purchase'],
            $row['depreciation_method'],
            $row['depreciation_rate'],
            $row['depreciation_factor'],
            null,
            $row['fa_class_id']
        );
        if (array_key_exists('inactive', $input) && $input['inactive'] !== null) {
            self::setInactive($code, (bool) $input['inactive']);
        }

        return $code;
    }

    private static function checkDescription(string $description): void
    {
        if (strlen($description) === 0) {
            throw new BadInput('The item name must be entered.', 'description');
        }
        // stock_master.description is varchar(200): characters.
        if (mb_strlen($description, FaText::charset()) > self::DESCRIPTION_LENGTH) {
            throw new BadInput('The item name cannot be longer than 200 characters.', 'description');
        }
    }

    /** stock_master.long_description is tinytext: 255 bytes. */
    private static function checkLongDescription(string $text): void
    {
        if (strlen($text) > self::LONG_DESCRIPTION_BYTES) {
            throw new BadInput('The description cannot be longer than 255 bytes.', 'longDescription');
        }
    }

    /**
     * The category, as get_item_category() reads it. A fixed-asset category's items
     * are made on FrontAccounting's fixed-asset page, not here.
     *
     * @param mixed $id
     * @return array<string, mixed>
     */
    private function category($id): array
    {
        $categoryId = IntKey::parse($id, 'categoryId');
        $category = get_item_category($categoryId);
        if (!$category) {
            throw new BadInput("There is no item category '$categoryId'.", 'categoryId');
        }
        if ($category['dflt_mb_flag'] === 'F') {
            throw new BadInput("Category '$categoryId' is for fixed assets.", 'categoryId');
        }

        return $category;
    }

    /** items.php:276-279: the item and its own item code. */
    private static function setInactive(string $code, bool $inactive): void
    {
        update_record_status($code, $inactive ? 1 : 0, 'stock_master', 'stock_id');
        update_record_status($code, $inactive ? 1 : 0, 'item_codes', 'item_code');
    }
}
