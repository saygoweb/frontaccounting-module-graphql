<?php

namespace FA\GraphQL\Fa\Service;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\FaRejected;
use FA\GraphQL\Error\NotFound;
use FA\GraphQL\Fa\DateConversion;

/**
 * Sales invoices written through FrontAccounting's Cart, as sales/customer_invoice.php
 * writes them, without the page (Release 3 spec section 4). Two sources: one or more
 * deliveries of the same customer and branch, or an order invoiced in one step
 * (everything remaining delivered, then invoiced, in the same transaction). Every
 * method runs inside its caller's DocumentLock and ServiceCall, in that order: the
 * lock wraps the transaction (spec section 2.1).
 *
 * Ported from upstream FrontAccounting master: sales/customer_invoice.php
 * check_quantities() (:197-224), copy_to_cart() (:245-268), check_data() (:290-352),
 * the process block (:355-373), the freight default (:589-608, see applyFreight());
 * sales/includes/db/sales_order_db.inc get_invoice_duedate() (:388-407).
 */
class InvoiceService
{
    public const TRANS_TYPE = 10; // ST_SALESINVOICE
    public const VOID_MEMO = 'Voided through the GraphQL API.';
    public const ALLOCATED = 'The invoice has payments allocated to it; deallocate them first '
        . '(customerPaymentUpdate), then void it.';

    private DeliveryService $deliveries;

    private Voider $voider;

    public function __construct(DeliveryService $deliveries, Voider $voider)
    {
        $this->deliveries = $deliveries;
        $this->voider = $voider;
    }

    /**
     * @param array<string, mixed> $input an InvoiceCreateInput
     * @return int the invoice's trans_no
     */
    public function create(array $input): int
    {
        FaIncludes::billing();

        $deliveryIds = self::given($input, 'deliveryIds') ? array_values($input['deliveryIds']) : [];
        $fromOrder = self::given($input, 'orderId');
        if (($deliveryIds === []) === !$fromOrder) {
            throw new BadInput('Give either deliveryIds or orderId (with orderVersion), not both.', 'deliveryIds');
        }
        $iso = DateConversion::iso($input['date'] ?? null, 'date');
        // check_data() :297-301: the current fiscal year, never on or before the
        // GL closing date (spec section 2.2).
        BillingChecks::assertInFiscalYear($iso, 'date', null);

        if ($fromOrder) {
            if (self::given($input, 'lines')) {
                throw new BadInput(
                    'lines choose quantities from deliveries; an order invoiced in one step takes everything '
                    . 'that remains.',
                    'lines'
                );
            }
            $deliveryIds = [$this->deliverRemaining($input, $iso)];
        } else {
            $deliveryIds = $this->checkDeliveries($deliveryIds);
        }

        // customer_invoice.php :122-128: the deliveries' Cart, made a child.
        $cart = new \Cart(ST_CUSTDELIVERY, $deliveryIds, true);
        if ($cart->count_items() == 0) {
            throw new BadInput(
                'There are no delivered items with a quantity left to invoice: nothing left to invoice.',
                'deliveryIds'
            );
        }
        $this->assertNotOnHold($cart);
        if ($cart->is_prepaid()) {
            throw new BadInput(
                'Invoicing a prepaid order is not supported: prepayment invoices are out of scope.',
                $fromOrder ? 'orderId' : 'deliveryIds'
            );
        }

        // prepare_child() dated the invoice today (fork) or new_doc_date()
        // (upstream) and took the due date from that; both are replaced here.
        $cart->document_date = DateConversion::toFa($iso, 'date');
        $this->applyTerms($cart, $input);
        $cart->due_date = self::given($input, 'dueDate')
            ? DateConversion::toFa($input['dueDate'], 'dueDate')
            : get_invoice_duedate($cart->payment, $cart->document_date);
        $cart->Comments = array_key_exists('comments', $input) ? (string) $input['comments'] : '';
        $this->applyFreight($cart, $input, $deliveryIds);
        if (!$fromOrder && self::given($input, 'lines')) {
            $this->applyQuantities($cart, array_values($input['lines']));
        }
        // check_data() :329-332
        if ($cart->has_items_dispatch() == 0 && (float) $cart->freight_cost == 0) {
            throw new BadInput('There are no item quantities on this invoice.', 'lines');
        }
        // spec section 2.3: FrontAccounting would use 1.0 where a rate is missing.
        BillingChecks::assertExchangeRate($cart->customer_currency, $iso, 'date', null);
        $cart->reference = $this->reference($cart, $input['reference'] ?? null);
        // copy_to_cart() :263
        $cart->update_payments();

        // cart_class.inc :288-347 -> write_sales_invoice(): -1 when the reference
        // is in use and ref_no_auto_increase is off (process block :360-364).
        $invoiceNo = $cart->write();
        if ($invoiceNo == -1) {
            throw new BadInput('The entered reference is already in use.', 'reference');
        }

        return (int) $invoiceNo;
    }

