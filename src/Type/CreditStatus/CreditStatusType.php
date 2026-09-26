<?php

namespace FA\GraphQL\Type\CreditStatus;

use FA\GraphQL\Type\CreditStatus\Base\CreditStatusTypeBase;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 *
 * Override points, all inherited from Anorm\GraphQL\ModelType:
 *  - authorize($verb, $model, $context)   throw to refuse 'list', 'create', 'edit' or 'delete'
 *  - beforeWrite($model, $input, $isUpdate, $context)   stamp columns before a write
 *  - newModel($context)   construct the model some other way
 *  - resolveList / resolveCreate / resolveUpdate / resolveDelete   replace a resolver outright
 *  - fields()   add computed fields: array_merge(parent::fields(), [...])
 */
class CreditStatusType extends CreditStatusTypeBase
{
    /**
     * Read-only: listing is the only verb, and it needs the area of the work the
     * lookup serves — taking orders — not FrontAccounting's setup area for this table,
     * which grants editing it in the web UI (Release 2 spec section 4.2).
     *
     * @return array<string, string>
     */
    protected function areas(): array
    {
        return ['list' => 'SA_SALESORDER'];
    }
}
