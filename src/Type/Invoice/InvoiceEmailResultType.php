<?php

namespace FA\GraphQL\Type\Invoice;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/** One invoice's outcome from invoiceEmail. */
class InvoiceEmailResultType extends ObjectType
{
    public function __construct()
    {
        parent::__construct([
            'name' => 'InvoiceEmailResult',
            'fields' => [
                'id' => ['type' => Type::nonNull(Type::id())],
                'sent' => [
                    'type' => Type::nonNull(Type::boolean()),
                    'description' => 'FrontAccounting reported the invoice sent (handed to the mail transport).',
                ],
                'recipient' => [
                    'type' => Type::string(),
                    'description' => 'The address FrontAccounting sent it to, when sent.',
                ],
                'messages' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull(Type::string()))),
                    'description' => "FrontAccounting's messages, e.g. why it was not sent.",
                ],
            ],
        ]);
    }
}
