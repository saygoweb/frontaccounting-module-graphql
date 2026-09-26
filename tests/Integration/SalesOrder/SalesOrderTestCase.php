<?php

namespace FA\GraphQL\Tests\Integration\SalesOrder;

use DI\Container;
use FA\GraphQL\Auth\Claims;
use FA\GraphQL\Config;
use FA\GraphQL\Fa\Bootstrap;
use FA\GraphQL\Fa\FaMessages;
use FA\GraphQL\Fa\Service\FaIncludes;
use FA\GraphQL\Fa\Service\SalesOrderService;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Fa\Warnings;
use FA\GraphQL\RequestInfo;
use FA\GraphQL\SessionGate;
use FA\GraphQL\Tests\Integration\FaTestCase;

/**
 * Sales-order tests against FrontAccounting in-process, signed in as apitest the way
 * a bearer token would be. What they write, FrontAccounting commits on its own mysqli
 * connection: nothing rolls it back, so every order a test makes is tracked and
 * purged — with its deliveries — in tearDown.
 *
 * Every subclass must carry
 *
 *     @runTestsInSeparateProcesses
 *     @preserveGlobalState disabled
 */
abstract class SalesOrderTestCase extends FaTestCase
{
    protected Container $container;

    /** @var int[] */
    private array $orders = [];

    protected function setUp(): void
    {
        parent::setUp();

        $config = Config::fromArray([
            'secret' => '0123456789abcdef0123456789abcdef',
            'fa_root' => Bootstrap::defaultRoot(),
        ]);
        $factory = require dirname(__DIR__, 3) . '/container.php';
        $this->container = $factory($config, new RequestInfo(false, 'phpunit 127.0.0.1'));

        $gate = $this->container->get(SessionGate::class);
        $gate->boot();
        $gate->enter(new Claims(0, 'apitest', 'sales-order-test', new \DateTimeImmutable('+5 minutes')));

        FaIncludes::orders();
        FaMessages::reset();
        Warnings::reset();
    }

    protected function tearDown(): void
    {
        foreach ($this->orders as $orderNo) {
            $this->purgeOrder($orderNo);
        }
        $this->orders = [];
        parent::tearDown();
    }

    protected function service(): SalesOrderService
    {
        return $this->container->get(SalesOrderService::class);
    }

    protected function today(): string
    {
        return date('Y-m-d');
    }

    /**
     * A valid order for demo customer 1 / branch 1: payment terms 3 (10 days, credit
     * terms, so the delivery checks apply — the customer's own terms, 4, are cash-only),
     * dated today (Task 4 made a fiscal year cover it).
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function orderInput(array $overrides = []): array
    {
        return array_merge([
            'customerId' => 1,
            'branchId' => 1,
            'orderDate' => new \DateTimeImmutable($this->today()),
            'paymentTermsId' => 3,
            'deliverTo' => 'Donald Easter',
            'deliveryAddress' => '1 Test Street',
            'lines' => [['stockId' => '101', 'quantity' => 2.0]],
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function createOrder(array $overrides = []): int
    {
        $input = $this->orderInput($overrides);
        $orderNo = ServiceCall::run(function () use ($input): int {
            return $this->service()->create($input);
        });
        $this->track($orderNo);

        return $orderNo;
    }

    protected function track(int $orderNo): void
    {
        $this->orders[] = $orderNo;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function orderRow(int $orderNo): ?array
    {
        $statement = $this->pdo()->prepare('SELECT * FROM 0_sales_orders WHERE order_no = ? AND trans_type = 30');
        $statement->execute([$orderNo]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function lineRows(int $orderNo): array
    {
        $statement = $this->pdo()->prepare(
            'SELECT * FROM 0_sales_order_details WHERE order_no = ? AND trans_type = 30 ORDER BY id'
        );
        $statement->execute([$orderNo]);

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * @return int[]
     */
    protected function lineIds(int $orderNo): array
    {
        return array_map('intval', array_column($this->lineRows($orderNo), 'id'));
    }

    /**
     * Deliver part of an order with FrontAccounting's own functions, as sgw_sales'
     * RecurringInvoiceService::generateInvoice() does (modules/sgw_sales
     * includes/service/RecurringInvoiceService.php): the order's Cart made a child
     * (a delivery), quantities set, reference 'auto'. Dated today, explicitly: upstream's
     * prepare_child() takes new_doc_date(), the fork's Today().
     *
     * @param array<int, float> $qtyByLineId sales_order_details.id => quantity to deliver
     * @return int the delivery's trans_no
     */
    protected function deliver(int $orderNo, array $qtyByLineId): int
    {
        return ServiceCall::run(function () use ($orderNo, $qtyByLineId): int {
            $delivery = new \Cart(ST_SALESORDER, [$orderNo], true);
            $delivery->reference = 'auto';
            $delivery->document_date = \Today();
            $delivery->due_date = \Today();
            foreach ($delivery->line_items as $line) {
                $line->qty_done = 0;
                $line->qty_dispatched = (float) ($qtyByLineId[(int) $line->src_id] ?? 0);
            }

            return (int) $delivery->write(1);
        });
    }

    /**
     * Remove an order and everything FrontAccounting wrote for it and its deliveries.
     * The tables are those add_sales_order(), write_sales_delivery() and sgw_sales
     * write (sales/includes/db/sales_order_db.inc, sales_delivery_db.inc).
     */
    protected function purgeOrder(int $orderNo): void
    {
        $pdo = $this->pdo();
        $deliveries = $pdo->prepare('SELECT trans_no FROM 0_debtor_trans WHERE type = 13 AND order_ = ?');
        $deliveries->execute([$orderNo]);
        foreach ($deliveries->fetchAll(\PDO::FETCH_COLUMN) as $dn) {
            foreach (
                [
                'DELETE FROM 0_debtor_trans_details WHERE debtor_trans_type = 13 AND debtor_trans_no = ?',
                'DELETE FROM 0_stock_moves WHERE type = 13 AND trans_no = ?',
                'DELETE FROM 0_gl_trans WHERE type = 13 AND type_no = ?',
                'DELETE FROM 0_trans_tax_details WHERE trans_type = 13 AND trans_no = ?',
                'DELETE FROM 0_audit_trail WHERE type = 13 AND trans_no = ?',
                'DELETE FROM 0_refs WHERE type = 13 AND id = ?',
                'DELETE FROM 0_comments WHERE type = 13 AND id = ?',
                'DELETE FROM 0_debtor_trans WHERE type = 13 AND trans_no = ?',
                ] as $sql
            ) {
                $pdo->prepare($sql)->execute([$dn]);
            }
        }
        foreach (
            [
            'DELETE FROM 0_sales_order_details WHERE trans_type = 30 AND order_no = ?',
            'DELETE FROM 0_sales_orders WHERE trans_type = 30 AND order_no = ?',
            'DELETE FROM 0_audit_trail WHERE type = 30 AND trans_no = ?',
            'DELETE FROM 0_refs WHERE type = 30 AND id = ?',
            'DELETE FROM 0_comments WHERE type = 30 AND id = ?',
            'DELETE FROM 0_cust_allocations WHERE trans_type_to = 30 AND trans_no_to = ?',
            ] as $sql
        ) {
            $pdo->prepare($sql)->execute([$orderNo]);
        }
        if ($pdo->query("SHOW TABLES LIKE '0_sales_recurring'")->fetch() !== false) {
            $pdo->prepare('DELETE FROM 0_sales_recurring WHERE trans_no = ?')->execute([$orderNo]);
        }
    }
}
