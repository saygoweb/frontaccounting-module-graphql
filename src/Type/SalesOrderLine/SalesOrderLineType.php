<?php

namespace FA\GraphQL\Type\SalesOrderLine;

use FA\GraphQL\Fa\Service\SalesOrderService;
use FA\GraphQL\Type\SalesOrderLine\Base\SalesOrderLineTypeBase;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 *
 * Read-only (bin/generate: READONLY): a line is written with its order, through
 * salesOrderCreate/salesOrderUpdate. sales_order_details also holds quotation
 * lines; scope() keeps the API to sales orders'.
 */
class SalesOrderLineType extends SalesOrderLineTypeBase
{
    /**
     * @return array<string, string>
     */
    protected function areas(): array
    {
        return [self::VERB_LIST => 'SA_SALESTRANSVIEW'];
    }

    protected function scope(): array
    {
        return ['transType' => SalesOrderService::TRANS_TYPE];
    }
}
