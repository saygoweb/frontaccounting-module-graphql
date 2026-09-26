<?php

namespace FA\GraphQL\Tests\Integration\Billing;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\FaRejected;
use FA\GraphQL\Error\NotFound;
use FA\GraphQL\Fa\Service\SalesOrderService;

/**
 * Release 3 spec §3: deliveryCreate, ported from sales/customer_delivery.php.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class DeliveryCreateTest extends BillingTestCase
{
    public function testADeliveryWithDefaultsDeliversEverythingLeft(): void
    {
        $order = $this->createOrder(['lines' => [
            ['stockId' => '101', 'quantity' => 2.0],
            ['stockId' => '102', 'quantity' => 3.0],
        ]]);

        $dn = $this->createDelivery($order);

        $row = $this->deliveryRow($dn);
        $this->assertSame((string) $order, $row['order_']);
        $this->assertSame($this->today(), $row['tran_date']);
        $this->assertSame('1', $row['debtor_no']);
        $this->assertSame(['2', '3'], array_map(static function (array $l): string {
            return (string) (float) $l['quantity'];
        }, $this->deliveryLines($dn)));
        $this->assertSame(['2', '3'], array_map(static function (array $l): string {
            return (string) (float) $l['qty_sent'];
        }, $this->lineRows($order)));
        $this->assertGlBalanced(13, $dn);
    }

    public function testADeliveryBumpsTheOrdersVersion(): void
    {
        $order = $this->createOrder();
        $before = (int) $this->orderRow($order)['version'];

        $this->createDelivery($order);

        $this->assertSame($before + 1, (int) $this->orderRow($order)['version']);
    }

    public function testAPartialDeliveryLeavesTheRestAndUnlistedLinesDeliverNothing(): void
    {
        $order = $this->createOrder(['lines' => [
            ['stockId' => '101', 'quantity' => 5.0],
            ['stockId' => '102', 'quantity' => 3.0],
        ]]);
        [$first, $second] = $this->lineIds($order);

        $dn = $this->createDelivery($order, ['lines' => [['orderLineId' => $first, 'quantity' => 2.0]]]);

        $sent = array_column($this->lineRows($order), 'qty_sent', 'id');
        $this->assertEqualsWithDelta(2.0, (float) $sent[$first], 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $sent[$second], 0.0001);
        // The second delivery defaults to what is left.
        $dn2 = $this->createDelivery($order);
        $this->assertNotSame($dn, $dn2);
        $sent = array_column($this->lineRows($order), 'qty_sent', 'id');
        $this->assertEqualsWithDelta(5.0, (float) $sent[$first], 0.0001);
        $this->assertEqualsWithDelta(3.0, (float) $sent[$second], 0.0001);
    }

    public function testMoreThanIsLeftIsBadInputOnThatLine(): void
    {
        $order = $this->createOrder(['lines' => [['stockId' => '101', 'quantity' => 2.0]]]);
        [$line] = $this->lineIds($order);
        try {
            $this->createDelivery($order, ['lines' => [['orderLineId' => $line, 'quantity' => 3.0]]]);
            $this->fail('expected BadInput');
        } catch (BadInput $e) {
            $this->assertSame('lines.0.quantity', $e->field());
        }
    }

    public function testANegativeQuantityIsBadInput(): void
    {
        $order = $this->createOrder();
        [$line] = $this->lineIds($order);
        $this->expectException(BadInput::class);
        $this->createDelivery($order, ['lines' => [['orderLineId' => $line, 'quantity' => -1.0]]]);
    }

    public function testALineOfAnotherOrderIsBadInput(): void
    {
        $order = $this->createOrder();
        $other = $this->createOrder();
        [$foreign] = $this->lineIds($other);
        try {
            $this->createDelivery($order, ['lines' => [['orderLineId' => $foreign, 'quantity' => 1.0]]]);
            $this->fail('expected BadInput');
        } catch (BadInput $e) {
            $this->assertSame('lines.0.orderLineId', $e->field());
        }
    }

    public function testTheSameOrderLineTwiceIsBadInput(): void
    {
        $order = $this->createOrder();
        [$line] = $this->lineIds($order);
        $this->expectException(BadInput::class);
        $this->createDelivery($order, ['lines' => [
            ['orderLineId' => $line, 'quantity' => 1.0],
            ['orderLineId' => $line, 'quantity' => 1.0],
        ]]);
    }

    public function testNothingToDeliverAndNoFreightIsBadInput(): void
    {
        $order = $this->createOrder();
        [$line] = $this->lineIds($order);
        try {
            $this->createDelivery($order, ['lines' => [['orderLineId' => $line, 'quantity' => 0.0]], 'freight' => 0.0]);
            $this->fail('expected BadInput');
        } catch (BadInput $e) {
            $this->assertSame('lines', $e->field());
        }
    }

    public function testAFullyDeliveredOrderHasNothingLeft(): void
    {
        $order = $this->createOrder();
        $this->createDelivery($order);
        $this->expectException(FaRejected::class);
        $this->createDelivery($order);
    }

    public function testAStaleOrderVersionIsRefused(): void
    {
        $order = $this->createOrder();
        try {
            $this->createDelivery($order, ['orderVersion' => (int) $this->orderRow($order)['version'] + 1]);
            $this->fail('expected FaRejected');
        } catch (FaRejected $e) {
            $this->assertSame(SalesOrderService::STALE, $e->getMessage());
        }
    }

    public function testAnOrderAtTheEditLimitIsRefused(): void
    {
        $order = $this->createOrder();
        $this->pdo()->prepare('UPDATE 0_sales_orders SET version = 255 WHERE order_no = ? AND trans_type = 30')
            ->execute([$order]);

        try {
            $this->createDelivery($order, ['orderVersion' => 255]);
            $this->fail('a delivery took the order past its edit limit');
        } catch (FaRejected $e) {
            $this->assertSame(SalesOrderService::EDIT_LIMIT, $e->getMessage());
        }
        $this->assertSame(255, (int) $this->orderRow($order)['version']);
        $this->assertEqualsWithDelta(0.0, (float) $this->lineRows($order)[0]['qty_sent'], 0.0001);
    }

    public function testAnOrderThatDoesNotExistIsNotFound(): void
    {
        $this->expectException(NotFound::class);
        $this->createDelivery(999999, ['orderVersion' => 0]);
    }

    public function testCloseOrderCancelsWhatWasNotDelivered(): void
    {
        $order = $this->createOrder(['lines' => [['stockId' => '101', 'quantity' => 5.0]]]);
        [$line] = $this->lineIds($order);

        $this->createDelivery($order, [
            'lines' => [['orderLineId' => $line, 'quantity' => 2.0]],
            'closeOrder' => true,
        ]);

        // close_sales_order(): quantity = qty_sent.
        $row = $this->lineRows($order)[0];
        $this->assertEqualsWithDelta(2.0, (float) $row['quantity'], 0.0001);
        $this->assertEqualsWithDelta(2.0, (float) $row['qty_sent'], 0.0001);
    }

    public function testADateOutsideTheFiscalYearIsBadInputOnDate(): void
    {
        $order = $this->createOrder();
        try {
            $this->createDelivery($order, ['date' => new \DateTimeImmutable('2000-01-01')]);
            $this->fail('expected BadInput');
        } catch (BadInput $e) {
            $this->assertSame('date', $e->field());
        }
    }

    public function testGivenHeaderFieldsWin(): void
    {
        $order = $this->createOrder();
        $due = (new \DateTimeImmutable($this->today()))->modify('+7 days');

        $dn = $this->createDelivery($order, [
            'dueDate' => $due,
            'shipperId' => 1,
            'freight' => 5.0,
            'comments' => 'Left at the door',
        ]);

        $row = $this->deliveryRow($dn);
        $this->assertSame($due->format('Y-m-d'), $row['due_date']);
        $this->assertSame('1', $row['ship_via']);
        $this->assertEqualsWithDelta(5.0, (float) $row['ov_freight'], 0.0001);
        $comment = $this->pdo()->prepare('SELECT memo_ FROM 0_comments WHERE type = 13 AND id = ?');
        $comment->execute([$dn]);
        $this->assertSame('Left at the door', (string) $comment->fetchColumn());
    }

    public function testAnUnknownLocationIsBadInput(): void
    {
        $order = $this->createOrder();
        try {
            $this->createDelivery($order, ['locationId' => 'NOPE']);
            $this->fail('expected BadInput');
        } catch (BadInput $e) {
            $this->assertSame('locationId', $e->field());
        }
    }

    public function testNegativeFreightIsBadInput(): void
    {
        $order = $this->createOrder();
        $this->expectException(BadInput::class);
        $this->createDelivery($order, ['freight' => -1.0]);
    }

    public function testAReferenceNotMatchingThePatternIsBadInput(): void
    {
        $order = $this->createOrder();
        try {
            $this->createDelivery($order, ['reference' => '']);
            $this->addToAssertionCount(1); // '' means "the next one"
        } catch (BadInput $e) {
            $this->fail('an empty reference means the next automatic one');
        }
        $order2 = $this->createOrder();
        $this->expectException(BadInput::class);
        // Demo reflines for deliveries are numeric patterns; a letter-only reference does not match.
        $this->createDelivery($order2, ['reference' => 'not a reference']);
    }

    public function testAutoIsNotAReferenceAClientMayUse(): void
    {
        $order = $this->createOrder();
        try {
            $this->createDelivery($order, ['reference' => 'auto']);
            $this->fail('expected BadInput');
        } catch (BadInput $e) {
            $this->assertSame('reference', $e->field());
        }
    }

    public function testTheServiceWritesAnAutoReferenceWhenAskedAndSavesNoRef(): void
    {
        $order = $this->createOrder();
        $input = [
            'orderId' => $order,
            'orderVersion' => (int) $this->orderRow($order)['version'],
            'date' => new \DateTimeImmutable($this->today()),
        ];
        $dn = \FA\GraphQL\Fa\DocumentLock::run(function () use ($input): int {
            return \FA\GraphQL\Fa\Service\ServiceCall::run(function () use ($input): int {
                return $this->deliveries()->create($input, true);
            });
        });

        $this->assertSame('auto', $this->deliveryRow($dn)['reference']);
        // 'auto' is never saved to refs (includes/references.inc:358-361). An earlier,
        // purged delivery with this number may have left its own refs row: only a row
        // saying 'auto' would be ours.
        $refs = $this->pdo()->prepare("SELECT COUNT(*) FROM 0_refs WHERE type = 13 AND id = ? AND reference = 'auto'");
        $refs->execute([$dn]);
        $this->assertSame('0', (string) $refs->fetchColumn());
    }

    public function testAReferenceInUseIsBadInputOnReference(): void
    {
        $order = $this->createOrder(['lines' => [['stockId' => '101', 'quantity' => 4.0]]]);
        [$line] = $this->lineIds($order);
        $dn = $this->createDelivery($order, ['lines' => [['orderLineId' => $line, 'quantity' => 1.0]]]);
        $used = $this->deliveryRow($dn)['reference'];
        try {
            $this->createDelivery($order, [
                'lines' => [['orderLineId' => $line, 'quantity' => 1.0]],
                'reference' => $used,
            ]);
            $this->fail('expected BadInput');
        } catch (BadInput $e) {
            $this->assertSame('reference', $e->field());
        }
    }

    public function testACustomerOnHoldIsRefused(): void
    {
        $order = $this->createOrder();
        $this->setCreditStatus(1, 3); // en_US-demo: "No more work until payment received", disallows invoices
        $this->expectException(FaRejected::class);
        $this->createDelivery($order);
    }

    public function testMoreThanIsInStockIsRefusedWhileNegativeStockIsOff(): void
    {
        // en_US-demo: allow_negative_stock = 0, item 101 has 92 at DEF.
        $order = $this->createOrder(['lines' => [['stockId' => '101', 'quantity' => 1000.0]]]);
        try {
            $this->createDelivery($order);
            $this->fail('expected FaRejected');
        } catch (FaRejected $e) {
            $this->assertStringContainsString('insufficient quantity', $e->getMessage());
            $this->assertStringContainsString('101', implode(' ', $e->getExtensions()['messages']));
        }
    }

    public function testADeliveryPostsStockMovesAndBalancedGl(): void
    {
        $order = $this->createOrder(['lines' => [['stockId' => '101', 'quantity' => 2.0]]]);

        $dn = $this->createDelivery($order);

        $moves = $this->pdo()->prepare(
            'SELECT stock_id, loc_code, qty FROM 0_stock_moves WHERE type = 13 AND trans_no = ?'
        );
        $moves->execute([$dn]);
        $move = $moves->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('101', (string) $move['stock_id']);
        $this->assertEqualsWithDelta(-2.0, (float) $move['qty'], 0.0001);
        $this->assertNotEmpty($this->glRows(13, $dn), 'item 101 has a standard cost: COGS and inventory post');
        $this->assertGlBalanced(13, $dn);
    }
}
