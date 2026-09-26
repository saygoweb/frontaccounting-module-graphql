<?php

namespace FA\GraphQL\Type\Contact;

use GraphQL\Type\Definition\EnumType;

/** What a contact is linked to. Internal values are FrontAccounting's crm_contacts.type. */
final class ContactEntityType extends EnumType
{
    public function __construct()
    {
        parent::__construct([
            'name' => 'ContactEntity',
            'values' => [
                'CUSTOMER' => ['value' => 'customer'],
                'BRANCH' => ['value' => 'cust_branch'],
            ],
        ]);
    }
}
