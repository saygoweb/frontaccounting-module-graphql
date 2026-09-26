<?php

namespace FA\GraphQL\Type\Contact;

use GraphQL\Type\Definition\EnumType;

/**
 * What a contact is for, per link. Internal values are FrontAccounting's
 * crm_contacts.action — the system categories every customer and branch has
 * (sql/en_US-new.sql:369-377).
 */
final class ContactCategoryType extends EnumType
{
    public function __construct()
    {
        parent::__construct([
            'name' => 'ContactCategory',
            'values' => [
                'GENERAL' => ['value' => 'general'],
                'ORDER' => ['value' => 'order'],
                'DELIVERY' => ['value' => 'delivery'],
                'INVOICE' => ['value' => 'invoice'],
            ],
        ]);
    }
}
