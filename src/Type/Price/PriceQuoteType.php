<?php

namespace FA\GraphQL\Type\Price;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * What FrontAccounting would charge for an item (Items and prices spec §3.1).
 */
class PriceQuoteType extends ObjectType
{
    public function __construct()
    {
        parent::__construct([
            'name' => 'PriceQuote',
            'fields' => [
                'stockId' => ['type' => Type::nonNull(Type::string())],
                'price' => [
                    'type' => Type::float(),
                    'description' => 'FrontAccounting\'s price; null when the item has no price on this list '
                        . 'or the base list.',
                ],
                'currency' => [
                    'type' => Type::nonNull(Type::string()),
                    'description' => 'The customer\'s currency, else the company\'s.',
                ],
                'salesTypeId' => [
                    'type' => Type::nonNull(Type::id()),
                    'description' => 'The price list quoted.',
                ],
            ],
        ]);
    }
}
