<?php

namespace FA\GraphQL\Type\Branch;

use DI\Container;
use FA\GraphQL\Fa\Service\BranchService;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Type\Branch\Base\BranchTypeBase;

/**
 * Yours to edit: anorm-graphql wrote this file once and never again.
 *
 * Branches are written through FrontAccounting (spec §2): the generated create,
 * update and delete go to BranchService, each batch in one ServiceCall::each.
 */
class BranchType extends BranchTypeBase
{
    /**
     * @return array<string, string>
     */
    protected function areas(): array
    {
        return [
            self::VERB_LIST => 'SA_CUSTOMER',
            self::VERB_CREATE => 'SA_CUSTOMER',
            self::VERB_EDIT => 'SA_CUSTOMER',
            self::VERB_DELETE => 'SA_CUSTOMER',
        ];
    }

    public function resolveCreate($root, $args, Container $context): array
    {
        $this->authorize(self::VERB_CREATE, null, $context);
        $branches = $context->get(BranchService::class);
        $ids = ServiceCall::each($args['input'], function (array $input) use ($branches): int {
            return $branches->create($input);
        });

        return $this->rowsById($context, $ids);
    }

    public function resolveUpdate($root, $args, Container $context): array
    {
        $this->authorize(self::VERB_EDIT, null, $context);
        $branches = $context->get(BranchService::class);
        $ids = ServiceCall::each($args['input'], function (array $input) use ($branches): int {
            $input['id'] = self::intId($input['id'] ?? null);
            $branches->update($input);

            return $input['id'];
        });

        return $this->rowsById($context, $ids);
    }

    public function resolveDelete($root, $args, Container $context): array
    {
        $this->authorize(self::VERB_DELETE, null, $context);
        $ids = self::intIds($args['id']);
        $rows = $this->rowsById($context, $ids);
        $branches = $context->get(BranchService::class);
        ServiceCall::each($ids, function (int $id) use ($branches): void {
            $branches->delete($id);
        });

        return $rows;
    }
}
