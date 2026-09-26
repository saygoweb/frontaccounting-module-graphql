<?php

namespace FA\GraphQL\Type\CustomerPayment;

use Anorm\DataMapper;
use Anorm\GraphQL\Builder\FieldBuilder;
use Anorm\GraphQL\Mapper;
use DI\Container;
use FA\GraphQL\Fa\CompanyContext;
use FA\GraphQL\Fa\DocumentLock;
use FA\GraphQL\Fa\Service\CustomerPaymentService;
use FA\GraphQL\Fa\Service\FaIncludes;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Fa\Service\Voider;
use FA\GraphQL\Model\AllocationModel;
use FA\GraphQL\Type\Allocation\AllocationType;
use FA\GraphQL\Type\CustomerPayment\Base\CustomerPaymentTypeBase;
use GraphQL\Type\Definition\Type;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 *
 * Customer payments: debtor_trans of type 12 (scope()), written only through
 * FrontAccounting (CustomerPaymentService). The bank side is read from bank_trans
 * and gl_trans; allocations from cust_allocations (Release 3 spec section 5).
 * Delete voids. Every write is one ServiceCall under the document lock (spec
 * section 2.1), read back once it has committed, authorised by the write's area.
 */
class CustomerPaymentType extends CustomerPaymentTypeBase
{
    private AllocationType $allocationType;

    public function __construct(AllocationType $allocationType)
    {
        $this->allocationType = $allocationType;
        parent::__construct();
    }

    /**
     * @return array<string, string>
     */
    protected function areas(): array
    {
        return [
            self::VERB_LIST => 'SA_SALESTRANSVIEW',
            self::VERB_CREATE => 'SA_SALESPAYMNT',
            self::VERB_EDIT => 'SA_SALESALLOC',
            self::VERB_DELETE => 'SA_VOIDTRANSACTION',
        ];
    }

    protected function scope(): array
    {
        return ['transType' => CustomerPaymentService::TRANS_TYPE];
    }

    protected function fields(): array
    {
        return array_merge(parent::fields(), [
            FieldBuilder::create('allocations', Type::nonNull(Type::listOf(Type::nonNull($this->allocationType))))
                ->setDescription('What this payment is allocated to.')
                ->setResolver(function (array $row, $args, $context): array {
                    return $row['allocations'] ?? self::allocationsOf((int) $row['id'], $context);
                })
                ->build(),
            FieldBuilder::create('unallocated', Type::nonNull(Type::float()))
                ->setDescription('Amount plus discount, less what is allocated.')
                ->setResolver(function (array $row): float {
                    return round((float) $row['amount'] + (float) $row['discount'] - (float) $row['allocated'], 2);
                })
                ->build(),
            FieldBuilder::create('bankAccountId', Type::id())
                ->setResolver(function (array $row, $args, $context): ?string {
                    $bank = self::bankOf((int) $row['id'], $context);

                    return $bank === null ? null : (string) $bank['bank_act'];
                })
                ->build(),
            FieldBuilder::create('bankAmount', Type::float())
                ->setDescription('In the bank account\'s currency, before the charge.')
                ->setResolver(function (array $row, $args, $context): ?float {
                    $bank = self::bankOf((int) $row['id'], $context);

                    return $bank === null ? null : round((float) $bank['amount'] + self::chargeOf((int) $row['id']), 2);
                })
                ->build(),
            FieldBuilder::create('charge', Type::nonNull(Type::float()))
                ->setResolver(function (array $row): float {
                    return round(self::chargeOf((int) $row['id']), 2);
                })
                ->build(),
            FieldBuilder::create('memo', Type::string())
                ->setResolver(function (array $row, $args, $context): ?string {
                    $statement = $context->get(\PDO::class)->prepare(
                        'SELECT memo_ FROM ' . CompanyContext::prefix()
                        . 'comments WHERE type = ? AND id = ? ORDER BY date_'
                    );
                    $statement->execute([CustomerPaymentService::TRANS_TYPE, (int) $row['id']]);
                    $memo = $statement->fetchColumn();

                    return $memo === false ? null : (string) $memo;
                })
                ->build(),
            FieldBuilder::create('voided', Type::nonNull(Type::boolean()))
                ->setDescription('Voided: the row stays, its amounts zeroed.')
                ->setResolver(function (array $row, $args, $context): bool {
                    return $row['voided'] ?? $context->get(Voider::class)
                        ->isVoided(CustomerPaymentService::TRANS_TYPE, (int) $row['id']);
                })
                ->build(),
        ]);
    }

