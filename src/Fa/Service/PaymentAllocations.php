<?php

namespace FA\GraphQL\Fa\Service;

use FA\GraphQL\Error\BadInput;

/**
 * A customer payment's allocations, written through FrontAccounting's allocation
 * cart (includes/ui/allocation_cart.inc) with every item set explicitly: the cart's
 * read() puts any unallocated remainder on the earliest open items (:185-202), which
 * an API must never do behind its client's back (Release 3 spec section 5, ruling 8).
 * check_allocations() (:373-437) reads $_POST; its rules are ported in validate().
 *
 * The API allocates to invoices only. An allocation the payment already has to
 * anything else — a sales-order prepayment, a journal, a bank payment, made in
 * FrontAccounting's UI — is kept at its current amount, and counts towards what the
 * payment may allocate (Checkpoint C M-1): the list replaces the invoice allocations.
 */
final class PaymentAllocations
{
    /**
     * Replace the payment's allocations with exactly $allocations.
     *
     * @param array<int, array{invoiceId: mixed, amount: mixed}> $allocations
     * @param float $limit what the payment may allocate: its amount plus discount
     */
    public static function apply(
        int $paymentNo,
        int $customerId,
        string $faDate,
        float $limit,
        array $allocations,
        string $field = 'allocations'
    ): void {
        $cart = new \allocation(ST_CUSTPAYMENT, $paymentNo, $customerId, PT_CUSTOMER);
        self::validate($cart, $limit, $allocations, $field, self::kept($paymentNo));
        // customer_payments.php :248-250: the payment's number and date, then write().
        $cart->trans_no = $paymentNo;
        $cart->date_ = $faDate;
        $cart->write();
    }

    /**
     * Zero every item the cart offers, put back the kept ones, then set the invoices
     * asked for, checking each.
     *
     * @param array<int, array{invoiceId: mixed, amount: mixed}> $allocations
     * @param array<string, array{type: int, no: int, amount: float}> $kept "type/no" =>
     *     the payment's current allocations to anything but an invoice
     */
    public static function validate(
        \allocation $cart,
        float $limit,
        array $allocations,
        string $field = 'allocations',
        array $kept = []
    ): void {
        foreach ($cart->allocs as $item) {
            $item->current_allocated = 0;
        }

        $total = 0.0;
        foreach ($kept as $key => $allocation) {
            $item = null;
            foreach ($cart->allocs as $candidate) {
                if ((int) $candidate->type . '/' . (int) $candidate->type_no === $key) {
                    $item = $candidate;
                    break;
                }
            }
            if ($item === null) {
                // The cart skips a target with nothing left (add_item(), a zero Total):
                // add it, or write() would drop the allocation it clears.
                $item = new \allocation_item(
                    $allocation['type'],
                    $allocation['no'],
                    '',
                    '',
                    $allocation['amount'],
                    0,
                    0,
                    '',
                    ''
                );
                $cart->allocs[] = $item;
            }
            $item->current_allocated = $allocation['amount'];
            $total += $allocation['amount'];
        }

        $dec = user_price_dec();
        $seen = [];
        foreach (array_values($allocations) as $index => $allocation) {
            $itemField = "$field.$index";
            $invoiceNo = IntKey::parse($allocation['invoiceId'] ?? null, "$itemField.invoiceId");
            $amount = round((float) ($allocation['amount'] ?? 0), $dec);
            if (isset($seen[$invoiceNo])) {
                throw new BadInput("Invoice $invoiceNo is allocated twice.", "$itemField.invoiceId");
            }
            $seen[$invoiceNo] = true;
            // check_allocations() :380: each amount >= 0.
            if ($amount < 0) {
                throw new BadInput('An allocation cannot be negative.', "$itemField.amount");
            }
            $item = self::item($cart, $invoiceNo);
            if ($item === null) {
                throw new BadInput(self::whyNotOpen($invoiceNo, (int) $cart->person_id), "$itemField.invoiceId");
            }
            // :391-396: no more than the invoice has left to allocate.
            $open = round($item->amount - $item->amount_allocated, $dec);
            if ($amount > 0 && $amount > $open) {
                throw new BadInput(
                    sprintf(
                        'Invoice %d has %s left to allocate; %s was asked for.',
                        $invoiceNo,
                        number_format($open, $dec, '.', ''),
                        number_format($amount, $dec, '.', '')
                    ),
                    "$itemField.amount"
                );
            }
            $item->current_allocated = $amount;
            $total += $amount;
        }

        // :430: the total may not exceed the payment (plus discount) by more than the
        // company's allowance.
        global $SysPrefs;
        if ($total - $limit > $SysPrefs->allocation_settled_allowance()) {
            throw new BadInput(
                'These allocations are more than the payment has to allocate.',
                $field
            );
        }
    }

    /**
     * The payment's current allocations to anything but an invoice; none for a
     * payment not yet written.
     *
     * @return array<string, array{type: int, no: int, amount: float}>
     */
    private static function kept(int $paymentNo): array
    {
        $kept = [];
        if ($paymentNo === 0) {
            return $kept;
        }
        $result = db_query(
            'SELECT trans_type_to, trans_no_to, SUM(amt) AS amt FROM ' . TB_PREF . 'cust_allocations'
            . ' WHERE trans_type_from = ' . ST_CUSTPAYMENT . ' AND trans_no_from = ' . db_escape($paymentNo)
            . ' AND trans_type_to <> ' . ST_SALESINVOICE
            . ' GROUP BY trans_type_to, trans_no_to ORDER BY trans_type_to, trans_no_to',
            'could not read the payment\'s allocations'
        );
        while ($row = db_fetch($result)) {
            $kept[(int) $row['trans_type_to'] . '/' . (int) $row['trans_no_to']] = [
                'type' => (int) $row['trans_type_to'],
                'no' => (int) $row['trans_no_to'],
                'amount' => (float) $row['amt'],
            ];
        }

        return $kept;
    }

    private static function item(\allocation $cart, int $invoiceNo): ?\allocation_item
    {
        foreach ($cart->allocs as $item) {
            if ((int) $item->type === ST_SALESINVOICE && (int) $item->type_no === $invoiceNo) {
                return $item;
            }
        }

        return null;
    }

    /**
     * The cart lists only the customer's open invoices (get_allocatable_to_cust_
     * transactions(), custalloc_db.inc :180-239), plus those this payment already
     * allocates to. Say which rule an absent one broke — :398-417's "not related",
     * or nothing to allocate.
     */
    private static function whyNotOpen(int $invoiceNo, int $customerId): string
    {
        $row = db_fetch(db_query(
            'SELECT debtor_no FROM ' . TB_PREF . 'debtor_trans WHERE type = ' . ST_SALESINVOICE
            . ' AND trans_no = ' . db_escape($invoiceNo),
            'could not read the invoice'
        ));
        if (!$row) {
            return "There is no invoice $invoiceNo.";
        }
        if ((int) $row['debtor_no'] !== $customerId) {
            return "Invoice $invoiceNo is not this customer's.";
        }

        return "Invoice $invoiceNo has nothing left to allocate.";
    }
}
