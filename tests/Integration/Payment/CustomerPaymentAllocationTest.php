<?php

namespace FA\GraphQL\Tests\Integration\Payment;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\FaRejected;
use FA\GraphQL\Error\NotFound;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class CustomerPaymentAllocationTest extends PaymentTestCase
{
    private function reallocate(int $paymentNo, array $allocations, array $extra = []): void
    {
        $this->locked(function () use ($paymentNo, $allocations, $extra): void {
            $this->payments()->update(array_merge(['id' => $paymentNo, 'allocations' => $allocations], $extra));
        });
    }

    public function testAllocationsAreReplacedByTheList(): void
    {
        $first = $this->invoice();
        $second = $this->invoice();
        $total = $this->invoiceTotal($first);
        $no = $this->pay(['amount' => $total, 'allocations' => [['invoiceId' => $first, 'amount' => $total]]]);

        $this->reallocate($no, [['invoiceId' => $second, 'amount' => $total]]);

        $this->assertSame([$second => ['type' => '10', 'amount' => (string) $total]], $this->allocationsOf($no));
        $this->assertEqualsWithDelta(0.0, (float) $this->transRow(10, $first)['alloc'], 0.001);
        $this->assertEqualsWithDelta($total, (float) $this->transRow(10, $second)['alloc'], 0.001);
    }

    public function testAnEmptyListDeallocates(): void
    {
        $invoice = $this->invoice();
        $total = $this->invoiceTotal($invoice);
        $no = $this->pay(['amount' => $total, 'allocations' => [['invoiceId' => $invoice, 'amount' => $total]]]);

        $this->reallocate($no, []);

        $this->assertSame([], $this->allocationsOf($no));
        $this->assertEqualsWithDelta(0.0, (float) $this->transRow(10, $invoice)['alloc'], 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $this->transRow(12, $no)['alloc'], 0.001);
    }

    public function testAPartiallyAllocatedPaymentCanAllocateItsRemainder(): void
    {
        $invoice = $this->invoice(self::HOME_CUSTOMER, self::HOME_BRANCH, 4.0);
        $total = $this->invoiceTotal($invoice);
        $half = round($total / 2, 2);
        $no = $this->pay(['amount' => $total, 'allocations' => [['invoiceId' => $invoice, 'amount' => $half]]]);

        $this->reallocate($no, [['invoiceId' => $invoice, 'amount' => $total]]);

        $this->assertSame([$invoice => ['type' => '10', 'amount' => (string) $total]], $this->allocationsOf($no));
    }

    /**
     * Allocate $amount of the payment to sales order $orderNo directly, as
     * FrontAccounting's allocation page would (the API cannot target orders).
     */
    private function allocateToOrder(int $paymentNo, int $orderNo, float $amount): void
    {
        $this->locked(function () use ($paymentNo, $orderNo, $amount): void {
            add_cust_allocation($amount, 12, $paymentNo, 30, $orderNo, self::HOME_CUSTOMER, \Today());
            update_debtor_trans_allocation(30, $orderNo, self::HOME_CUSTOMER);
            update_debtor_trans_allocation(12, $paymentNo, self::HOME_CUSTOMER);
        });
    }

    /**
     * @return array<string, string> the payment's allocations, "type/no" => amount
     */
    private function allocationsByType(int $paymentNo): array
    {
        $statement = $this->pdo()->prepare(
            'SELECT trans_type_to, trans_no_to, amt FROM 0_cust_allocations
             WHERE trans_type_from = 12 AND trans_no_from = ? ORDER BY trans_type_to, trans_no_to'
        );
        $statement->execute([$paymentNo]);
        $rows = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $rows[$row['trans_type_to'] . '/' . $row['trans_no_to']] = (string) round((float) $row['amt'], 2);
        }

        return $rows;
    }

    /**
     * Checkpoint C M-1: the list replaces the invoice allocations only; one made in
     * FrontAccounting's UI to anything else (here a sales order) stays as it is.
     */
    public function testAnAllocationToSomethingOtherThanAnInvoiceIsKept(): void
    {
        $first = $this->invoice();
        $second = $this->invoice();
        $total = $this->invoiceTotal($first);
        $orderNo = $this->createOrder([
            'customerId' => self::HOME_CUSTOMER,
            'branchId' => self::HOME_BRANCH,
            'lines' => [['stockId' => '101', 'quantity' => 1.0]],
        ]);
        $no = $this->pay(['amount' => $total + 20.0, 'allocations' => [['invoiceId' => $first, 'amount' => $total]]]);
        $this->allocateToOrder($no, $orderNo, 20.0);

        $this->reallocate($no, [['invoiceId' => $second, 'amount' => $total]]);

        $this->assertSame(
            ['10/' . $second => (string) $total, '30/' . $orderNo => '20'],
            $this->allocationsByType($no)
        );
        $this->assertEqualsWithDelta(20.0, (float) $this->orderRow($orderNo)['alloc'], 0.001);
        $this->assertEqualsWithDelta($total + 20.0, (float) $this->transRow(12, $no)['alloc'], 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $this->transRow(10, $first)['alloc'], 0.001);

        // The kept 20 counts towards what the payment may allocate: total + 20 is all.
        try {
            $this->reallocate($no, [
                ['invoiceId' => $first, 'amount' => $total],
                ['invoiceId' => $second, 'amount' => 1.0],
            ]);
            $this->fail('Expected a refusal.');
        } catch (BadInput $e) {
            $this->assertSame('allocations', $e->field());
        }
        $this->assertSame(
            ['10/' . $second => (string) $total, '30/' . $orderNo => '20'],
            $this->allocationsByType($no)
        );

        // An empty list removes the invoice allocations only.
        $this->reallocate($no, []);
        $this->assertSame(['30/' . $orderNo => '20'], $this->allocationsByType($no));
        $this->assertEqualsWithDelta(20.0, (float) $this->transRow(12, $no)['alloc'], 0.001);
    }

    public function testOverAllocationIsRefusedOnUpdate(): void
    {
        $invoice = $this->invoice();
        $total = $this->invoiceTotal($invoice);
        $no = $this->pay(['amount' => $total - 1.0]);

        try {
            $this->reallocate($no, [['invoiceId' => $invoice, 'amount' => $total]]);
            $this->fail('Expected a refusal.');
        } catch (BadInput $e) {
            $this->assertStringStartsWith('allocations', (string) $e->field());
        }
        $this->assertSame([], $this->allocationsOf($no));
    }

    /**
     * @dataProvider otherFields
     * @param mixed $value
     */
    public function testNothingButAllocationsCanChange(string $field, $value): void
    {
        $no = $this->pay();
        try {
            $this->reallocate($no, [], [$field => $value]);
            $this->fail('Expected a refusal.');
        } catch (BadInput $e) {
            $this->assertSame($field, $e->field());
        }
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public function otherFields(): array
    {
        return [
            'amount' => ['amount', 99.0],
            'date' => ['date', new \DateTimeImmutable('2022-01-01')],
            'reference' => ['reference', 'X'],
            'customerId' => ['customerId', 2],
        ];
    }

    public function testAllocationsAreRequiredOnUpdate(): void
    {
        $no = $this->pay();
        try {
            $this->locked(function () use ($no): void {
                $this->payments()->update(['id' => $no]);
            });
            $this->fail('Expected a refusal.');
        } catch (BadInput $e) {
            $this->assertSame('allocations', $e->field());
        }
    }

    public function testAnUnknownPaymentIsNotFound(): void
    {
        $this->expectException(NotFound::class);
        $this->reallocate(999999, []);
    }

    /**
     * Spec §5, ruling 9: FrontAccounting does not reverse the exchange variation it
     * posted for an allocation when the allocation is cleared.
     */
    public function testAForeignCurrencyPaymentWithAllocationsCannotBeReallocated(): void
    {
        $invoice = $this->invoice(self::EUR_CUSTOMER, self::EUR_BRANCH, 1.0);
        $total = $this->invoiceTotal($invoice);
        $no = $this->pay([
            'customerId' => self::EUR_CUSTOMER,
            'branchId' => self::EUR_BRANCH,
            'amount' => $total,
            'bankAmount' => round($total * 1.2, 2),
            'allocations' => [['invoiceId' => $invoice, 'amount' => $total]],
        ]);
        $glBefore = (int) $this->pdo()->query('SELECT COUNT(*) FROM 0_gl_trans')->fetchColumn();

        try {
            $this->reallocate($no, []);
            $this->fail('Expected a refusal.');
        } catch (FaRejected $e) {
            $this->assertStringContainsString('void', $e->getMessage());
        }
        $this->assertSame([$invoice => ['type' => '10', 'amount' => (string) $total]], $this->allocationsOf($no));
        $this->assertSame($glBefore, (int) $this->pdo()->query('SELECT COUNT(*) FROM 0_gl_trans')->fetchColumn());
    }

    public function testAForeignCurrencyPaymentWithoutAllocationsCanBeAllocated(): void
    {
        $invoice = $this->invoice(self::EUR_CUSTOMER, self::EUR_BRANCH, 1.0);
        $total = $this->invoiceTotal($invoice);
        $no = $this->pay([
            'customerId' => self::EUR_CUSTOMER,
            'branchId' => self::EUR_BRANCH,
            'amount' => $total,
            'bankAmount' => round($total * 1.2, 2),
        ]);

        $this->reallocate($no, [['invoiceId' => $invoice, 'amount' => $total]]);

        $this->assertSame([$invoice => ['type' => '10', 'amount' => (string) $total]], $this->allocationsOf($no));
    }
}
