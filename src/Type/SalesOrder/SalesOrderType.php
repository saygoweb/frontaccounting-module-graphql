<?php

namespace FA\GraphQL\Type\SalesOrder;

use Anorm\DataMapper;
use Anorm\GraphQL\Builder\FieldBuilder;
use Anorm\GraphQL\Mapper;
use DI\Container;
use FA\GraphQL\Fa\Service\RecurringSchedule;
use FA\GraphQL\Fa\Service\SalesOrderService;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Fa\Warnings;
use FA\GraphQL\Model\SalesOrderLineModel;
use FA\GraphQL\Type\SalesOrder\Base\SalesOrderTypeBase;
use FA\GraphQL\Type\SalesOrderLine\SalesOrderLineType;
use GraphQL\Type\Definition\Type;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 *
 * Read like any generated Type; written only through FrontAccounting's Cart
 * (SalesOrderService), never by ModelType's own write (Release 2 spec section 2).
 * sales_orders also holds quotations; scope() keeps the API to trans_type 30.
 */
class SalesOrderType extends SalesOrderTypeBase
{
    private SalesOrderLineType $lineType;

    private RecurrenceType $recurrenceType;

    public function __construct(SalesOrderLineType $lineType, RecurrenceType $recurrenceType)
    {
        // Before parent::__construct(), which calls fields().
        $this->lineType = $lineType;
        $this->recurrenceType = $recurrenceType;
        parent::__construct();
    }

    /**
     * @return array<string, string>
     */
    protected function areas(): array
    {
        return [
            self::VERB_LIST => 'SA_SALESTRANSVIEW',
            self::VERB_CREATE => 'SA_SALESORDER',
            self::VERB_EDIT => 'SA_SALESORDER',
            self::VERB_DELETE => 'SA_SALESORDER',
        ];
    }

    protected function scope(): array
    {
        return ['transType' => SalesOrderService::TRANS_TYPE];
    }

    protected function fields(): array
    {
        return array_merge(parent::fields(), [
            FieldBuilder::create('lines', Type::nonNull(Type::listOf(Type::nonNull($this->lineType))))
                ->setDescription('The order\'s lines, in entry order.')
                ->setResolver(function (array $row, $args, $context): array {
                    // A snapshot taken before a delete (Task 8) is returned as it was.
                    return $row['lines'] ?? self::linesOf((int) $row['id'], $context);
                })
                ->build(),
            FieldBuilder::create('recurring', $this->recurrenceType)
                ->setDescription('The recurring schedule, when sgw_sales is active and the order has one.')
                ->setResolver(function (array $row, $args, $context): ?array {
                    return array_key_exists('recurring', $row)
                        ? $row['recurring']
                        : $context->get(RecurringSchedule::class)->read((int) $row['id']);
                })
                ->build(),
        ]);
    }

    /**
     * Each order through SalesOrderService, the batch in one FrontAccounting
     * transaction, read back once it has committed — authorised by the create area,
     * not the list area (spec section 2.1).
     */
    public function resolveCreate($root, $args, Container $context): array
    {
        $this->authorize(self::VERB_CREATE, null, $context);
        $service = $context->get(SalesOrderService::class);
        $ids = ServiceCall::each($args['input'], function (array $input) use ($service): int {
            return $service->create($input);
        });

        return $this->rowsById($context, $ids, self::VERB_CREATE);
    }

    public function resolveUpdate($root, $args, Container $context): array
    {
        $this->authorize(self::VERB_EDIT, null, $context);
        $service = $context->get(SalesOrderService::class);
        $ids = ServiceCall::each($args['input'], function (array $input) use ($service): int {
            $input['id'] = self::intId($input['id'] ?? null);
            $service->update($input);

            return $input['id'];
        });

        return $this->rowsById($context, $ids, self::VERB_EDIT);
    }

    /**
     * FrontAccounting's cancel (Release 2 spec section 4.4): returns each order as it
     * was, lines included. An order with deliveries is closed rather than deleted; the
     * response's extensions.warnings says so.
     */
    public function resolveDelete($root, $args, Container $context): array
    {
        $this->authorize(self::VERB_DELETE, null, $context);
        $ids = self::intIds($args['id']);
        $before = [];
        foreach ($this->rowsById($context, $ids, self::VERB_DELETE) as $index => $row) {
            $row['lines'] = self::linesOf($ids[$index], $context);
            $row['recurring'] = $context->get(RecurringSchedule::class)->read($ids[$index]);
            $before[] = $row;
        }

        $service = $context->get(SalesOrderService::class);
        $outcomes = ServiceCall::each($ids, function (int $id) use ($service): string {
            return $service->delete($id);
        });
        foreach ($outcomes as $index => $outcome) {
            if ($outcome === SalesOrderService::CLOSED) {
                Warnings::add("Sales order {$ids[$index]} has deliveries: its undelivered part was cancelled "
                    . '(the order is closed), not deleted.');
            }
        }

        return $before;
    }

    /**
     * An order's lines, in entry order. Read directly, not through
     * SalesOrderLineType::resolveList: the order's own read already passed its area,
     * and a role that may create orders gets its created order's lines back.
     *
     * @param mixed $context the container
     * @return array<int, array<string, mixed>>
     */
    public static function linesOf(int $orderId, $context): array
    {
        $rows = [];
        $lines = DataMapper::find(SalesOrderLineModel::class, $context->get(\PDO::class))
            ->where(
                'order_no = :id AND trans_type = :type',
                [':id' => $orderId, ':type' => SalesOrderService::TRANS_TYPE]
            )
            ->orderBy('id')
            ->some();
        foreach ($lines as $line) {
            $rows[] = Mapper::toArray($line);
        }

        return $rows;
    }
}
