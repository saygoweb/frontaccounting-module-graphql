<?php

namespace FA\GraphQL\Fa\Service;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\NotFound;
use FA\GraphQL\Fa\FaText;

/**
 * Price lists (sales types), written through FrontAccounting's own functions the way
 * sales/manage/sales_types.php writes them (upstream master).
 *
 * Run it inside ServiceCall::each(): it opens no transaction of its own.
 *
 * Input is the generated SalesTypeCreateInput / SalesTypeUpdateInput as an array. The
 * page's checks keep its messages; sales_types.sales_type is UNIQUE, which the page
 * leaves to the database, so an API names the field.
 */
final class SalesTypeService
{
    /** The generated input fields this service writes (the key and inactive aside). */
    private const FIELDS = ['name', 'taxIncluded', 'factor'];

    /**
     * @param array<string, mixed> $input
     */
    public function create(array $input): int
    {
        FaIncludes::items();
        $list = $this->validated($input, ['name' => '', 'taxIncluded' => false, 'factor' => 1.0], null);
        add_sales_type($list['name'], $list['taxIncluded'] ? 1 : 0, self::sqlNumber((float) $list['factor']));
        $id = (int) db_insert_id();
        if (!empty($input['inactive'])) {
            update_record_status($id, 1, 'sales_types', 'id');
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $input with an int 'id'
     */
    public function update(array $input): void
    {
        FaIncludes::items();
        $id = (int) $input['id'];
        $row = get_sales_type($id);
        if (!$row) {
            throw new NotFound("Sales type id '$id' not found");
        }
        $list = $this->validated($input, [
            'name' => $row['sales_type'],
            'taxIncluded' => (bool) $row['tax_included'],
            'factor' => $row['factor'],
        ], $id);
        update_sales_type($id, $list['name'], $list['taxIncluded'] ? 1 : 0, self::sqlNumber((float) $list['factor']));
        if (array_key_exists('inactive', $input) && $input['inactive'] !== null) {
            update_record_status($id, $input['inactive'] ? 1 : 0, 'sales_types', 'id');
        }
    }

    /**
     * $base with the input's fields over it, then checked: the page's two checks
     * (sales_types.php, its order and messages), then the unique name.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    private function validated(array $input, array $base, ?int $id): array
    {
        $list = $base;
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            if ($input[$field] === null) {
                throw new BadInput("$field cannot be null.", $field);
            }
            $list[$field] = $input[$field];
        }

        if (strlen((string) $list['name']) === 0) {
            throw new BadInput('The sales type description cannot be empty.', 'name');
        }
        if (!is_numeric($list['factor']) || (float) $list['factor'] < 0) {
            throw new BadInput('Calculation factor must be valid positive number.', 'factor');
        }

        // The name is as the database stores it (cleaned input), so this compares like for like.
        $result = db_query(
            'SELECT id FROM ' . TB_PREF . 'sales_types WHERE sales_type = ' . db_escape((string) $list['name']),
            'could not check the price list name'
        );
        while ($same = db_fetch($result)) {
            if ((int) $same['id'] !== $id) {
                $name = FaText::decode((string) $list['name']);
                throw new BadInput("A price list named '$name' already exists.", 'name');
            }
        }

        return $list;
    }

    /** add_sales_type() puts the factor into its SQL unquoted: a plain number, never locale-formatted. */
    private static function sqlNumber(float $value): string
    {
        return sprintf('%.6F', $value);
    }
}
