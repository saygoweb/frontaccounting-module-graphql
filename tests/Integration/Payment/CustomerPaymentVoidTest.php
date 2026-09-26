<?php

namespace FA\GraphQL\Tests\Integration\Payment;

use FA\GraphQL\Error\FaRejected;
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

    /**
     * FrontAccounting's own refusal (voiding_db.inc :45-46, check_void_bank_trans()):
     * voiding a receipt into a cash account (account_type 3, demo bank 2) is refused
     * when a later withdrawal would then take the account below zero. The later
     * withdrawal is a bank_trans row inserted for the test, and removed after it.
     */
    public function testAVoidThatWouldOverdrawACashAccountIsRejected(): void
    {
        $invoice = $this->invoice();
        $total = $this->invoiceTotal($invoice);
        $no = $this->pay([
            'bankAccountId' => self::CASH_BANK,
            'amount' => $total,
            'allocations' => [['invoiceId' => $invoice, 'amount' => $total]],
        ]);
        $balance = $this->pdo()->prepare('SELECT SUM(amount) FROM 0_bank_trans WHERE bank_act = ? AND trans_date <= ?');
        $balance->execute([self::CASH_BANK, $this->today()]);
        $tomorrow = (new \DateTimeImmutable($this->today()))->modify('+1 day')->format('Y-m-d');
        $this->pdo()->prepare(
            "INSERT INTO 0_bank_trans (type, trans_no, bank_act, ref, trans_date, amount)
             VALUES (1, 999999, ?, 'GQLTEST', ?, ?)"
        )->execute([self::CASH_BANK, $tomorrow, -round((float) $balance->fetchColumn(), 2)]);
        $withdrawal = (int) $this->pdo()->lastInsertId();

        try {
            $this->void($no);
            $this->fail('Expected FrontAccounting to refuse the void.');
        } catch (FaRejected $e) {
            $this->assertStringContainsString('balance', $e->getMessage());
        } finally {
            $this->pdo()->prepare('DELETE FROM 0_bank_trans WHERE id = ?')->execute([$withdrawal]);
        }

        $this->assertEqualsWithDelta($total, (float) $this->transRow(12, $no)['ov_amount'], 0.001);
        $this->assertSame([$invoice => ['type' => '10', 'amount' => (string) $total]], $this->allocationsOf($no));
        $this->assertEqualsWithDelta($total, (float) $this->transRow(10, $invoice)['alloc'], 0.001);
        $voided = $this->pdo()->prepare('SELECT COUNT(*) FROM 0_voided WHERE type = 12 AND id = ?');
        $voided->execute([$no]);
        $this->assertSame(0, (int) $voided->fetchColumn());
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
