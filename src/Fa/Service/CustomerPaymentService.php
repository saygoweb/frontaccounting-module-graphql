<?php

namespace FA\GraphQL\Fa\Service;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\FaRejected;
use FA\GraphQL\Error\NotFound;
use FA\GraphQL\Fa\DateConversion;

/**
 * Customer payments written through FrontAccounting, as sales/customer_payments.php
 * writes them, without the page (Release 3 spec section 5): write_customer_payment()
 * (sales/includes/db/payment_db.inc:23-116), then the allocation cart with every item
 * set explicitly (PaymentAllocations). Every method runs inside its caller's
 * DocumentLock and ServiceCall, in that order — the lock wraps the one FaTransaction
 * per mutation (spec section 2.1).
 *
 * The page's checks are can_process() (customer_payments.php :141-227), each ported
 * with its line.
 */
class CustomerPaymentService
{
    public const TRANS_TYPE = 12; // ST_CUSTPAYMENT
    public const VOID_MEMO = 'Voided through the GraphQL API.';
    public const POSTED = 'A posted payment cannot be changed; void it and enter it again.';
    public const FOREIGN_REALLOCATION = 'This payment is in a foreign currency and already allocated: '
        . 'FrontAccounting does not reverse the exchange variation of an allocation it clears, so its '
        . 'allocations cannot be changed: void the payment and enter it again.';

    /** The fields customerPaymentUpdate may carry besides its id (spec section 5, ruling 4). */
    private const UPDATABLE = ['id', 'allocations'];

    private Voider $voider;

    public function __construct(Voider $voider)
    {
        $this->voider = $voider;
    }

    /**
     * @param array<string, mixed> $input a CustomerPaymentCreateInput
     * @return int the payment's number
     */
    public function create(array $input): int
    {
        global $Refs;

        FaIncludes::billing();

        // :145-149, and the customer must exist.
        ReferenceCheck::requireAll($input, ['customerId' => ['debtors_master', 'debtor_no', 'customer', true]]);
        $customerId = IntKey::parse($input['customerId'], 'customerId');
        $branchId = $this->branch($customerId, $input['branchId'] ?? null);

        $bankId = IntKey::parse($input['bankAccountId'] ?? null, 'bankAccountId');
        $bank = get_bank_account($bankId);
        if (!$bank || !empty($bank['inactive'])) {
            throw new BadInput("There is no active bank account $bankId.", 'bankAccountId');
        }

        // :157-167: a valid date in the fiscal year.
        $iso = DateConversion::iso($input['date'] ?? null, 'date');
        BillingChecks::assertInFiscalYear($iso, 'date', null);
        $date = DateConversion::toFa($input['date'], 'date');

        // :174 and :205: the amount.
        $amount = (float) ($input['amount'] ?? 0);
        if ($amount <= 0) {
            throw new BadInput('The amount must be above zero.', 'amount');
        }
        // :199: the discount (0 when omitted; negative is allowed, as the page allows).
        $discount = (float) ($input['discount'] ?? 0);

        // :180-192: the charge.
        $charge = (float) ($input['charge'] ?? 0);
        if ($charge < 0 || $charge == $amount) {
            throw new BadInput('The charge must be zero or more, and not the whole amount.', 'charge');
        }
        if ($charge > 0 && get_gl_account(get_bank_charge_account($bankId)) == false) {
            $message = 'The Bank Charge Account has not been set in System and General GL Setup.';
            throw new FaRejected($message, [$message]);
        }

        // :211, and the bank amount's default: the amount, when the bank's currency is
        // the customer's (customer_payments.php :246, input_num('bank_amount', amount)).
        $customerCurrency = get_customer_currency($customerId);
        $bankCurrency = (string) $bank['bank_curr_code'];
        if (array_key_exists('bankAmount', $input) && $input['bankAmount'] !== null) {
            $bankAmount = (float) $input['bankAmount'];
            if ($bankAmount <= 0) {
                throw new BadInput('The bank amount must be above zero.', 'bankAmount');
            }
        } elseif ($bankCurrency === $customerCurrency) {
            $bankAmount = $amount;
        } else {
            throw new BadInput(
                "The payment is in $customerCurrency and the bank account in $bankCurrency: give the bank amount.",
                'bankAmount'
            );
        }

        // :217, and the bank's currency too (spec section 2.3).
        BillingChecks::assertExchangeRate($customerCurrency, $iso, 'date', null);
        BillingChecks::assertExchangeRate($bankCurrency, $iso, 'date', null);

        // :169: check_reference() — the pattern, and not already used.
        $reference = isset($input['reference']) && $input['reference'] !== ''
            ? (string) $input['reference']
            : $Refs->get_next(
                ST_CUSTPAYMENT,
                null,
                ['customer' => $customerId, 'branch' => $branchId, 'date' => $date]
            );
        if (!$Refs->is_valid($reference, ST_CUSTPAYMENT)) {
            throw new BadInput('The reference does not match the customer-payment reference pattern.', 'reference');
        }
        if (!is_new_reference($reference, ST_CUSTPAYMENT)) {
            throw new BadInput('The entered reference is already in use.', 'reference');
        }

        // Before anything is written: the allocations are checked against the open
        // invoices as they stand (:224, check_allocations()).
        $allocations = array_values($input['allocations'] ?? []);
        $limit = $amount + $discount;
        if ($allocations !== []) {
            $cart = new \allocation(ST_CUSTPAYMENT, 0, $customerId, PT_CUSTOMER);
            PaymentAllocations::validate($cart, $limit, $allocations);
        }

        // :244-246.
        $paymentNo = (int) write_customer_payment(
            0,
            $customerId,
            $branchId,
            $bankId,
            $date,
            $reference,
            $amount,
            $discount,
            (string) ($input['memo'] ?? ''),
            0,
            $charge,
            $bankAmount
        );
        // :248-250: allocations, even none — the page always writes the cart.
        PaymentAllocations::apply($paymentNo, $customerId, $date, $limit, $allocations);

        return $paymentNo;
    }

