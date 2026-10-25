<?php

namespace FA\GraphQL\Type\SalesType;

use Anorm\GraphQL\Builder\FieldBuilder;
use DI\Container;
use FA\GraphQL\Fa\Service\SalesTypeService;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Type\SalesType\Base\SalesTypeTypeBase;
use GraphQL\Type\Definition\Type;

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
 *
 * Price lists are written through FrontAccounting (spec §2): the generated create and
 * update are routed to SalesTypeService, each batch in one ServiceCall::each — one
 * FrontAccounting transaction — and the rows read back once it has committed.
 * FaModelType refuses any write this class does not route.
 */
class SalesTypeType extends SalesTypeTypeBase
{
    protected function fields(): array
    {
        return array_merge(parent::fields(), [
            FieldBuilder::create('isBase', Type::nonNull(Type::boolean()))
                ->setDescription(
                    "The company's base price list (base_sales): "
                    . 'other lists fall back to its prices times their factor.'
                )
                ->setResolver(function (array $row): bool {
                    return (int) $row['id'] === (int) get_base_sales_type();
                })
                ->build(),
        ]);
    }

    public function resolveCreate($root, $args, Container $context): array
    {
        $this->authorize(self::VERB_CREATE, null, $context);
        $lists = $context->get(SalesTypeService::class);
        $ids = ServiceCall::each($args['input'], function (array $input) use ($lists): int {
            return $lists->create($input);
        });

        return $this->rowsById($context, $ids, self::VERB_CREATE);
    }

    public function resolveUpdate($root, $args, Container $context): array
    {
        $this->authorize(self::VERB_EDIT, null, $context);
        $lists = $context->get(SalesTypeService::class);
        $ids = ServiceCall::each($args['input'], function (array $input) use ($lists): int {
            $input['id'] = self::intId($input['id'] ?? null);
            $lists->update($input);

            return $input['id'];
        });

        return $this->rowsById($context, $ids, self::VERB_EDIT);
    }

    /**
     * Listing keeps the area of the work the lookup serves — taking orders — not
     * FrontAccounting's setup area for this table (Release 2 spec section 4.2); the
     * writes take that setup area, SA_SALESTYPES, as the price-list page does. There
     * is no delete.
     *
     * @return array<string, string>
     */
    protected function areas(): array
    {
        return ['list' => 'SA_SALESORDER', 'create' => 'SA_SALESTYPES', 'edit' => 'SA_SALESTYPES'];
    }
}
