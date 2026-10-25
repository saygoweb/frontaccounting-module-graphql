<?php

namespace FA\GraphQL\Type\Price;

use Anorm\GraphQL\Builder\FieldBuilder;
use FA\GraphQL\Type\Price\Base\PriceTypeBase;
use DI\Container;
use FA\GraphQL\Error\NotFound;
use FA\GraphQL\Fa\Service\PriceService;
use FA\GraphQL\Fa\Service\ServiceCall;
use GraphQL\Type\Definition\Type;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 *
 * Read through priceList; written by the hand-written priceSet and priceDelete
 * (ApiSchema), never by a generated mutation.
 *
 * stockId is a String: the generator types a name ending in "Id" as ID, but an item
 * code is text, and StockItemType's id is the same code.
 */
class PriceType extends PriceTypeBase
{
    protected function fields(): array
    {
        return array_map(function (array $field): array {
            return $field['name'] === 'stockId'
                ? FieldBuilder::create('stockId', Type::string())->build()
                : $field;
        }, parent::fields());
    }

    /**
     * Reading prices and writing them are one area, SA_SALESPRICE (inventory/prices.php).
     *
     * @return array<string, string>
     */
    protected function areas(): array
    {
        return [
            'list' => 'SA_SALESPRICE',
            'create' => 'SA_SALESPRICE',
            'edit' => 'SA_SALESPRICE',
            'delete' => 'SA_SALESPRICE',
        ];
    }

    /**
     * @param mixed $root
     * @param array<string, mixed> $args
     * @return array<int, array<string, mixed>>
     */
    public function resolveSet($root, $args, Container $context): array
    {
        $this->authorize(self::VERB_CREATE, null, $context);
        $prices = $context->get(PriceService::class);
        $ids = ServiceCall::each($args['input'], function (array $input) use ($prices): int {
            return $prices->set($input);
        });

        return $this->rowsById($context, $ids, self::VERB_EDIT);
    }

    /**
     * Each row is read before it is deleted, inside the batch's transaction.
     *
     * @param mixed $root
     * @param array<string, mixed> $args
     * @return array<int, array<string, mixed>>
     */
    public function resolveRemove($root, $args, Container $context): array
    {
        $this->authorize(self::VERB_DELETE, null, $context);
        $prices = $context->get(PriceService::class);
        $rows = [];
        ServiceCall::each($args['input'], function (array $input) use ($prices, $context, &$rows): void {
            $id = $prices->find($input);
            try {
                $rows[] = $this->rowsById($context, [$id], self::VERB_DELETE)[0];
            } catch (NotFound $e) {
                // The read-back's index is its own 0, not the batch's: let each() name the item.
                throw new NotFound($e->getMessage());
            }
            $prices->removeById($id);
        });

        return $rows;
    }
}