    /**
     * Void: void_transaction() (admin/db/voiding_db.inc :17-80) with today's date.
     * An invoice with allocations is refused first (spec section 4, ruling 3):
     * FrontAccounting's void would leave the payment's side of the allocation to
     * clean up. "Already voided" and "already credited" are FrontAccounting's own
     * refusals.
     */
    public function delete(int $id): void
    {
        FaIncludes::billing();
        $row = self::document(ST_SALESINVOICE, $id, true);
        if ($row === null) {
            throw new NotFound("There is no invoice $id.");
        }
        if (!get_voided_entry(ST_SALESINVOICE, $id) && abs((float) $row['alloc']) > 0.0001) {
            throw new FaRejected(self::ALLOCATED, [self::ALLOCATED]);
        }
        // A one-step invoice's void voids its 'auto' delivery, which gives the order
        // its quantities back: hold the order row, as an order update does, so an
        // update cannot write back the quantities it read before (Checkpoint B M-1).
        OrderLock::lockIfPresent((int) $row['order_']);
        $this->voider->void(ST_SALESINVOICE, $id, self::VOID_MEMO);
    }

    /**
     * The one-step path: DeliveryService delivers every remaining line of the order
     * with FrontAccounting's 'auto' reference, dated as the invoice, the order's
     * version checked under FOR UPDATE there.
     *
     * @param array<string, mixed> $input
     */
    private function deliverRemaining(array $input, string $iso): int
    {
        if (!self::given($input, 'orderVersion')) {
            throw new BadInput(
                'orderVersion is needed with orderId: the version of the order you read.',
                'orderVersion'
            );
        }
        $delivery = [
            'orderId' => $input['orderId'],
            'orderVersion' => $input['orderVersion'],
            'date' => $iso,
        ];
        foreach (['shipperId', 'freight'] as $field) {
            if (self::given($input, $field)) {
                $delivery[$field] = $input[$field];
            }
        }

        return $this->deliveries->create($delivery, true);
    }