    public function resolveCreate($root, $args, Container $context): array
    {
        $this->authorize(self::VERB_CREATE, null, $context);
        $service = $context->get(CustomerPaymentService::class);
        $ids = DocumentLock::run(function () use ($args, $service): array {
            return ServiceCall::each($args['input'], function (array $input) use ($service): int {
                return $service->create($input);
            });
        });

        return $this->rowsById($context, $ids, self::VERB_CREATE);
    }

    public function resolveUpdate($root, $args, Container $context): array
    {
        $this->authorize(self::VERB_EDIT, null, $context);
        $service = $context->get(CustomerPaymentService::class);
        $ids = DocumentLock::run(function () use ($args, $service): array {
            return ServiceCall::each($args['input'], function (array $input) use ($service): int {
                $input['id'] = self::intId($input['id'] ?? null);
                $service->update($input);

                return $input['id'];
            });
        });

        return $this->rowsById($context, $ids, self::VERB_EDIT);
    }

    /**
     * Voids (spec section 5): returns each payment as it was, allocations included —
     * read under the document lock, so no API reallocation can land between the read
     * and the void (Checkpoint C M-5).
     */
    public function resolveDelete($root, $args, Container $context): array
    {
        $this->authorize(self::VERB_DELETE, null, $context);
        $ids = self::intIds($args['id']);
        $service = $context->get(CustomerPaymentService::class);

        return DocumentLock::run(function () use ($ids, $service, $context): array {
            $before = [];
            foreach ($this->rowsById($context, $ids, self::VERB_DELETE) as $index => $row) {
                $row['allocations'] = self::allocationsOf($ids[$index], $context);
                $row['voided'] = $context->get(Voider::class)
                    ->isVoided(CustomerPaymentService::TRANS_TYPE, $ids[$index]);
                $before[] = $row;
            }
            ServiceCall::each($ids, function (int $id) use ($service): void {
                $service->delete($id);
            });

            return $before;
        });
    }

    /**
     * Read directly, as SalesOrderType::linesOf(): the payment's own read already
     * passed its area.
     *
     * @param mixed $context the container
     * @return array<int, array<string, mixed>>
     */
    public static function allocationsOf(int $paymentNo, $context): array
    {
        $rows = [];
        $allocations = DataMapper::find(AllocationModel::class, $context->get(\PDO::class))
            ->where(
                'trans_type_from = :type AND trans_no_from = :id',
                [':type' => CustomerPaymentService::TRANS_TYPE, ':id' => $paymentNo]
            )
            ->orderBy('id')
            ->some();
        foreach ($allocations as $allocation) {
            $rows[] = Mapper::toArray($allocation);
        }

        return $rows;
    }

    /**
     * @param mixed $context
     * @return array{bank_act: string, amount: string}|null
     */
    private static function bankOf(int $paymentNo, $context): ?array
    {
        $statement = $context->get(\PDO::class)->prepare(
            'SELECT bank_act, amount FROM ' . CompanyContext::prefix()
            . 'bank_trans WHERE type = ? AND trans_no = ? ORDER BY id LIMIT 1'
        );
        $statement->execute([CustomerPaymentService::TRANS_TYPE, $paymentNo]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * FrontAccounting does not store the charge; get_cust_bank_charge()
     * (payment_db.inc:142) recovers it from the GL.
     */
    private static function chargeOf(int $paymentNo): float
    {
        FaIncludes::billing();

        return (float) get_cust_bank_charge(ST_CUSTPAYMENT, $paymentNo);
    }
}
