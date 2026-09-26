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
