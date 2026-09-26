<?php

namespace FA\GraphQL\Type\Customer;

use DI\Container;
use FA\GraphQL\Fa\Service\CustomerService;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Type\Customer\Base\CustomerTypeBase;

/**
 * Yours to edit: anorm-graphql wrote this file once and never again.
 *
 * Customers are written through FrontAccounting (spec §2): the generated create,
 * update and delete are routed to CustomerService, each batch in one ServiceCall::each —
 * one FrontAccounting transaction — and the rows read back once it has committed.
 * FaModelType refuses any write this class does not route.
 */
class CustomerType extends CustomerTypeBase
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
        $customers = $context->get(CustomerService::class);
        $ids = ServiceCall::each($args['input'], function (array $input) use ($customers): int {
            return $customers->create($input);
        });

        return $this->rowsById($context, $ids);
    }

    public function resolveUpdate($root, $args, Container $context): array
    {
        $this->authorize(self::VERB_EDIT, null, $context);
        $customers = $context->get(CustomerService::class);
        $ids = ServiceCall::each($args['input'], function (array $input) use ($customers): int {
            $input['id'] = self::intId($input['id'] ?? null);
            $customers->update($input);

            return $input['id'];
        });

        return $this->rowsById($context, $ids);
    }

    public function resolveDelete($root, $args, Container $context): array
    {
        $this->authorize(self::VERB_DELETE, null, $context);
        $ids = self::intIds($args['id']);
        $rows = $this->rowsById($context, $ids);
        $customers = $context->get(CustomerService::class);
        ServiceCall::each($ids, function (int $id) use ($customers): void {
            $customers->delete($id);
        });

        return $rows;
    }
}
