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
