<?php

namespace FA\GraphQL\Type\Customer;

use Anorm\GraphQL\Builder\FieldBuilder;
use DI\Container;
use FA\GraphQL\Fa\Service\CustomerService;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Type\Branch\BranchType;
use FA\GraphQL\Type\Contact\ContactLinks;
use FA\GraphQL\Type\Contact\ContactType;
use FA\GraphQL\Type\Customer\Base\CustomerTypeBase;
use GraphQL\Type\Definition\Type;

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
    private BranchType $branchType;

    private ContactType $contactType;

    public function __construct(BranchType $branchType, ContactType $contactType)
    {
        // Set before the parent constructor: it calls fields(). The container hands
        // out one instance of each Type, the same ones ApiSchema lists.
        $this->branchType = $branchType;
        $this->contactType = $contactType;
        parent::__construct();
    }

    protected function fields(): array
    {
        return array_merge(parent::fields(), [
            FieldBuilder::create('branches', Type::nonNull(Type::listOf(Type::nonNull($this->branchType))))
                ->setDescription("The customer's branches.")
                ->setResolver(function (array $row, $args, Container $context): array {
                    return $this->branchType->resolveList(
                        null,
                        ['query' => ['selector' => json_encode(['customerId' => (int) $row['id']])]],
                        $context
                    );
                })
                ->build(),
            FieldBuilder::create('contacts', Type::nonNull(Type::listOf(Type::nonNull($this->contactType))))
                ->setDescription('The contacts linked to the customer itself (a branch has its own).')
                ->setResolver(function (array $row, $args, Container $context): array {
                    $ids = ContactLinks::personIds($context->get(\PDO::class), 'customer', (int) $row['id']);

                    return $ids === [] ? [] : $this->contactType->resolveList(
                        null,
                        ['query' => ['selector' => json_encode(['id' => ['$in' => $ids]])]],
                        $context
                    );
                })
                ->build(),
        ]);
    }

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

        return $this->rowsById($context, $ids, self::VERB_CREATE);
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

        return $this->rowsById($context, $ids, self::VERB_EDIT);
    }

    public function resolveDelete($root, $args, Container $context): array
    {
        $this->authorize(self::VERB_DELETE, null, $context);
        $ids = self::intIds($args['id']);
        $rows = $this->rowsById($context, $ids, self::VERB_DELETE);
        $customers = $context->get(CustomerService::class);
        ServiceCall::each($ids, function (int $id) use ($customers): void {
            $customers->delete($id);
        });

        return $rows;
    }
}
