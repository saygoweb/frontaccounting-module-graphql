<?php

namespace FA\GraphQL\Type\Allocation;

use FA\GraphQL\Type\Allocation\Base\AllocationTypeBase;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 *
 * Read-only: allocations are written by FrontAccounting's allocation cart, through
 * customerPaymentCreate and customerPaymentUpdate (Release 3 spec section 5).
 */
class AllocationType extends AllocationTypeBase
{
    /**
     * @return array<string, string>
     */
    protected function areas(): array
    {
        return [self::VERB_LIST => 'SA_SALESTRANSVIEW'];
    }
}
