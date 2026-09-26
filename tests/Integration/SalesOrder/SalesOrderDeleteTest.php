<?php

namespace FA\GraphQL\Tests\Integration\SalesOrder;

use FA\GraphQL\Error\NotFound;
use FA\GraphQL\Fa\Service\SalesOrderService;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Fa\Warnings;
use FA\GraphQL\Type\SalesOrder\SalesOrderType;

/**
 * salesOrderDelete is FrontAccounting's cancel (handle_cancel_order(),
 * sales/sales_order_entry.php :634-648; Release 2 spec section 4.4).
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SalesOrderDeleteTest extends SalesOrderTestCase
{
    private function delete(int $orderNo): string
    {
        return ServiceCall::run(function () use ($orderNo): string {
            return $this->service()->delete($orderNo);
        });
    }

    public function testAnOrderWithNoDeliveriesIsDeleted(): void
    {
        $orderNo = $this->createOrder();

        $this->assertSame(SalesOrderService::DELETED, $this->delete($orderNo));
        $this->assertNull($this->orderRow($orderNo));
        $this->assertSame([], $this->lineRows($orderNo));
    }

    public function testAnOrderWithDeliveriesIsClosedToWhatWasDelivered(): void
    {
        $orderNo = $this->createOrder(['lines' => [['stockId' => '101', 'quantity' => 3.0]]]);
        $this->deliver($orderNo, [$this->lineIds($orderNo)[0] => 1.0]);

        $this->assertSame(SalesOrderService::CLOSED, $this->delete($orderNo));
        $line = $this->lineRows($orderNo)[0];
        $this->assertEquals(1, $line['quantity']);
        $this->assertEquals(1, $line['qty_sent']);
    }

    public function testAnUnknownOrderIsNotFound(): void
    {
        $this->expectException(NotFound::class);
        $this->delete(999999);
    }

    public function testTheTypeReturnsTheOrdersAsTheyWereAndWarnsOfAClose(): void
    {
        $deleted = $this->createOrder();
        $closed = $this->createOrder();
        $this->deliver($closed, [$this->lineIds($closed)[0] => 1.0]);
        $type = $this->container->get(SalesOrderType::class);

        $rows = $type->resolveDelete(null, ['id' => [(string) $deleted, (string) $closed]], $this->container);

        $this->assertSame([$deleted, $closed], array_map('intval', array_column($rows, 'id')));
        $this->assertCount(1, $rows[0]['lines'], 'the deleted order\'s lines, as they were');
        $this->assertEquals(2, $rows[1]['lines'][0]['quantity'], 'the closed order before it was closed');
        $this->assertNull($this->orderRow($deleted));
        $this->assertCount(1, array_filter(Warnings::all(), function (string $w) use ($closed): bool {
            return strpos($w, "Sales order $closed") === 0;
        }));
    }
}
