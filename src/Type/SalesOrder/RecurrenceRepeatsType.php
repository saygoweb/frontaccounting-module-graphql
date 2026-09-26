<?php

namespace FA\GraphQL\Type\SalesOrder;

use GraphQL\Type\Definition\EnumType;

/**
 * How a recurring order repeats. The values are sgw_sales' own column values
 * (sales_recurring.repeats), so a parsed input needs no mapping.
 */
final class RecurrenceRepeatsType extends EnumType
{
    public function __construct()
    {
        parent::__construct([
            'name' => 'RecurrenceRepeats',
            'description' => 'How a recurring order repeats (sgw_sales).',
            'values' => [
                'MONTH' => ['value' => 'month'],
                'YEAR' => ['value' => 'year'],
            ],
        ]);
    }
}
