<?php

namespace FA\GraphQL\Type\DeliveryLine;

use FA\GraphQL\Fa\Service\DeliveryService;
use FA\GraphQL\Type\DeliveryLine\Base\DeliveryLineTypeBase;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 *
 * Read-only (bin/generate: READONLY): a line is written with its delivery, through
 * deliveryCreate. debtor_trans_details also holds invoice and credit lines; scope()
 * keeps the API to deliveries'.
 */
class DeliveryLineType extends DeliveryLineTypeBase
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
        return ['transType' => DeliveryService::TRANS_TYPE];
    }
}
