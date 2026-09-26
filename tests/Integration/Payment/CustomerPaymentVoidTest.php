<?php

namespace FA\GraphQL\Tests\Integration\Payment;

use FA\GraphQL\Error\NotFound;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class CustomerPaymentVoidTest extends PaymentTestCase
{
    private function void(int $paymentNo): void
    {
        $this->locked(function () use ($paymentNo): void {
            $this->payments()->delete($paymentNo);
        });
    }

    public function testVoidingZeroesThePaymentAndFreesItsInvoices(): void
    {
        $invoice = $this->invoice();
        $total = $this->invoiceTotal($invoice);
        $no = $this->pay(['amount' => $total, 'allocations' => [['invoiceId' => $invoice, 'amount' => $total]]]);

        $this->void($no);

        $row = $this->transRow(12, $no);
        $this->assertEqualsWithDelta(0.0, (float) $row['ov_amount'], 0.001);
        $this->assertSame([], $this->allocationsOf($no));
        $this->assertEqualsWithDelta(0.0, (float) $this->transRow(10, $invoice)['alloc'], 0.001);
        $voided = $this->pdo()->prepare('SELECT COUNT(*) FROM 0_voided WHERE type = 12 AND id = ?');
        $voided->execute([$no]);
        $this->assertSame(1, (int) $voided->fetchColumn());
        $this->assertSame(0.0, $this->glSum(12, $no), 'the voided payment\'s GL nets to zero');
    }

    public function testAnUnknownOrAlreadyVoidedPaymentIsNotFound(): void
    {
        $no = $this->pay();
        $this->void($no);

        foreach ([999999, $no] as $id) {
            try {
                $this->void($id);
                $this->fail("Expected payment $id to be NotFound.");
            } catch (NotFound $e) {
                $this->assertStringContainsString((string) $id, $e->getMessage());
            }
        }
    }

    public function testAnInvoiceNumberIsNotAPayment(): void
    {
        $invoice = $this->invoice();
        $this->expectException(NotFound::class);
        $this->void($invoice);
    }
}
