<?php

namespace FA\GraphQL\Type\DeliveryLine;

use FA\GraphQL\Type\DeliveryLine\Base\DeliveryLineCreateInputBase;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 *
 * A line to deliver, nested in DeliveryCreateInput.lines: the order line and how
 * much of it. What FrontAccounting takes from the order line is removed from the
 * generated Input.
 */
class DeliveryLineCreateInput extends DeliveryLineCreateInputBase
{
    /** What FrontAccounting takes from the order line. */
    public const SERVER_SET = [
        'deliveryId', 'transType', 'stockId', 'unitPrice', 'unitTax', 'discountPercent', 'standardCost',
        'qtyInvoiced',
    ];

    protected function fields(): array
    {
        return array_values(array_filter(parent::fields(), function (array $field): bool {
            return !in_array($field['name'], self::SERVER_SET, true);
        }));
    }
}
