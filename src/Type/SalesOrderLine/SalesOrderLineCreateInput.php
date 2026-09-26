<?php

namespace FA\GraphQL\Type\SalesOrderLine;

use FA\GraphQL\Type\SalesOrderLine\Base\SalesOrderLineCreateInputBase;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 *
 * A new line, nested in SalesOrderCreateInput.lines and SalesOrderUpdateInput.lines.
 * The fields FrontAccounting sets itself are removed from the generated Input.
 */
class SalesOrderLineCreateInput extends SalesOrderLineCreateInputBase
{
    /** Set by FrontAccounting, never by a client. */
    public const SERVER_SET = ['orderId', 'transType', 'qtyDelivered', 'qtyInvoiced'];

    protected function fields(): array
    {
        return array_values(array_filter(parent::fields(), function (array $field): bool {
            return !in_array($field['name'], self::SERVER_SET, true);
        }));
    }
}
