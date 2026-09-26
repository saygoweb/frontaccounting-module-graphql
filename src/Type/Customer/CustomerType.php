<?php

namespace FA\GraphQL\Type\Customer;

use Anorm\GraphQL\Builder\FieldBuilder;
use DI\Container;
use FA\GraphQL\Auth\Guard;
use FA\GraphQL\Fa\Service\CustomerService;
use FA\GraphQL\Fa\Service\FaIncludes;
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

    private CustomerBalanceType $balanceType;

    public function __construct(BranchType $branchType, ContactType $contactType, CustomerBalanceType $balanceType)
    {
        // Set before the parent constructor: it calls fields(). The container hands
        // out one instance of each Type, the same ones ApiSchema lists.
        $this->branchType = $branchType;
        $this->contactType = $contactType;
        $this->balanceType = $balanceType;
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
            FieldBuilder::create('balance', $this->balanceType)
                ->setDescription('What the customer owes, and how overdue (needs SA_SALESTRANSVIEW).')
                ->setResolver(function (array $row): ?array {
                    return self::balanceOf((int) $row['id']);
                })
                ->build(),
        ]);
    }

    /**
     * FrontAccounting's customer balance and ageing, get_customer_details()
     * (sales/includes/db/customers_db.inc:68-122), as customer_inquiry.php reads it:
     * $all = false, so only what is not yet allocated counts and a settled invoice is
     * never "due". Financial figures: the customer's own area (SA_CUSTOMER) is not
     * enough; a role without the transactions view gets null and an error.
     *
     * @return array<string, float|string>|null
     */
    public static function balanceOf(int $customerId): ?array
    {
        Guard::require('SA_SALESTRANSVIEW');
        FaIncludes::customers();
        $details = get_customer_details($customerId, null, false);
        if (!$details) {
            // With $all = false its WHERE drops the LEFT JOIN's null row, so a customer
            // with nothing unallocated has no row: it owes nothing. With $all = true the
            // row is missing only when the customer's payment terms or credit status are
            // (inner joins): then there is no balance to give.
            $all = get_customer_details($customerId, null, true);
            if (!$all) {
                return null;
            }

            return ['balance' => 0.0, 'due' => 0.0, 'overdue1' => 0.0, 'overdue2' => 0.0,
                'currency' => (string) $all['curr_code']];
        }

        return [
            'balance' => round((float) $details['Balance'], 2),
            'due' => round((float) $details['Due'], 2),
            'overdue1' => round((float) $details['Overdue1'], 2),
            'overdue2' => round((float) $details['Overdue2'], 2),
            'currency' => (string) $details['curr_code'],
        ];
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
