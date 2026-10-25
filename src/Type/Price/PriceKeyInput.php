<?php

namespace FA\GraphQL\Type\Price;

use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\Type;

/**
 * priceDelete's input: the key of the price to remove (inventory/prices.php).
 */
final class PriceKeyInput extends InputObjectType
{
    public function __construct()
    {
        parent::__construct([
            'name' => 'PriceKeyInput',
            'description' => 'The key of a price: item, price list and currency.',
            'fields' => [
                'stockId' => ['type' => Type::nonNull(Type::string())],
                'salesTypeId' => ['type' => Type::nonNull(Type::id())],
                'currency' => ['type' => Type::nonNull(Type::string())],
            ],
        ]);
    }
}
