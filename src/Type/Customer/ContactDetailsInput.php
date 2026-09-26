<?php

namespace FA\GraphQL\Type\Customer;

use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\Type;

/**
 * customerCreate's `contact`: the CRM person the customer page creates with the
 * default branch (customers.php:121-127), named and addressed as the customer and
 * linked to the customer and the branch.
 */
final class ContactDetailsInput extends InputObjectType
{
    public function __construct()
    {
        parent::__construct([
            'name' => 'ContactDetailsInput',
            'description' => "Phone and email of the contact created with the customer's default branch.",
            'fields' => [
                'phone' => ['type' => Type::string()],
                'phone2' => ['type' => Type::string()],
                'fax' => ['type' => Type::string()],
                'email' => ['type' => Type::string()],
            ],
        ]);
    }
}
