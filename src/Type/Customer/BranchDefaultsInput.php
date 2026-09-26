<?php

namespace FA\GraphQL\Type\Customer;

use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\Type;

/**
 * customerCreate's `branch`: the default branch FrontAccounting's customer page
 * creates with a new customer while auto_create_branch is on (customers.php:112-119).
 * Named as the generated Branch Type names these references.
 */
final class BranchDefaultsInput extends InputObjectType
{
    public function __construct()
    {
        parent::__construct([
            'name' => 'BranchDefaultsInput',
            'description' => "The new customer's default branch, created with it while "
                . "FrontAccounting's auto_create_branch is on. Name, reference and address come from the customer; "
                . 'GL accounts from the company preferences.',
            'fields' => [
                'salesmanId' => ['type' => Type::nonNull(Type::id())],
                'salesAreaId' => ['type' => Type::nonNull(Type::id())],
                'taxGroupId' => ['type' => Type::nonNull(Type::id())],
                'locationId' => ['type' => Type::nonNull(Type::id())],
                'shipperId' => ['type' => Type::nonNull(Type::id())],
            ],
        ]);
    }
}
