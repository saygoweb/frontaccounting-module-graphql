<?php

namespace FA\GraphQL\Type\SalesType;

use FA\GraphQL\Type\SalesType\Base\SalesTypeTypeBase;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 *
 * Override points, all inherited from Anorm\GraphQL\ModelType:
 *  - authorize($verb, $model, $context)   throw to refuse 'list', 'create', 'edit' or 'delete'
 *  - beforeWrite($model, $input, $isUpdate, $context)   stamp columns before a write
 *  - newModel($context)   construct the model some other way
 *  - resolveList / resolveUpsert / resolveDelete   replace a resolver outright
 *  - fields()   add computed fields: array_merge(parent::fields(), [...])
 *
 * authorize() is FaModelType's: it checks areas() through Guard.
 */
class SalesTypeType extends SalesTypeTypeBase
{
    /**
     * Read-only: listing is the only verb. Anything else is Forbidden by FaModelType
     * even if a mutation were ever wired to it.
     *
     * @return array<string, string>
     */
    protected function areas(): array
    {
        return ['list' => 'SA_SALESTYPES'];
    }
}
