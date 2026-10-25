<?php

namespace FA\GraphQL\Type\StockItem;

use Anorm\GraphQL\Builder\FieldBuilder;
use FA\GraphQL\Type\StockItem\Base\StockItemCreateInputBase;
use GraphQL\Type\Definition\Type;

/**
 * Yours to edit: anorm-graphql wrote this file once and never again.
 *
 * Trims the generated fields to what the item page takes (Items and prices spec
 * §4.1): mbFlag and noSale come from the category, and the code is the item's
 * key, which the page lets the user type.
 */
class StockItemCreateInput extends StockItemCreateInputBase
{
    protected function fields(): array
    {
        $keep = ['description', 'categoryId', 'longDescription', 'inactive', 'taxTypeId', 'units', 'editable'];
        $descriptions = [
            'taxTypeId' => "Defaults to the category's tax type.",
            'units' => "Defaults to the category's units.",
            'editable' => 'Whether an order line may change the description. Defaults to true.',
        ];
        $fields = [];
        foreach (parent::fields() as $field) {
            if (!in_array($field['name'], $keep, true)) {
                continue;
            }
            if (isset($descriptions[$field['name']])) {
                $field['description'] = $descriptions[$field['name']];
            }
            $fields[$field['name']] = $field;
        }

        $ordered = [
            FieldBuilder::create('stockId', Type::nonNull(Type::string()))
                ->setDescription(
                    "Item code, as FA's item page takes it (no spaces, quotes, + or &; at most 20 characters)."
                )
                ->build(),
        ];
        foreach ($keep as $name) {
            $ordered[] = $fields[$name];
        }
        return $ordered;
    }
}
