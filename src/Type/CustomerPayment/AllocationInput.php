<?php

namespace FA\GraphQL\Type\CustomerPayment;

use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\Type;

/**
 * One allocation of a payment to an invoice (Release 3 spec section 5).
 */
class AllocationInput extends InputObjectType
{
    public function __construct()
    {
        parent::__construct([
            'name' => 'AllocationInput',
            'description' => 'Part of a payment applied to one invoice.',
            'fields' => [
                'invoiceId' => ['type' => Type::nonNull(Type::id()), 'description' => 'The invoice (its id).'],
                'amount' => [
                    'type' => Type::nonNull(Type::float()),
                    'description' => 'In the customer\'s currency; at most what the invoice has outstanding.',
                ],
            ],
        ]);
    }
}
