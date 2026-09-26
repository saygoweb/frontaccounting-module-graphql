<?php

namespace FA\GraphQL\Tests\Integration\Payment;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\FaRejected;
use FA\GraphQL\Fa\DocumentLock;
use FA\GraphQL\Fa\Service\ServiceCall;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class CustomerPaymentCreateTest extends PaymentTestCase
{
    public function testAPaymentIsPostedAsTheCustomerPaymentsPageWouldPostIt(): void
    {
        $no = $this->pay(['amount' => 25.0, 'memo' => 'panel payment']);

        $row = $this->transRow(12, $no);
        $this->assertSame((string) self::HOME_CUSTOMER, $row['debtor_no']);
        $this->assertSame((string) self::HOME_BRANCH, $row['branch_code']);
        $this->assertSame($this->today(), $row['tran_date']);
        $this->assertEqualsWithDelta(25.0, (float) $row['ov_amount'], 0.001);
        $this->assertNotSame('', $row['reference'], 'the next automatic reference');
        $this->assertSame(0.0, $this->glSum(12, $no), 'GL balanced');

        $bank = $this->pdo()->prepare('SELECT bank_act, amount FROM 0_bank_trans WHERE type = 12 AND trans_no = ?');
        $bank->execute([$no]);
        $bankRow = $bank->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame((string) self::BANK, (string) $bankRow['bank_act']);
        $this->assertEqualsWithDelta(25.0, (float) $bankRow['amount'], 0.001);

        $memo = $this->pdo()->prepare('SELECT memo_ FROM 0_comments WHERE type = 12 AND id = ?');
        $memo->execute([$no]);
        $this->assertSame('panel payment', $memo->fetchColumn());
    }

    public function testAGivenReferenceIsUsedAndATakenOneRefused(): void
    {
        // The demo's customer-payment pattern is {001}/{YYYY}.
        $reference = '987/' . date('Y');
        $no = $this->pay(['reference' => $reference]);
        $this->assertSame($reference, $this->transRow(12, $no)['reference']);

        $e = $this->refusal(function () use ($reference) {
            return $this->payments()->create($this->paymentInput(['reference' => $reference]));
        });
        $this->assertInstanceOf(BadInput::class, $e);
        $this->assertSame('reference', $e->field());
        $this->assertStringContainsString('in use', $e->getMessage());
    }

    public function testAReferenceOffThePatternIsRefused(): void
    {
        $e = $this->refusal(function () {
            return $this->payments()->create($this->paymentInput(['reference' => 'not a reference']));
        });
        $this->assertInstanceOf(BadInput::class, $e);
        $this->assertSame('reference', $e->field());
        $this->assertStringContainsString('pattern', $e->getMessage());
    }

    /**
     * @dataProvider refusals
     * @param array<string, mixed> $overrides
     */
    public function testThePagesChecksAreEnforced(array $overrides, string $field): void
    {
        $e = $this->refusal(function () use ($overrides) {
            return $this->payments()->create($this->paymentInput($overrides));
        });
        $this->assertInstanceOf(BadInput::class, $e, $e->getMessage());
        $this->assertSame($field, $e->field());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public function refusals(): array
    {
        return [
            'no such customer' => [['customerId' => 999999], 'customerId'],
            'customer id not a number' => [['customerId' => '1 x'], 'customerId'],
            'not the customer\'s branch' => [['branchId' => 2], 'branchId'],
            'no such bank account' => [['bankAccountId' => 9999], 'bankAccountId'],
            'zero amount' => [['amount' => 0.0], 'amount'],
            'negative amount' => [['amount' => -5.0], 'amount'],
            'negative charge' => [['charge' => -1.0], 'charge'],
            'charge equal to the amount' => [['amount' => 10.0, 'charge' => 10.0], 'charge'],
            'zero bank amount' => [['bankAmount' => 0.0], 'bankAmount'],
            'date outside the fiscal years' => [['date' => new \DateTimeImmutable('1990-01-01')], 'date'],
        ];
    }

    public function testACustomerWithBranchesMustNameOne(): void
    {
        $e = $this->refusal(function () {
            $input = $this->paymentInput();
            unset($input['branchId']);

            return $this->payments()->create($input);
        });
        $this->assertInstanceOf(BadInput::class, $e);
        $this->assertSame('branchId', $e->field());
    }

    /**
     * :151-155 and :324-328: a customer without branches pays against ANY_NUMERIC,
     * posted to the company's receivables account; naming a branch is refused.
     */
    public function testACustomerWithoutBranchesPaysWithoutOne(): void
    {
        $customerId = $this->branchlessCustomer();
        try {
            $e = $this->refusal(function () use ($customerId) {
                return $this->payments()->create($this->paymentInput(['customerId' => $customerId]));
            });
            $this->assertInstanceOf(BadInput::class, $e, $e->getMessage());
            $this->assertSame('branchId', $e->field());

            $input = $this->paymentInput(['customerId' => $customerId, 'amount' => 40.0]);
            unset($input['branchId']);
            $no = $this->locked(function () use ($input): int {
                return $this->payments()->create($input);
            });

            $row = $this->transRow(12, $no);
            $this->assertSame((string) $customerId, $row['debtor_no']);
            $this->assertSame('-1', $row['branch_code'], 'ANY_NUMERIC');
            $debtors = (string) get_company_pref('debtors_act');
            $this->assertSame(['1060' => 40.0, $debtors => -40.0], $this->glByAccount(12, $no));
            $this->assertSame(0.0, $this->glSum(12, $no));

            $this->locked(function () use ($no): void {
                $this->payments()->delete($no);
            });
            $this->assertSame([], $this->glByAccount(12, $no), 'the void nets every account to zero');
            $this->assertSame(0.0, $this->glSum(12, $no));
        } finally {
            $this->removeCustomer($customerId);
        }
    }

    /**
     * :199 and check_allocations() :430: the discount is posted to the branch's
     * payment-discount account, and the payment may allocate its amount plus discount.
     */
    public function testADiscountIsPostedAndCountsTowardsTheAllocations(): void
    {
        $invoice = $this->invoice();
        $total = $this->invoiceTotal($invoice);
        $branch = $this->pdo()->prepare('SELECT payment_discount_account FROM 0_cust_branch WHERE branch_code = ?');
        $branch->execute([self::HOME_BRANCH]);
        $discountAccount = (string) $branch->fetchColumn();

        $e = $this->refusal(function () use ($invoice, $total) {
            return $this->payments()->create($this->paymentInput([
                'amount' => $total - 5.0,
                'discount' => 5.0,
                'allocations' => [['invoiceId' => $invoice, 'amount' => $total + 1.0]],
            ]));
        });
        $this->assertInstanceOf(BadInput::class, $e, $e->getMessage());
        $this->assertSame('allocations.0.amount', $e->field());

        $no = $this->pay([
            'amount' => $total - 5.0,
            'discount' => 5.0,
            'allocations' => [['invoiceId' => $invoice, 'amount' => $total]],
        ]);

        $this->assertEqualsWithDelta(5.0, (float) $this->transRow(12, $no)['ov_discount'], 0.001);
        $this->assertSame(
            ['1060' => round($total - 5.0, 2), '1200' => -$total, $discountAccount => 5.0],
            $this->glByAccount(12, $no)
        );
        $this->assertSame(0.0, $this->glSum(12, $no));
        $this->assertSame([$invoice => ['type' => '10', 'amount' => (string) $total]], $this->allocationsOf($no));
        $this->assertEqualsWithDelta($total, (float) $this->transRow(10, $invoice)['alloc'], 0.001);
        $this->assertEqualsWithDelta($total, (float) $this->transRow(12, $no)['alloc'], 0.001);
    }

    public function testAChargeIsPostedToTheBankChargeAccount(): void
    {
        $no = $this->pay(['amount' => 30.0, 'charge' => 1.5]);

        $bank = $this->pdo()->prepare('SELECT amount FROM 0_bank_trans WHERE type = 12 AND trans_no = ?');
        $bank->execute([$no]);
        $this->assertEqualsWithDelta(28.5, (float) $bank->fetchColumn(), 0.001, 'bank amount less the charge');
        $this->assertSame(0.0, $this->glSum(12, $no));
    }

    public function testAForeignCurrencyPaymentIntoAHomeCurrencyAccountNeedsItsBankAmount(): void
    {
        $e = $this->refusal(function () {
            return $this->payments()->create($this->paymentInput([
                'customerId' => self::EUR_CUSTOMER,
                'branchId' => self::EUR_BRANCH,
            ]));
        });
        $this->assertInstanceOf(BadInput::class, $e);
        $this->assertSame('bankAmount', $e->field());

        $no = $this->pay([
            'customerId' => self::EUR_CUSTOMER,
            'branchId' => self::EUR_BRANCH,
            'amount' => 10.0,
            'bankAmount' => 12.0,
        ]);
        $this->assertSame(0.0, $this->glSum(12, $no), 'the exchange difference balances the GL');
    }

    /**
     * Checkpoint C I-1: the page shows bank_amount only when the currencies differ
     * (customer_payments.php :363-366) and otherwise posts the amount (:246). A
     * different bankAmount in one currency would book the gap as an exchange
     * variation, so it is refused, naming the field and the batch item.
     */
    public function testASameCurrencyBankAmountOtherThanTheAmountIsRefused(): void
    {
        $payments = $this->paymentCount();
        $gl = (int) $this->pdo()->query('SELECT COUNT(*) FROM 0_gl_trans')->fetchColumn();
        $bank = (int) $this->pdo()->query('SELECT COUNT(*) FROM 0_bank_trans')->fetchColumn();

        try {
            DocumentLock::run(function (): array {
                return ServiceCall::each(
                    [
                        $this->paymentInput(),
                        $this->paymentInput(['amount' => 100.0, 'bankAmount' => 50.0]),
                    ],
                    function (array $input): int {
                        return $this->payments()->create($input);
                    }
                );
            });
            $this->fail('Expected the bank amount to be refused.');
        } catch (BadInput $e) {
            $this->assertSame('bankAmount', $e->field());
            $this->assertSame(1, $e->index());
        }

        $this->assertSame($payments, $this->paymentCount(), 'nothing written');
        $this->assertSame($gl, (int) $this->pdo()->query('SELECT COUNT(*) FROM 0_gl_trans')->fetchColumn());
        $this->assertSame($bank, (int) $this->pdo()->query('SELECT COUNT(*) FROM 0_bank_trans')->fetchColumn());
    }

    public function testASameCurrencyBankAmountEqualToTheAmountIsAccepted(): void
    {
        $no = $this->pay(['amount' => 100.0, 'bankAmount' => 100.004]);

        $bank = $this->pdo()->prepare('SELECT amount FROM 0_bank_trans WHERE type = 12 AND trans_no = ?');
        $bank->execute([$no]);
        $this->assertEqualsWithDelta(100.0, (float) $bank->fetchColumn(), 0.001);
        $this->assertSame(0.0, $this->glSum(12, $no));
    }

    public function testAllocationsAreWrittenExactlyAsGiven(): void
    {
        $first = $this->invoice();
        $second = $this->invoice();
        $firstTotal = $this->invoiceTotal($first);

        $no = $this->pay([
            'amount' => $firstTotal + 5.0,
            'allocations' => [['invoiceId' => $first, 'amount' => $firstTotal]],
        ]);

        // Only what was asked: FrontAccounting's read() would also have put the 5.00
        // remainder on the second invoice.
        $this->assertSame([$first => ['type' => '10', 'amount' => (string) $firstTotal]], $this->allocationsOf($no));
        $this->assertEqualsWithDelta($firstTotal, (float) $this->transRow(10, $first)['alloc'], 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $this->transRow(10, $second)['alloc'], 0.001);
        $this->assertEqualsWithDelta($firstTotal, (float) $this->transRow(12, $no)['alloc'], 0.001);
    }

    public function testNoAllocationsLeavesThePaymentUnallocated(): void
    {
        $this->invoice();
        $no = $this->pay(['amount' => 5.0]);

        $this->assertSame([], $this->allocationsOf($no));
        $this->assertEqualsWithDelta(0.0, (float) $this->transRow(12, $no)['alloc'], 0.001);
    }

    /**
     * @dataProvider badAllocations
     */
    public function testBadAllocationsAreRefusedAndNothingIsWritten(string $case): void
    {
        $invoice = $this->invoice();
        $total = $this->invoiceTotal($invoice);
        $other = $this->invoice(self::EUR_CUSTOMER, self::EUR_BRANCH, 1.0);
        $allocations = [
            'more than outstanding' => [['invoiceId' => $invoice, 'amount' => $total + 1.0]],
            'more than the payment' => [['invoiceId' => $invoice, 'amount' => $total]],
            'negative' => [['invoiceId' => $invoice, 'amount' => -1.0]],
            'another customer\'s invoice' => [['invoiceId' => $other, 'amount' => 1.0]],
            'no such invoice' => [['invoiceId' => 999999, 'amount' => 1.0]],
            'the same invoice twice' => [
                ['invoiceId' => $invoice, 'amount' => 1.0],
                ['invoiceId' => $invoice, 'amount' => 1.0],
            ],
        ][$case];
        $amount = $case === 'more than the payment' ? $total - 1.0 : $total + 10.0;
        $before = $this->paymentCount();

        $e = $this->refusal(function () use ($amount, $allocations) {
            return $this->payments()->create($this->paymentInput(['amount' => $amount, 'allocations' => $allocations]));
        });

        $this->assertInstanceOf(BadInput::class, $e, $e->getMessage());
        $this->assertStringStartsWith('allocations', (string) $e->field());
        $after = $this->paymentCount();
        $this->assertSame($before, $after, 'the payment was rolled back with its allocations');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function badAllocations(): array
    {
        return array_map(static function (string $case): array {
            return [$case];
        }, array_combine($cases = [
            'more than outstanding',
            'more than the payment',
            'negative',
            'another customer\'s invoice',
            'no such invoice',
            'the same invoice twice',
        ], $cases));
    }

    public function testABatchIsOneTransaction(): void
    {
        $before = $this->paymentCount();

        try {
            DocumentLock::run(function (): array {
                return ServiceCall::each(
                    [$this->paymentInput(), $this->paymentInput(['amount' => 0.0])],
                    function (array $input): int {
                        return $this->payments()->create($input);
                    }
                );
            });
            $this->fail('Expected the second item to be refused.');
        } catch (BadInput $e) {
            $this->assertSame(1, $e->index());
        }
        $after = $this->paymentCount();
        $this->assertSame($before, $after, 'item 0 rolled back with item 1');
    }

    /**
     * get_bank_charge_account() (gl/includes/db/gl_db_bank_accounts.inc:109) reads the
     * bank account's own bank_charge_act, with no company fallback: that column is the
     * one blanked, and put back.
     */
    public function testAMissingBankChargeAccountIsRejected(): void
    {
        $read = $this->pdo()->prepare('SELECT bank_charge_act FROM 0_bank_accounts WHERE id = ?');
        $read->execute([self::BANK]);
        $account = $read->fetchColumn();
        $this->pdo()->prepare("UPDATE 0_bank_accounts SET bank_charge_act = '' WHERE id = ?")->execute([self::BANK]);
        try {
            $before = $this->paymentCount();
            $e = $this->refusal(function () {
                return $this->payments()->create($this->paymentInput(['amount' => 20.0, 'charge' => 1.0]));
            });
            $this->assertInstanceOf(FaRejected::class, $e, $e->getMessage());
            $this->assertSame($before, $this->paymentCount());
        } finally {
            $this->pdo()->prepare('UPDATE 0_bank_accounts SET bank_charge_act = ? WHERE id = ?')
                ->execute([$account, self::BANK]);
        }
    }
}
