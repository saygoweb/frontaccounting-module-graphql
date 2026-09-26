<?php

namespace FA\GraphQL\Tests\Integration\Payment;

use FA\GraphQL\Fa\DocumentLock;
use FA\GraphQL\Fa\Service\CustomerPaymentService;
use FA\GraphQL\Fa\Service\InvoiceService;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Tests\Integration\Billing\BillingTestCase;

/**
 * Payment tests against FrontAccounting in-process, signed in as apitest. Invoices
 * are made the panel's way (InvoiceService, an order invoiced in one step); every
 * billing row the test writes — payments, allocations, invoices, voids — is removed in
 * tearDown by BillingTestCase (FaBillingRows), and nothing written before its mark.
 * Every write runs as the resolvers run it: the document lock around one ServiceCall.
 *
 * Every subclass must carry
 *
 *     @runTestsInSeparateProcesses
 *     @preserveGlobalState disabled
 */
abstract class PaymentTestCase extends BillingTestCase
{
    /** Demo customer 1: the company currency (USD), one branch. */
    protected const HOME_CUSTOMER = 1;
    protected const HOME_BRANCH = 1;
    /** Demo customer 2: EUR, one branch. */
    protected const EUR_CUSTOMER = 2;
    protected const EUR_BRANCH = 2;
    /** Demo bank account 1: USD current account. */
    protected const BANK = 1;

    protected function payments(): CustomerPaymentService
    {
        return $this->container->get(CustomerPaymentService::class);
    }

    /**
     * $work the way a resolver runs a write: the document lock around one ServiceCall.
     *
     * @return mixed
     */
    protected function locked(callable $work)
    {
        return DocumentLock::run(function () use ($work) {
            return ServiceCall::run($work);
        });
    }

    /**
     * An invoice for $quantity of item 101, the panel's way: an order invoiced in one
     * step, dated today.
     */
    protected function invoice(
        int $customerId = self::HOME_CUSTOMER,
        int $branchId = self::HOME_BRANCH,
        float $quantity = 2.0
    ): int {
        $orderNo = $this->createOrder([
            'customerId' => $customerId,
            'branchId' => $branchId,
            'lines' => [['stockId' => '101', 'quantity' => $quantity]],
        ]);
        $version = (int) $this->orderRow($orderNo)['version'];

        return $this->locked(function () use ($orderNo, $version): int {
            return $this->container->get(InvoiceService::class)->create([
                'orderId' => $orderNo,
                'orderVersion' => $version,
                'date' => new \DateTimeImmutable($this->today()),
            ]);
        });
    }

    protected function invoiceTotal(int $invoiceNo): float
    {
        $row = $this->transRow(10, $invoiceNo);

        return round((float) $row['ov_amount'] + (float) $row['ov_gst'] + (float) $row['ov_freight']
            + (float) $row['ov_freight_tax'] + (float) $row['ov_discount'], 2);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function paymentInput(array $overrides = []): array
    {
        return array_merge([
            'customerId' => self::HOME_CUSTOMER,
            'branchId' => self::HOME_BRANCH,
            'bankAccountId' => self::BANK,
            'date' => new \DateTimeImmutable($this->today()),
            'amount' => 10.0,
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function pay(array $overrides = []): int
    {
        $input = $this->paymentInput($overrides);

        return $this->locked(function () use ($input): int {
            return $this->payments()->create($input);
        });
    }

    /**
     * The refusal a service call ends in (the test fails if it succeeds).
     */
    protected function refusal(callable $work): \Throwable
    {
        try {
            $this->locked($work);
        } catch (\Throwable $e) {
            return $e;
        }
        $this->fail('Expected a refusal.');
    }

    /**
     * @return array<int, array<string, string>> the payment's allocations, by invoice
     */
    protected function allocationsOf(int $paymentNo): array
    {
        $statement = $this->pdo()->prepare(
            'SELECT trans_no_to, trans_type_to, amt FROM 0_cust_allocations
             WHERE trans_type_from = 12 AND trans_no_from = ? ORDER BY trans_no_to'
        );
        $statement->execute([$paymentNo]);
        $rows = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $rows[(int) $row['trans_no_to']] = [
                'type' => (string) $row['trans_type_to'],
                'amount' => (string) round((float) $row['amt'], 2),
            ];
        }

        return $rows;
    }

    /** The sum of a document's GL postings: 0 when it balances. */
    protected function glSum(int $type, int $transNo): float
    {
        $statement = $this->pdo()->prepare(
            'SELECT COALESCE(SUM(amount), 0) FROM 0_gl_trans WHERE type = ? AND type_no = ?'
        );
        $statement->execute([$type, $transNo]);

        return round((float) $statement->fetchColumn(), 2);
    }

    protected function paymentCount(): int
    {
        return (int) $this->pdo()->query('SELECT COUNT(*) FROM 0_debtor_trans WHERE type = 12')->fetchColumn();
    }
}
