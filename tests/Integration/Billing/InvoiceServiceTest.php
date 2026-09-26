<?php

namespace FA\GraphQL\Tests\Integration\Billing;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\FaRejected;
use FA\GraphQL\Error\NotFound;
use FA\GraphQL\Fa\DocumentLock;
use FA\GraphQL\Fa\Service\InvoiceService;
use FA\GraphQL\Fa\Service\ServiceCall;

/**
 * invoiceCreate and invoiceDelete (Release 3 spec section 4), against
 * FrontAccounting in-process.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class InvoiceServiceTest extends InvoiceTestCase
{
    public function testADeliveryIsInvoicedWithDueDateFromTheTermsAndBalancedGl(): void
    {
        $orderNo = $this->createOrder(['lines' => [['stockId' => '101', 'quantity' => 2.0]]]);
        $dn = $this->deliverOrder($orderNo);

        $invoiceNo = $this->invoice(['deliveryIds' => [$dn], 'date' => new \DateTimeImmutable($this->today())]);

        $row = $this->transRow(InvoiceService::TRANS_TYPE, $invoiceNo);
        $this->assertSame($this->today(), $row['tran_date']);
        // Terms 3: days_before_due 10 (get_invoice_duedate, sales_order_db.inc :402-403).
        $this->assertSame(date('Y-m-d', strtotime($this->today() . ' +10 days')), $row['due_date']);
        $this->assertSame((string) $orderNo, $row['order_']);
        $lines = $this->detailRows(InvoiceService::TRANS_TYPE, $invoiceNo);
        $this->assertCount(1, $lines);
        $this->assertEquals(2, $lines[0]['quantity']);
        $delivered = $this->detailRows(13, $dn);
        $this->assertSame($delivered[0]['id'], $lines[0]['src_id'], 'the invoice line invoices the delivery line');
        $this->assertEquals(2, $delivered[0]['qty_done'], 'the delivery line is fully invoiced');
        $this->assertSame(0.0, $this->glSum(InvoiceService::TRANS_TYPE, $invoiceNo));
    }

    public function testAGivenDueDateReferenceAndCommentsWin(): void
    {
        $orderNo = $this->createOrder();
        $dn = $this->deliverOrder($orderNo);
        $due = date('Y-m-d', strtotime($this->today() . ' +45 days'));

        $invoiceNo = $this->invoice([
            'deliveryIds' => [$dn],
            'date' => new \DateTimeImmutable($this->today()),
            'dueDate' => new \DateTimeImmutable($due),
            'comments' => 'hosting, October',
        ]);

        $this->assertSame($due, $this->transRow(10, $invoiceNo)['due_date']);
        $statement = $this->pdo()->prepare('SELECT memo_ FROM 0_comments WHERE type = 10 AND id = ?');
        $statement->execute([$invoiceNo]);
        $this->assertSame('hosting, October', $statement->fetchColumn());
    }

    public function testGivenTermsRecomputeTheDueDate(): void
    {
        $orderNo = $this->createOrder();
        $dn = $this->deliverOrder($orderNo);

        // Terms 1: the 17th of the following month (day_in_following_month, :400-401).
        $invoiceNo = $this->invoice([
            'deliveryIds' => [$dn],
            'date' => new \DateTimeImmutable($this->today()),
            'paymentTermsId' => 1,
        ]);

        $row = $this->transRow(10, $invoiceNo);
        $this->assertSame('1', $row['payment_terms']);
        $this->assertSame(
            date('Y-m-17', strtotime('first day of next month', strtotime($this->today()))),
            $row['due_date']
        );
    }

    public function testPartOfADeliveryIsInvoicedAndTheRestLater(): void
    {
        $orderNo = $this->createOrder(['lines' => [['stockId' => '101', 'quantity' => 3.0]]]);
        $dn = $this->deliverOrder($orderNo);
        $deliveryLine = (int) $this->detailRows(13, $dn)[0]['id'];

        $first = $this->invoice([
            'deliveryIds' => [$dn],
            'date' => new \DateTimeImmutable($this->today()),
            'lines' => [['deliveryLineId' => $deliveryLine, 'quantity' => 1.0]],
        ]);
        $second = $this->invoice(['deliveryIds' => [$dn], 'date' => new \DateTimeImmutable($this->today())]);

        $this->assertEquals(1, $this->detailRows(10, $first)[0]['quantity']);
        $this->assertEquals(2, $this->detailRows(10, $second)[0]['quantity'], 'the default is what remains');
        $this->assertEquals(3, $this->detailRows(13, $dn)[0]['qty_done']);
    }

    public function testADeliveryInvoicedInPartsChargesItsFreightOnce(): void
    {
        // Checkpoint B I-1: the default freight of a delivery is charged by the first
        // invoice of it only; the rest of it is invoiced without freight.
        $orderNo = $this->createOrder(['lines' => [['stockId' => '101', 'quantity' => 3.0]], 'freight' => 0.0]);
        $dn = $this->deliverOrder($orderNo, null, ['freight' => 5.0]);
        $deliveryLine = (int) $this->detailRows(13, $dn)[0]['id'];

        $first = $this->invoice([
            'deliveryIds' => [$dn],
            'date' => new \DateTimeImmutable($this->today()),
            'lines' => [['deliveryLineId' => $deliveryLine, 'quantity' => 1.0]],
        ]);
        $second = $this->invoice(['deliveryIds' => [$dn], 'date' => new \DateTimeImmutable($this->today())]);

        $this->assertEquals(5, $this->transRow(10, $first)['ov_freight']);
        $this->assertEquals(0, $this->transRow(10, $second)['ov_freight'], 'the freight was billed once');
        $this->assertSame(0.0, $this->glSum(10, $first));
        $this->assertSame(0.0, $this->glSum(10, $second));
    }

    public function testOnlyTheUninvoicedDeliveriesOfABatchAddTheirFreight(): void
    {
        $orderNo = $this->createOrder(['lines' => [['stockId' => '101', 'quantity' => 3.0]], 'freight' => 0.0]);
        $lineId = $this->lineIds($orderNo)[0];
        $dn1 = $this->deliverOrder($orderNo, [['orderLineId' => $lineId, 'quantity' => 2.0]], ['freight' => 3.0]);
        $dn2 = $this->deliverOrder($orderNo, [['orderLineId' => $lineId, 'quantity' => 1.0]], ['freight' => 4.0]);
        $this->invoice([
            'deliveryIds' => [$dn1],
            'date' => new \DateTimeImmutable($this->today()),
            'lines' => [['deliveryLineId' => (int) $this->detailRows(13, $dn1)[0]['id'], 'quantity' => 1.0]],
        ]);

        $invoiceNo = $this->invoice(['deliveryIds' => [$dn1, $dn2], 'date' => new \DateTimeImmutable($this->today())]);

        $this->assertEquals(4, $this->transRow(10, $invoiceNo)['ov_freight'], 'dn1 already charged its 3');
        $this->assertSame(0.0, $this->glSum(10, $invoiceNo));
    }

    public function testAGivenFreightAndShipperWinOnTheDeliveriesPath(): void
    {
        $this->pdo()->exec(
            "INSERT INTO 0_shippers (shipper_name, contact, address) VALUES ('Checkpoint B courier', '', '')"
        );
        $shipper = (int) $this->pdo()->lastInsertId();
        try {
            $orderNo = $this->createOrder(['freight' => 0.0]);
            $dn = $this->deliverOrder($orderNo, null, ['freight' => 5.0]);

            $invoiceNo = $this->invoice([
                'deliveryIds' => [$dn],
                'date' => new \DateTimeImmutable($this->today()),
                'freight' => 2.5,
                'shipperId' => $shipper,
            ]);

            $row = $this->transRow(10, $invoiceNo);
            $this->assertEquals(2.5, $row['ov_freight']);
            $this->assertSame((string) $shipper, $row['ship_via']);
            $this->assertSame(0.0, $this->glSum(10, $invoiceNo));
        } finally {
            $this->pdo()->prepare('DELETE FROM 0_shippers WHERE shipper_id = ?')->execute([$shipper]);
            $this->pdo()->exec('ALTER TABLE 0_shippers AUTO_INCREMENT = 1');
        }
    }

    public function testANegativeFreightOrAnUnknownShipperIsRefusedOnTheDeliveriesPath(): void
    {
        $orderNo = $this->createOrder();
        $dn = $this->deliverOrder($orderNo);
        foreach (['freight' => -1.0, 'shipperId' => 999999] as $field => $value) {
            try {
                $this->invoice([
                    'deliveryIds' => [$dn],
                    'date' => new \DateTimeImmutable($this->today()),
                    $field => $value,
                ]);
                $this->fail("$field was accepted");
            } catch (BadInput $e) {
                $this->assertSame($field, $e->field());
            }
        }
    }

    public function testADeliveryLineGivenTwiceIsRefused(): void
    {
        $orderNo = $this->createOrder(['lines' => [['stockId' => '101', 'quantity' => 3.0]]]);
        $dn = $this->deliverOrder($orderNo);
        $deliveryLine = (int) $this->detailRows(13, $dn)[0]['id'];

        try {
            $this->invoice([
                'deliveryIds' => [$dn],
                'date' => new \DateTimeImmutable($this->today()),
                'lines' => [
                    ['deliveryLineId' => $deliveryLine, 'quantity' => 1.0],
                    ['deliveryLineId' => $deliveryLine, 'quantity' => 2.0],
                ],
            ]);
            $this->fail('a delivery line given twice was accepted');
        } catch (BadInput $e) {
            $this->assertSame('lines.1.deliveryLineId', $e->field());
            $this->assertStringContainsString('twice', $e->getMessage());
        }
    }

    public function testTermsCannotBeChosenWhereThePointOfSaleAllowsNeither(): void
    {
        $orderNo = $this->createOrder();
        $dn = $this->deliverOrder($orderNo);
        $this->pdo()->exec('UPDATE 0_sales_pos SET cash_sale = 0, credit_sale = 0 WHERE id = 1');
        try {
            $this->invoice([
                'deliveryIds' => [$dn],
                'date' => new \DateTimeImmutable($this->today()),
                'paymentTermsId' => 1,
            ]);
            $this->fail('terms were chosen at a point of sale that allows neither');
        } catch (BadInput $e) {
            $this->assertSame('paymentTermsId', $e->field());
            $this->assertStringContainsString('point of sale', $e->getMessage());
        } finally {
            $this->pdo()->exec('UPDATE 0_sales_pos SET cash_sale = 1, credit_sale = 1 WHERE id = 1');
        }
    }

    public function testAOneStepInvoiceRefusedAtItsWriteLeavesNothingBehind(): void
    {
        // The invoice's write returns -1 (reference in use) after the 'auto'
        // delivery was written in the same transaction: both go.
        $taken = $this->transRow(10, $this->invoice([
            'deliveryIds' => [$this->deliverOrder($this->createOrder())],
            'date' => new \DateTimeImmutable($this->today()),
        ]))['reference'];
        $orderNo = $this->createOrder(['lines' => [['stockId' => '101', 'quantity' => 2.0]]]);
        $version = (int) $this->orderRow($orderNo)['version'];
        $count = function (): int {
            return (int) $this->pdo()->query('SELECT COUNT(*) FROM 0_debtor_trans')->fetchColumn();
        };
        $before = $count();

        try {
            $this->invoice([
                'orderId' => $orderNo,
                'orderVersion' => $version,
                'date' => new \DateTimeImmutable($this->today()),
                'reference' => $taken,
            ]);
            $this->fail('a taken reference was accepted');
        } catch (BadInput $e) {
            $this->assertSame('reference', $e->field());
        }

        $this->assertSame($before, $count(), 'neither the delivery nor the invoice is left');
        $this->assertEquals(0, $this->lineRows($orderNo)[0]['qty_sent']);
        $this->assertSame($version, (int) $this->orderRow($orderNo)['version']);
        $this->assertSame('1', (string) $this->pdo()->query(
            'SELECT IS_FREE_LOCK(' . $this->pdo()->quote(DocumentLock::name()) . ')'
        )->fetchColumn(), 'the document lock is released');
    }

    public function testVoidingAOneStepInvoiceWaitsForTheOrderRow(): void
    {
        // Checkpoint B M-1: the void restores the order's quantities, so it holds the
        // order row as an order update does.
        $orderNo = $this->createOrder(['lines' => [['stockId' => '101', 'quantity' => 2.0]]]);
        $invoiceNo = $this->invoice([
            'orderId' => $orderNo,
            'orderVersion' => (int) $this->orderRow($orderNo)['version'],
            'date' => new \DateTimeImmutable($this->today()),
        ]);

        $this->assertWaitsForTheOrderRow($orderNo, function () use ($invoiceNo): void {
            $this->voidInvoice($invoiceNo);
        });
        $this->assertFalse($this->isVoided(10, $invoiceNo));
        $this->assertEquals(2, $this->lineRows($orderNo)[0]['qty_sent']);
    }

    public function testTwoDeliveriesOfOneBranchAreInvoicedTogetherWithTheirFreightSummed(): void
    {
        $orderNo = $this->createOrder(['lines' => [['stockId' => '101', 'quantity' => 2.0]], 'freight' => 0.0]);
        $lineId = $this->lineIds($orderNo)[0];
        $dn1 = $this->deliverOrder($orderNo, [['orderLineId' => $lineId, 'quantity' => 1.0]], ['freight' => 3.0]);
        $dn2 = $this->deliverOrder($orderNo, [['orderLineId' => $lineId, 'quantity' => 1.0]], ['freight' => 4.0]);

        $invoiceNo = $this->invoice(['deliveryIds' => [$dn1, $dn2], 'date' => new \DateTimeImmutable($this->today())]);

        $this->assertCount(2, $this->detailRows(10, $invoiceNo));
        $this->assertEquals(7, $this->transRow(10, $invoiceNo)['ov_freight']);
        $this->assertSame(0.0, $this->glSum(10, $invoiceNo));
    }

    public function testDeliveriesOfDifferentCustomersOrBranchesAreRefused(): void
    {
        $a = $this->createOrder();
        $b = $this->createOrder(['customerId' => 2, 'branchId' => 2]);
        $dnA = $this->deliverOrder($a);
        $dnB = $this->deliverOrder($b);

        try {
            $this->invoice(['deliveryIds' => [$dnA, $dnB], 'date' => new \DateTimeImmutable($this->today())]);
            $this->fail('mixed deliveries were invoiced');
        } catch (BadInput $e) {
            $this->assertSame('deliveryIds', $e->field());
        }
    }

    public function testAnOrderIsInvoicedInOneStep(): void
    {
        $orderNo = $this->createOrder([
            'lines' => [['stockId' => '101', 'quantity' => 2.0], ['stockId' => '102', 'quantity' => 1.0]],
        ]);

        $invoiceNo = $this->invoice([
            'orderId' => $orderNo,
            'orderVersion' => (int) $this->orderRow($orderNo)['version'],
            'date' => new \DateTimeImmutable($this->today()),
        ]);

        $this->assertCount(2, $this->detailRows(10, $invoiceNo));
        foreach ($this->lineRows($orderNo) as $line) {
            $this->assertEquals($line['quantity'], $line['qty_sent'], 'everything was delivered');
        }
        $statement = $this->pdo()->prepare("SELECT reference FROM 0_debtor_trans WHERE type = 13 AND order_ = ?");
        $statement->execute([$orderNo]);
        $this->assertSame('auto', $statement->fetchColumn(), 'the one-step delivery is FrontAccounting\'s own');
        $this->assertSame(0.0, $this->glSum(10, $invoiceNo));
    }

    public function testAStaleOrderVersionIsRefusedAndNothingIsWritten(): void
    {
        $orderNo = $this->createOrder();
        $before = (int) $this->pdo()->query('SELECT COUNT(*) FROM 0_debtor_trans')->fetchColumn();

        try {
            $this->invoice([
                'orderId' => $orderNo,
                'orderVersion' => (int) $this->orderRow($orderNo)['version'] + 1,
                'date' => new \DateTimeImmutable($this->today()),
            ]);
            $this->fail('a stale version was accepted');
        } catch (FaRejected $e) {
            $this->assertStringContainsString('changed by someone else', $e->getMessage());
        }
        $this->assertSame($before, (int) $this->pdo()->query('SELECT COUNT(*) FROM 0_debtor_trans')->fetchColumn());
    }

    public function testExactlyOneSourceIsNeeded(): void
    {
        $orderNo = $this->createOrder();
        $dn = $this->deliverOrder($orderNo);
        foreach (
            [
                ['date' => new \DateTimeImmutable($this->today())],
                [
                    'deliveryIds' => [$dn],
                    'orderId' => $orderNo,
                    'orderVersion' => 1,
                    'date' => new \DateTimeImmutable($this->today()),
                ],
            ] as $input
        ) {
            try {
                $this->invoice($input);
                $this->fail('an invoice without exactly one source was written');
            } catch (BadInput $e) {
                $this->assertSame('deliveryIds', $e->field());
            }
        }
    }

    public function testLinesAreRefusedOnTheOneStepPath(): void
    {
        $orderNo = $this->createOrder();

        $this->expectException(BadInput::class);
        $this->invoice([
            'orderId' => $orderNo,
            'orderVersion' => (int) $this->orderRow($orderNo)['version'],
            'date' => new \DateTimeImmutable($this->today()),
            'lines' => [['deliveryLineId' => 1, 'quantity' => 1.0]],
        ]);
    }

    public function testAQuantityAboveWhatRemainsIsRefused(): void
    {
        $orderNo = $this->createOrder(['lines' => [['stockId' => '101', 'quantity' => 2.0]]]);
        $dn = $this->deliverOrder($orderNo);
        $deliveryLine = (int) $this->detailRows(13, $dn)[0]['id'];

        try {
            $this->invoice([
                'deliveryIds' => [$dn],
                'date' => new \DateTimeImmutable($this->today()),
                'lines' => [['deliveryLineId' => $deliveryLine, 'quantity' => 3.0]],
            ]);
            $this->fail('more than was delivered was invoiced');
        } catch (BadInput $e) {
            $this->assertSame('lines.0.quantity', $e->field());
        }
    }

    public function testAnUnknownDeliveryLineIsRefused(): void
    {
        $orderNo = $this->createOrder();
        $dn = $this->deliverOrder($orderNo);

        $this->expectException(BadInput::class);
        $this->invoice([
            'deliveryIds' => [$dn],
            'date' => new \DateTimeImmutable($this->today()),
            'lines' => [['deliveryLineId' => 999999, 'quantity' => 1.0]],
        ]);
    }

    public function testAFullyInvoicedDeliveryHasNothingLeft(): void
    {
        $orderNo = $this->createOrder();
        $dn = $this->deliverOrder($orderNo);
        $this->invoice(['deliveryIds' => [$dn], 'date' => new \DateTimeImmutable($this->today())]);

        try {
            $this->invoice(['deliveryIds' => [$dn], 'date' => new \DateTimeImmutable($this->today())]);
            $this->fail('nothing was left, yet an invoice was written');
        } catch (BadInput $e) {
            $this->assertStringContainsString('nothing left to invoice', $e->getMessage());
        }
    }

    public function testAnUnknownDeliveryIsNotFound(): void
    {
        $this->expectException(NotFound::class);
        $this->invoice(['deliveryIds' => [999999], 'date' => new \DateTimeImmutable($this->today())]);
    }

    public function testADateOutsideTheFiscalYearIsRefused(): void
    {
        $orderNo = $this->createOrder();
        $dn = $this->deliverOrder($orderNo);

        try {
            $this->invoice(['deliveryIds' => [$dn], 'date' => new \DateTimeImmutable('2019-01-01')]);
            $this->fail('an invoice was dated outside the fiscal year');
        } catch (BadInput $e) {
            $this->assertSame('date', $e->field());
        }
    }

    public function testCashTermsAreRefused(): void
    {
        $orderNo = $this->createOrder();
        $dn = $this->deliverOrder($orderNo);

        try {
            $this->invoice([
                'deliveryIds' => [$dn],
                'date' => new \DateTimeImmutable($this->today()),
                'paymentTermsId' => 4,
            ]);
            $this->fail('a cash-sale invoice was written');
        } catch (BadInput $e) {
            $this->assertSame('paymentTermsId', $e->field());
        }
    }

    public function testPrepaidTermsAreRefused(): void
    {
        $orderNo = $this->createOrder();
        $dn = $this->deliverOrder($orderNo);

        $this->expectException(BadInput::class);
        $this->invoice([
            'deliveryIds' => [$dn],
            'date' => new \DateTimeImmutable($this->today()),
            'paymentTermsId' => 5,
        ]);
    }

    public function testAnInvalidOrTakenReferenceIsRefused(): void
    {
        $orderNo = $this->createOrder();
        $dn = $this->deliverOrder($orderNo);
        $first = $this->invoice(['deliveryIds' => [$dn], 'date' => new \DateTimeImmutable($this->today())]);
        $taken = $this->transRow(10, $first)['reference'];
        $orderNo2 = $this->createOrder();
        $dn2 = $this->deliverOrder($orderNo2);

        try {
            $this->invoice([
                'deliveryIds' => [$dn2],
                'date' => new \DateTimeImmutable($this->today()),
                'reference' => $taken,
            ]);
            $this->fail('a taken reference was accepted');
        } catch (BadInput $e) {
            $this->assertSame('reference', $e->field());
        }
    }

    public function testACustomerOnHoldIsRefused(): void
    {
        $orderNo = $this->createOrder();
        $dn = $this->deliverOrder($orderNo);
        // Demo credit status 3, "No more work until payment received": dissallow_invoices 1.
        $this->setCreditStatus(1, 3);

        try {
            $this->invoice(['deliveryIds' => [$dn], 'date' => new \DateTimeImmutable($this->today())]);
            $this->fail('an invoice was written for a customer on hold');
        } catch (FaRejected $e) {
            $this->assertStringContainsString('on hold', $e->getMessage());
        }
    }

    public function testVoidingAnInvoiceGivesItsDeliveryBackItsQuantities(): void
    {
        $orderNo = $this->createOrder(['lines' => [['stockId' => '101', 'quantity' => 2.0]]]);
        $dn = $this->deliverOrder($orderNo);
        $invoiceNo = $this->invoice(['deliveryIds' => [$dn], 'date' => new \DateTimeImmutable($this->today())]);

        $this->voidInvoice($invoiceNo);

        $this->assertTrue($this->isVoided(10, $invoiceNo));
        $this->assertEquals(0, $this->transRow(10, $invoiceNo)['ov_amount'], 'a voided invoice keeps its row, zeroed');
        $this->assertEquals(0, $this->detailRows(13, $dn)[0]['qty_done'], 'the delivery can be invoiced again');
        $this->assertSame(0.0, $this->glSum(10, $invoiceNo));
    }

    public function testVoidingAOneStepInvoiceVoidsItsAutoDeliveryToo(): void
    {
        $orderNo = $this->createOrder(['lines' => [['stockId' => '101', 'quantity' => 2.0]]]);
        $invoiceNo = $this->invoice([
            'orderId' => $orderNo,
            'orderVersion' => (int) $this->orderRow($orderNo)['version'],
            'date' => new \DateTimeImmutable($this->today()),
        ]);
        $statement = $this->pdo()->prepare('SELECT trans_no FROM 0_debtor_trans WHERE type = 13 AND order_ = ?');
        $statement->execute([$orderNo]);
        $dn = (int) $statement->fetchColumn();

        $this->voidInvoice($invoiceNo);

        $this->assertTrue($this->isVoided(13, $dn));
        $this->assertEquals(0, $this->lineRows($orderNo)[0]['qty_sent'], 'the order is as it was before');
    }

    public function testAnInvoiceWithAllocationsCannotBeVoided(): void
    {
        $orderNo = $this->createOrder();
        $dn = $this->deliverOrder($orderNo);
        $invoiceNo = $this->invoice(['deliveryIds' => [$dn], 'date' => new \DateTimeImmutable($this->today())]);
        // An allocation, as write_customer_payment + allocation::write would leave it
        // (Task 5 writes real ones): only the invoice's alloc column matters here.
        $this->pdo()->prepare('UPDATE 0_debtor_trans SET alloc = 1 WHERE type = 10 AND trans_no = ?')
            ->execute([$invoiceNo]);

        try {
            $this->voidInvoice($invoiceNo);
            $this->fail('an allocated invoice was voided');
        } catch (FaRejected $e) {
            $this->assertStringContainsString('deallocate', $e->getMessage());
        } finally {
            $this->pdo()->prepare('UPDATE 0_debtor_trans SET alloc = 0 WHERE type = 10 AND trans_no = ?')
                ->execute([$invoiceNo]);
        }
        $this->assertFalse($this->isVoided(10, $invoiceNo));
    }

    public function testVoidingAnUnknownInvoiceIsNotFound(): void
    {
        $this->expectException(NotFound::class);
        $this->voidInvoice(999999);
    }

    public function testVoidingTwiceIsRefused(): void
    {
        $orderNo = $this->createOrder();
        $dn = $this->deliverOrder($orderNo);
        $invoiceNo = $this->invoice(['deliveryIds' => [$dn], 'date' => new \DateTimeImmutable($this->today())]);
        $this->voidInvoice($invoiceNo);

        $this->expectException(FaRejected::class);
        $this->voidInvoice($invoiceNo);
    }

    public function testABatchIsAtomicAndNamesTheFailingItem(): void
    {
        $orderNo = $this->createOrder();
        $dn = $this->deliverOrder($orderNo);
        $before = (int) $this->pdo()->query('SELECT COUNT(*) FROM 0_debtor_trans WHERE type = 10')->fetchColumn();

        try {
            DocumentLock::run(function () use ($dn): array {
                return ServiceCall::each(
                    [
                        ['deliveryIds' => [$dn], 'date' => new \DateTimeImmutable($this->today())],
                        ['deliveryIds' => [999999], 'date' => new \DateTimeImmutable($this->today())],
                    ],
                    function (array $input): int {
                        return $this->invoices()->create($input);
                    }
                );
            });
            $this->fail('the batch was not refused');
        } catch (NotFound $e) {
            $this->assertSame(1, $e->index());
        }
        $this->assertSame(
            $before,
            (int) $this->pdo()->query('SELECT COUNT(*) FROM 0_debtor_trans WHERE type = 10')->fetchColumn(),
            'item 0 was rolled back with item 1'
        );
    }
}
