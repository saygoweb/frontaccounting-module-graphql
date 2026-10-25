<?php

namespace FA\GraphQL\Type\StockItem;

use FA\GraphQL\Type\StockItem\Base\StockItemTypeBase;

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
class StockItemType extends StockItemTypeBase
{
    /**
     * Listing keeps the area of the work the lookup serves — taking orders — not
     * FrontAccounting's setup area for this table (Release 2 spec section 4.2); the
     * writes take that setup area, SA_ITEM, as the item page does. There is no delete.
     *
     * @return array<string, string>
     */
    protected function areas(): array
    {
        return ['list' => 'SA_SALESORDER', 'create' => 'SA_ITEM', 'edit' => 'SA_ITEM'];
    }
}
