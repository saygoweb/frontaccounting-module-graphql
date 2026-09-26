<?php

namespace FA\GraphQL\Type\SalesOrderLine;

use FA\GraphQL\Type\SalesOrderLine\Base\SalesOrderLineUpdateInputBase;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 *
 * A line of salesOrderUpdate's lines (Task 8 wires it). The fields FrontAccounting
 * sets itself are removed from the generated Input, as on SalesOrderLineCreateInput.
 */
class SalesOrderLineUpdateInput extends SalesOrderLineUpdateInputBase
{
    /** Set by FrontAccounting, never by a client. */
    public const SERVER_SET = SalesOrderLineCreateInput::SERVER_SET;

    protected function fields(): array
    {
        return array_values(array_filter(parent::fields(), function (array $field): bool {
            return !in_array($field['name'], self::SERVER_SET, true);
        }));
    }
}
