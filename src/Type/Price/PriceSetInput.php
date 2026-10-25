<?php

namespace FA\GraphQL\Type\Price;

use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\Type;

/**
 * priceSet's input: one price per item, price list and currency (inventory/prices.php).
 */
final class PriceSetInput extends InputObjectType
{
    public function __construct()
    {
        parent::__construct([
            'name' => 'PriceSetInput',
            'description' => 'A price to add or change, keyed by item, price list and currency.',
            'fields' => [
                'stockId' => ['type' => Type::nonNull(Type::string())],
                'salesTypeId' => ['type' => Type::nonNull(Type::id())],
                'currency' => ['type' => Type::nonNull(Type::string())],
                'price' => ['type' => Type::nonNull(Type::float())],
            ],
        ]);
    }
}