    /**
     * Replace a payment's allocations (spec section 5): nothing else can change.
     *
     * @param array<string, mixed> $input a CustomerPaymentUpdateInput
     */
    public function update(array $input): void
    {
        FaIncludes::billing();
        foreach ($input as $field => $value) {
            if ($value !== null && !in_array($field, self::UPDATABLE, true)) {
                throw new BadInput(self::POSTED, $field);
            }
        }
        if (!array_key_exists('allocations', $input) || $input['allocations'] === null) {
            throw new BadInput(
                'Give the allocations: the list replaces the payment\'s allocations; [] removes them.',
                'allocations'
            );
        }
        $id = (int) $input['id'];

        $payment = $this->lockPayment($id);
        $customerId = (int) $payment['debtor_no'];
        // Spec section 5, ruling 9.
        if (!is_company_currency(get_customer_currency($customerId)) && abs((float) $payment['alloc']) > 0) {
            throw new FaRejected(self::FOREIGN_REALLOCATION, [self::FOREIGN_REALLOCATION]);
        }
        PaymentAllocations::apply(
            $id,
            $customerId,
            sql2date($payment['tran_date']),
            (float) $payment['ov_amount'] + (float) $payment['ov_discount'],
            array_values($input['allocations'])
        );
    }

    /**
     * Void a payment (spec section 5): FrontAccounting's void_transaction(), which
     * clears its allocations; its own bank-balance refusal comes back as FA_REJECTED.
     */
    public function delete(int $id): void
    {
        FaIncludes::billing();
        $this->lockPayment($id);
        $this->voider->void(ST_CUSTPAYMENT, $id, self::VOID_MEMO);
    }

    /**
     * The payment row, locked until the transaction ends; a voided one is gone.
     *
     * @return array<string, mixed>
     */
    private function lockPayment(int $id): array
    {
        $row = db_fetch(db_query(
            'SELECT t.debtor_no, t.tran_date, t.ov_amount, t.ov_discount, t.alloc, v.id AS voided'
            . ' FROM ' . TB_PREF . 'debtor_trans t'
            . ' LEFT JOIN ' . TB_PREF . 'voided v ON v.type = t.type AND v.id = t.trans_no'
            . ' WHERE t.type = ' . ST_CUSTPAYMENT . ' AND t.trans_no = ' . db_escape($id) . ' FOR UPDATE',
            'could not lock the customer payment'
        ));
        if (!$row || $row['voided'] !== null) {
            throw new NotFound("There is no customer payment $id.");
        }

        return $row;
    }

    /**
     * :151-155 and :324-328: a customer with branches pays against one of them; one
     * without, against ANY_NUMERIC (the company's receivables account).
     *
     * @param mixed $branchId
     */
    private function branch(int $customerId, $branchId): int
    {
        if (!db_customer_has_branches($customerId)) {
            if ($branchId !== null && $branchId !== '') {
                throw new BadInput('This customer has no branches: omit branchId.', 'branchId');
            }

            return (int) ANY_NUMERIC;
        }
        if ($branchId === null || $branchId === '') {
            throw new BadInput('This customer has branches: give branchId.', 'branchId');
        }
        $branchId = IntKey::parse($branchId, 'branchId');
        $branch = get_branch($branchId);
        if (!$branch || (int) $branch['debtor_no'] !== $customerId) {
            throw new BadInput("Branch $branchId is not this customer's.", 'branchId');
        }

        return $branchId;
    }
}
