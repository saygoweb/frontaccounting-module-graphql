<?php

namespace FA\GraphQL\Type\Invoice;

use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\Type;

/**
 * How much of one delivery line to invoice. Hand-written: no generated Input fits
 * "which delivery line, how much" (Release 3 spec section 4; Release 2 spec section 1
 * allows a hand-written shape where generation cannot express one).
 */
class InvoiceLineQuantityInput extends InputObjectType
{
    public function __construct()
    {
        parent::__construct([
            'name' => 'InvoiceLineQuantityInput',
            'fields' => [
                'deliveryLineId' => [
                    'type' => Type::nonNull(Type::id()),
                    'description' => 'The delivery line (DeliveryLine id) to invoice from.',
                ],
                'quantity' => [
                    'type' => Type::nonNull(Type::float()),
                    'description' => 'From 0 to what that line has not yet had invoiced.',
                ],
            ],
        ]);
    }
}
