<?php

namespace FA\GraphQL\Type\StockItem;

use FA\GraphQL\Type\StockItem\Base\StockItemUpdateInputBase;

/**
 * Yours to edit: anorm-graphql wrote this file once and never again.
 *
 * Trims the generated fields to what the item page edits (Items and prices spec
 * §4.1). The code is the key: it names the row and cannot change.
 */
class StockItemUpdateInput extends StockItemUpdateInputBase
{
    protected function fields(): array
    {
        $keep = ['id', 'description', 'longDescription', 'categoryId', 'inactive', 'taxTypeId', 'units', 'editable'];
        $descriptions = [
            'categoryId' => 'Changing it does not re-default the accounts or tax type.',
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

        return array_map(function (string $name) use ($fields): array {
            return $fields[$name];
        }, $keep);
    }
}
