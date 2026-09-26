<?php

namespace FA\GraphQL\Type\Invoice;

use Anorm\DataMapper;
use Anorm\GraphQL\Builder\FieldBuilder;
use Anorm\GraphQL\Mapper;
use DI\Container;
use FA\GraphQL\Fa\CompanyContext;
use FA\GraphQL\Fa\DocumentLock;
use FA\GraphQL\Fa\Service\DeliveryService;
use FA\GraphQL\Fa\Service\InvoiceService;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Fa\Service\Voider;
use FA\GraphQL\Model\InvoiceLineModel;
use FA\GraphQL\Type\Invoice\Base\InvoiceTypeBase;
use FA\GraphQL\Type\InvoiceLine\InvoiceLineType;
use GraphQL\Type\Definition\Type;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 *
 * Read like any generated Type; written only through FrontAccounting's Cart
 * (InvoiceService), never by ModelType's own write (Release 3 spec section 2).
 * debtor_trans holds every customer document; scope() keeps this to type 10.
 * No update (bin/generate: WITHOUT_UPDATE): a posted invoice is voided and entered
 * again. Delete voids.
 */
class InvoiceType extends InvoiceTypeBase
{
    private InvoiceLineType $lineType;

    public function __construct(InvoiceLineType $lineType)
    {
        // Before parent::__construct(), which calls fields().
        $this->lineType = $lineType;
        parent::__construct();
    }

    /**
     * @return array<string, string>
     */
    protected function areas(): array
    {
        return [
            self::VERB_LIST => 'SA_SALESTRANSVIEW',
            self::VERB_CREATE => 'SA_SALESINVOICE',
            self::VERB_DELETE => 'SA_VOIDTRANSACTION',
        ];
    }

    protected function scope(): array
    {
        return ['transType' => InvoiceService::TRANS_TYPE];
    }

    protected function fields(): array
    {
        return array_merge(parent::fields(), [
            FieldBuilder::create('lines', Type::nonNull(Type::listOf(Type::nonNull($this->lineType))))
                ->setDescription('The invoice\'s lines, in entry order.')
                ->setResolver(function (array $row, $args, $context): array {
                    return $row['lines'] ?? self::linesOf((int) $row['id'], $context);
                })
                ->build(),
            FieldBuilder::create('total', Type::nonNull(Type::float()))
                ->setDescription('Items, tax, freight and freight tax, less discount — FrontAccounting\'s Total.')
                ->setResolver(function (array $row): float {
                    return self::total($row);
                })
                ->build(),
            FieldBuilder::create('outstanding', Type::nonNull(Type::float()))
                ->setDescription('The total less what has been allocated to it.')
                ->setResolver(function (array $row): float {
                    return round(self::total($row) - (float) $row['allocated'], 2);
                })
                ->build(),
            FieldBuilder::create('deliveryIds', Type::nonNull(Type::listOf(Type::nonNull(Type::id()))))
                ->setDescription('The deliveries this invoice invoices.')
                ->setResolver(function (array $row, $args, $context): array {
                    return $row['deliveryIds'] ?? self::deliveriesOf((int) $row['id'], $context);
                })
                ->build(),
            FieldBuilder::create('voided', Type::nonNull(Type::boolean()))
                ->setDescription('Voided: the row stays, its amounts zeroed.')
                ->setResolver(function (array $row, $args, $context): bool {
                    return $row['voided'] ?? $context->get(Voider::class)
                        ->isVoided(InvoiceService::TRANS_TYPE, (int) $row['id']);
                })
                ->build(),
        ]);
    }

    /**
     * Each invoice through InvoiceService, the batch in one FrontAccounting
     * transaction under the document lock (spec section 2.1), read back once it has
     * committed — authorised by the create area, not the list area (Release 2 ruling).
     */
    public function resolveCreate($root, $args, Container $context): array
    {
        $this->authorize(self::VERB_CREATE, null, $context);
        $service = $context->get(InvoiceService::class);
        $ids = DocumentLock::run(function () use ($args, $service): array {
            return ServiceCall::each($args['input'], function (array $input) use ($service): int {
                return $service->create($input);
            });
        });

        return $this->rowsById($context, $ids, self::VERB_CREATE);
    }

    /**
     * Void. Returns each invoice as it was before, lines and deliveries included.
     */
    public function resolveDelete($root, $args, Container $context): array
    {
        $this->authorize(self::VERB_DELETE, null, $context);
        $ids = self::intIds($args['id']);
        $before = [];
        foreach ($this->rowsById($context, $ids, self::VERB_DELETE) as $index => $row) {
            $row['lines'] = self::linesOf($ids[$index], $context);
            $row['deliveryIds'] = self::deliveriesOf($ids[$index], $context);
            $row['voided'] = $context->get(Voider::class)->isVoided(InvoiceService::TRANS_TYPE, $ids[$index]);
            $before[] = $row;
        }

        $service = $context->get(InvoiceService::class);
        DocumentLock::run(function () use ($ids, $service): void {
            ServiceCall::each($ids, function (int $id) use ($service): void {
                $service->delete($id);
            });
        });

        return $before;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function total(array $row): float
    {
        if ((float) $row['prepaymentAmount'] > 0) {
            return (float) $row['prepaymentAmount'];
        }

        return round(
            (float) $row['amount'] + (float) $row['tax'] + (float) $row['freight'] + (float) $row['freightTax']
            + (float) $row['discount'],
            2
        );
    }

    /**
     * @param mixed $context the container
     * @return array<int, array<string, mixed>>
     */
    public static function linesOf(int $invoiceId, $context): array
    {
        $rows = [];
        $lines = DataMapper::find(InvoiceLineModel::class, $context->get(\PDO::class))
            ->where(
                'debtor_trans_no = :id AND debtor_trans_type = :type',
                [':id' => $invoiceId, ':type' => InvoiceService::TRANS_TYPE]
            )
            ->orderBy('id')
            ->some();
        foreach ($lines as $line) {
            $rows[] = Mapper::toArray($line);
        }

        return $rows;
    }

    /**
     * The deliveries whose lines this invoice's lines invoice (src_id).
     *
     * @param mixed $context the container
     * @return string[]
     */
    public static function deliveriesOf(int $invoiceId, $context): array
    {
        $prefix = CompanyContext::prefix();
        $statement = $context->get(\PDO::class)->prepare(
            "SELECT DISTINCT d.debtor_trans_no FROM {$prefix}debtor_trans_details i
             JOIN {$prefix}debtor_trans_details d ON d.id = i.src_id AND d.debtor_trans_type = ?
             WHERE i.debtor_trans_type = ? AND i.debtor_trans_no = ? ORDER BY d.debtor_trans_no"
        );
        $statement->execute([DeliveryService::TRANS_TYPE, InvoiceService::TRANS_TYPE, $invoiceId]);

        return array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }
}
