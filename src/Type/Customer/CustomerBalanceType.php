<?php

namespace FA\GraphQL\Type\Customer;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * FrontAccounting's customer balance and ageing (get_customer_details(),
 * sales/includes/db/customers_db.inc:68-122), in the customer's currency.
 */
class CustomerBalanceType extends ObjectType
{
    public function __construct()
    {
        parent::__construct([
            'name' => 'CustomerBalance',
            'fields' => [
                'balance' => [
                    'type' => Type::nonNull(Type::float()),
                    'description' => 'Invoiced less paid and credited.',
                ],
                'due' => ['type' => Type::nonNull(Type::float()), 'description' => 'Past its due date.'],
                'overdue1' => [
                    'type' => Type::nonNull(Type::float()),
                    'description' => 'Due for the company\'s past-due days or more.',
                ],
                'overdue2' => ['type' => Type::nonNull(Type::float()), 'description' => 'Due for twice that or more.'],
                'currency' => ['type' => Type::nonNull(Type::string())],
            ],
        ]);
    }
}
