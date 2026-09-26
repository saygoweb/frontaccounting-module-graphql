<?php

namespace FA\GraphQL\Type\SalesOrderLine;

use FA\GraphQL\Type\SalesOrderLine\Base\SalesOrderLineUpdateInputBase;
use GraphQL\Type\Definition\Type;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 *
 * A line in SalesOrderUpdateInput.lines, which replaces the order's set: with an id
 * it is that existing line, updated; without one it is new. So the generated
 * `id: ID!` becomes optional here. FrontAccounting's own fields are removed.
 */
class SalesOrderLineUpdateInput extends SalesOrderLineUpdateInputBase
{
    /** Set by FrontAccounting, never by a client. */
    public const SERVER_SET = ['orderId', 'transType', 'qtyDelivered', 'qtyInvoiced'];

    protected function fields(): array
    {
        $fields = [];
        foreach (parent::fields() as $field) {
            if (in_array($field['name'], self::SERVER_SET, true)) {
                continue;
            }
            if ($field['name'] === 'id') {
                $field['type'] = Type::id();
                $field['description'] = 'An existing line of this order; omit it for a new line.';
            }
            $fields[] = $field;
        }

        return $fields;
    }
}
