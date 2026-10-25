<?php

namespace FA\GraphQL\Type\Price;

use Anorm\GraphQL\Builder\FieldBuilder;
use FA\GraphQL\Type\Price\Base\PriceTypeBase;
use GraphQL\Type\Definition\Type;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 *
 * Read through priceList; written by the hand-written priceSet and priceDelete
 * (ApiSchema), never by a generated mutation.
 *
 * stockId is a String: the generator types a name ending in "Id" as ID, but an item
 * code is text, and StockItemType's id is the same code.
 */
class PriceType extends PriceTypeBase
{
    protected function fields(): array
    {
        return array_map(function (array $field): array {
            return $field['name'] === 'stockId'
                ? FieldBuilder::create('stockId', Type::string())->build()
                : $field;
        }, parent::fields());
    }

    /**
     * Reading prices and writing them are one area, SA_SALESPRICE (inventory/prices.php).
     *
     * @return array<string, string>
     */
    protected function areas(): array
    {
        return [
            'list' => 'SA_SALESPRICE',
            'create' => 'SA_SALESPRICE',
            'edit' => 'SA_SALESPRICE',
            'delete' => 'SA_SALESPRICE',
        ];
    }
}
