<?php

namespace FA\GraphQL\Type\InvoiceLine;

use FA\GraphQL\Fa\Service\InvoiceService;
use FA\GraphQL\Type\InvoiceLine\Base\InvoiceLineTypeBase;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 *
 * Read-only (bin/generate: READONLY): a line is written with its invoice.
 * debtor_trans_details also holds deliveries' and credit notes' lines; scope() keeps
 * the API to invoices'.
 */
class InvoiceLineType extends InvoiceLineTypeBase
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
        return ['transType' => InvoiceService::TRANS_TYPE];
    }
}
