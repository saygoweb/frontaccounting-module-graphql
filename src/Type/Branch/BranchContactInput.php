<?php

namespace FA\GraphQL\Type\Branch;

use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\Type;

/**
 * branchCreate's `contact`: the CRM person FrontAccounting's branch page creates
 * with every new branch (customer_branches.php:101-105). Without it the person is
 * named "Main Branch" for a customer's first branch, otherwise after the branch.
 */
final class BranchContactInput extends InputObjectType
{
    public function __construct()
    {
        parent::__construct([
            'name' => 'BranchContactInput',
            'fields' => [
                'name' => ['type' => Type::string(), 'description' => 'Also its reference, as on the page.'],
                'phone' => ['type' => Type::string()],
                'phone2' => ['type' => Type::string()],
                'fax' => ['type' => Type::string()],
                'email' => ['type' => Type::string()],
                'lang' => ['type' => Type::string(), 'description' => 'Document language code.'],
            ],
        ]);
    }
}