    /**
     * Each delivery exists, is not voided, and all are the same customer and branch
     * (the page's batch invoicing assumes it, sales/inquiry/sales_deliveries_view.php
     * :50-75). One customer means one currency: FrontAccounting ties a currency to
     * the customer account.
     *
     * @param array<int, mixed> $given
     * @return int[]
     */
    private function checkDeliveries(array $given): array
    {
        $ids = [];
        $customer = $branch = null;
        foreach ($given as $index => $value) {
            $id = IntKey::parse($value, "deliveryIds.$index");
            $row = self::document(ST_CUSTDELIVERY, $id, false);
            if ($row === null || get_voided_entry(ST_CUSTDELIVERY, $id)) {
                throw new NotFound("There is no delivery $id.");
            }
            if ($customer === null) {
                $customer = (int) $row['debtor_no'];
                $branch = (int) $row['branch_code'];
            } elseif ((int) $row['debtor_no'] !== $customer || (int) $row['branch_code'] !== $branch) {
                throw new BadInput(
                    'The deliveries invoiced together must be for the same customer and branch.',
                    'deliveryIds'
                );
            }
            $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * customer_invoice.php :504-511: the page stops for a customer on hold.
     */
    private function assertNotOnHold(\Cart $cart): void
    {
        $customer = get_customer_to_order($cart->customer_id);
        if ($customer && $customer['dissallow_invoices'] == 1) {
            $text = _('The selected customer account is currently on hold. '
                . 'Please contact the credit control personnel to discuss.');
            throw new FaRejected($text, [$text]);
        }
    }

    /**
     * copy_to_cart() :251-254: terms change only where the point of sale allows cash
     * or credit sales. Cash-sale terms (FrontAccounting would create and allocate a
     * payment itself) and prepaid terms are refused, whether given or the
     * delivery's own (spec section 4, rulings 1 and 6).
     *
     * @param array<string, mixed> $input
     */
    private function applyTerms(\Cart $cart, array $input): void
    {
        if (self::given($input, 'paymentTermsId')) {
            if (!$cart->pos['cash_sale'] && !$cart->pos['credit_sale']) {
                throw new BadInput('Your point of sale does not allow payment terms to be chosen.', 'paymentTermsId');
            }
            $terms = get_payment_terms(IntKey::parse($input['paymentTermsId'], 'paymentTermsId'));
            if (!$terms) {
                throw new BadInput('There are no such payment terms.', 'paymentTermsId');
            }
            $cart->payment = $terms['terms_indicator'];
            $cart->payment_terms = $terms;
        }
        if ($cart->payment_terms['cash_sale']) {
            throw new BadInput(
                'Invoicing on cash-sale terms is not supported: give credit payment terms.',
                'paymentTermsId'
            );
        }
        if ($cart->payment_terms['days_before_due'] == -1) {
            throw new BadInput(
                'Prepayment terms are not supported: prepayment invoices are out of scope.',
                'paymentTermsId'
            );
        }
    }

    /**
     * copy_to_cart() :258-261 and check_data() :317-327: freight numeric and not
     * negative. The shipper likewise.
     *
     * The default is our own rule (Checkpoint B I-1): each delivery adds its freight
     * only while none of its lines has been invoiced, so a delivery invoiced in parts
     * charges its freight once. The page pre-fills the first delivery's freight
     * (read_sales_trans()), or for a batch the deliveries' sum when the company's
     * accumulate_shipping is on (set_delivery_shipping_sum(), :230-241, called at
     * :606-608), and a person corrects it; when the field is empty it charges nothing
     * once any line has been invoiced (any_already_delivered(), :589-599). An API
     * default nobody sees must not bill freight twice, nor drop a batch's other
     * deliveries' freight, so it sums per delivery and skips the ones already billed.
     * A client charging freight again gives `freight`.
     *
     * @param array<string, mixed> $input
     * @param int[] $deliveryIds
     */
    private function applyFreight(\Cart $cart, array $input, array $deliveryIds): void
    {
        if (self::given($input, 'shipperId')) {
            $shipperId = IntKey::parse($input['shipperId'], 'shipperId');
            if (!get_shipper($shipperId)) {
                throw new BadInput('There is no such shipper.', 'shipperId');
            }
            $cart->ship_via = $shipperId;
        }
        if (self::given($input, 'freight') && !self::given($input, 'orderId')) {
            if ((float) $input['freight'] < 0) {
                throw new BadInput('The shipping cost cannot be negative.', 'freight');
            }
            $cart->freight_cost = (float) $input['freight'];

            return;
        }
        $sum = 0.0;
        foreach ($deliveryIds as $id) {
            if (!self::anyInvoiced($id)) {
                $sum += (float) self::document(ST_CUSTDELIVERY, $id, false)['ov_freight'];
            }
        }
        $cart->freight_cost = $sum;
    }

    /**
     * Whether any line of the delivery has been invoiced (qty_done), read in the
     * invoice's transaction, under the document lock.
     */
    private static function anyInvoiced(int $deliveryNo): bool
    {
        $row = db_fetch_row(db_query(
            'SELECT COALESCE(SUM(qty_done), 0) FROM ' . TB_PREF . 'debtor_trans_details WHERE debtor_trans_type = '
            . ST_CUSTDELIVERY . ' AND debtor_trans_no = ' . db_escape($deliveryNo),
            'could not read the delivery lines'
        ));

        return (float) $row[0] > 0;
    }

    /**
     * check_quantities() :197-224 for a new invoice: 0 <= quantity <= what the
     * delivery line has not yet had invoiced. Lines not named keep their default:
     * everything remaining (prepare_child()).
     *
     * @param array<int, array<string, mixed>> $lines InvoiceLineQuantityInput
     */
    private function applyQuantities(\Cart $cart, array $lines): void
    {
        $seen = [];
        foreach ($lines as $index => $given) {
            $field = "lines.$index";
            $deliveryLineId = IntKey::parse($given['deliveryLineId'] ?? null, "$field.deliveryLineId");
            if (isset($seen[$deliveryLineId])) {
                throw new BadInput("Delivery line $deliveryLineId is given twice.", "$field.deliveryLineId");
            }
            $seen[$deliveryLineId] = true;
            $quantity = (float) ($given['quantity'] ?? 0);
            $found = false;
            foreach ($cart->line_items as $line) {
                if ((int) $line->src_id !== $deliveryLineId) {
                    continue;
                }
                $found = true;
                $max = round2($line->quantity - $line->qty_done, get_qty_dec($line->stock_id));
                if ($quantity < 0 || $quantity > $max) {
                    throw new BadInput(
                        "The quantity must be from 0 to $max, what is left to invoice on that delivery line.",
                        "$field.quantity"
                    );
                }
                $line->qty_dispatched = $quantity;
            }
            if (!$found) {
                throw new BadInput("There is no line $deliveryLineId on these deliveries.", "$field.deliveryLineId");
            }
        }
    }

    /**
     * check_data() :309-315: the given reference, or FrontAccounting's next for this
     * date, customer and branch; either must match the invoice reference pattern.
     */
    private function reference(\Cart $cart, ?string $given): string
    {
        global $Refs;

        $reference = $given !== null && $given !== ''
            ? $given
            : $Refs->get_next(ST_SALESINVOICE, null, [
                'date' => $cart->document_date,
                'customer' => $cart->customer_id,
                'branch' => $cart->Branch,
            ]);
        if (!$Refs->is_valid($reference, ST_SALESINVOICE)) {
            throw new BadInput('The reference does not match the invoice reference pattern.', 'reference');
        }

        return $reference;
    }

    /**
     * One debtor_trans row, or null. Not get_customer_trans(), which exits the process
     * when the document does not exist (sales/includes/db/cust_trans_db.inc).
     *
     * @return array<string, mixed>|null
     */
    private static function document(int $type, int $transNo, bool $forUpdate): ?array
    {
        $row = db_fetch(db_query(
            'SELECT debtor_no, branch_code, order_, ov_freight, alloc FROM ' . TB_PREF . 'debtor_trans WHERE type = '
            . (int) $type . ' AND trans_no = ' . db_escape($transNo) . ($forUpdate ? ' FOR UPDATE' : ''),
            'could not read the document'
        ));

        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function given(array $input, string $key): bool
    {
        return array_key_exists($key, $input) && $input[$key] !== null;
    }
}
