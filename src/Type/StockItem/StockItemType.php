<?php

namespace FA\GraphQL\Type\StockItem;

use DI\Container;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Fa\Service\StockItemService;
use FA\GraphQL\Type\StockItem\Base\StockItemTypeBase;

/**
 * Yours to edit: anorm-graphql wrote this file once and never again.
 *
 * Items are written through FrontAccounting (Items and prices spec §4.1): the
 * generated create and update are routed to StockItemService, each batch in one
 * ServiceCall::each — one FrontAccounting transaction — and the rows read back once
 * it has committed. The key is the item code, a string. There is no delete:
 * FaModelType refuses any write this class does not route.
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

    public function resolveCreate($root, $args, Container $context): array
    {
        $this->authorize(self::VERB_CREATE, null, $context);
        $items = $context->get(StockItemService::class);
        $codes = ServiceCall::each($args['input'], function (array $input) use ($items): string {
            return $items->create($input);
        });

        return $this->rowsById($context, $codes, self::VERB_CREATE);
    }

    public function resolveUpdate($root, $args, Container $context): array
    {
        $this->authorize(self::VERB_EDIT, null, $context);
        $items = $context->get(StockItemService::class);
        $codes = ServiceCall::each($args['input'], function (array $input) use ($items): string {
            $input['id'] = (string) ($input['id'] ?? '');

            // stock_master's spelling: the given id matched by collation.
            return $items->update($input);
        });

        return $this->rowsById($context, $codes, self::VERB_EDIT);
    }
}
