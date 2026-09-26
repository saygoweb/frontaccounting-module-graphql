<?php

namespace FA\GraphQL\Fa\Service;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\FaRejected;
use FA\GraphQL\Error\NotFound;
use FA\GraphQL\Fa\DateConversion;

/**
 * Deliveries of sales orders, written through FrontAccounting's Cart as
 * sales/customer_delivery.php writes them, without the page (Release 3 spec §3).
 * Every method runs inside its caller's DocumentLock and ServiceCall.
 *
 * Ported, each rule with its upstream line: customer_delivery.php :91-111 (a new
 * delivery), :155-212 check_data(), :214-227 copy_to_cart(), :247-279
 * check_quantities(), :283-313 the write, :408-415 the on-hold customer.
 */
class DeliveryService
{
    public const TRANS_TYPE = 13; // ST_CUSTDELIVERY
    public const VOID_MEMO = 'Voided through the GraphQL API.';

    private Voider $voider;

    public function __construct(Voider $voider)
    {
        $this->voider = $voider;
    }

    /**
     * @param array<string, mixed> $input a DeliveryCreateInput
     * @param bool $autoReference write FrontAccounting's 'auto' reference, as its own
     *        direct invoice writes the parent delivery (cart_class.inc:301-312); for
     *        InvoiceService's invoice-from-order path only, never from a client
     * @return int the delivery's trans_no
     */
    public function create(array $input, bool $autoReference = false): int
    {
        global $SysPrefs;

        FaIncludes::billing();
        $orderId = IntKey::parse($input['orderId'] ?? null, 'orderId');
        $orderVersion = self::version($input['orderVersion'] ?? null);
        $iso = DateConversion::iso($input['date'] ?? null, 'date');
        BillingChecks::assertInFiscalYear($iso, 'date');

        OrderLock::version($orderId, $orderVersion);

        // customer_delivery.php :91-111
        $delivery = new \Cart(ST_SALESORDER, $orderId, true);
        if ($delivery->is_prepaid() && !get_company_pref('deferred_income_act')) {
            $message = 'You have to set Deferred Income Account in GL Setup to entry prepayment invoices.';
            throw new FaRejected($message, [$message]);
        }
        if ($delivery->count_items() == 0) {
            $message = 'This order has no items. There is nothing to deliver.';
            throw new FaRejected($message, [$message]);
        }
        if (!$delivery->is_released()) {
            $message = 'This prepayment order is not yet ready for delivery due to insufficient amount received.';
            throw new FaRejected($message, [$message]);
        }
        // :408-415: the page shows an on-hold customer no form.
        $customer = get_customer_to_order($delivery->customer_id);
        if ($customer && (int) $customer['dissallow_invoices'] === 1) {
            $message = 'The selected customer account is currently on hold. '
                . 'Please contact the credit control personnel to discuss.';
            throw new FaRejected($message, [$message]);
        }
        adjust_shipping_charge($delivery, $orderId);

        // copy_to_cart() :214-227 — the date first: the reference and the checks use it.
        $delivery->document_date = DateConversion::toFa($iso, 'date');
        if (array_key_exists('dueDate', $input) && $input['dueDate'] !== null) {
            $delivery->due_date = DateConversion::toFa($input['dueDate'], 'dueDate');
        }
        if (!is_date($delivery->due_date)) {
            throw new BadInput('The entered dead-line for invoice is invalid.', 'dueDate');
        }
        if (self::given($input, 'locationId')) {
            ReferenceCheck::requireAll($input, ['locationId' => ['locations', 'loc_code', 'location', false]]);
            $delivery->Location = (string) $input['locationId'];
        }
        if (self::given($input, 'shipperId')) {
            ReferenceCheck::requireAll($input, ['shipperId' => ['shippers', 'shipper_id', 'shipper', true]]);
            $delivery->ship_via = (int) $input['shipperId'];
        }
        if (self::given($input, 'freight')) {
            $freight = (float) $input['freight'];
            if ($freight < 0) {
                // :184-192
                throw new BadInput('Freight cost cannot be less than zero.', 'freight');
            }
            $delivery->freight_cost = $freight;
        }
        $delivery->Comments = (string) ($input['comments'] ?? '');
        $delivery->reference = $autoReference ? 'auto' : $this->reference($delivery, $input['reference'] ?? null);

        if (self::given($input, 'lines')) {
            $this->setQuantities($delivery, array_values($input['lines']));
        }
        // :194-197
        if (!$delivery->has_items_dispatch() && (float) $delivery->freight_cost == 0.0) {
            throw new BadInput('There are no item quantities on this delivery note.', 'lines');
        }
        BillingChecks::assertExchangeRate($delivery->customer_currency, $iso, 'date');
        // :205-209
        if (!$SysPrefs->allow_negative_stock() && ($low = $delivery->check_qoh())) {
            $message = 'This document cannot be processed because there is insufficient quantity for items marked.';
            throw new FaRejected($message, array_merge([$message], array_map('strval', $low)));
        }

        // :283-313: "cancel any quantity not dispatched" is policy 0.
        $closeOrder = (bool) ($input['closeOrder'] ?? false);
        $no = $delivery->write($closeOrder ? 0 : 1);
        if ($no == -1) {
            throw new BadInput('The entered reference is already in use.', 'reference');
        }

        return (int) $no;
    }

    /**
     * Void a delivery (Release 3 spec §3): refused once anything on it is invoiced
     * — FrontAccounting's own check covers only a delivery at version 1
     * (admin/db/voiding_db.inc:53-58).
     */
    public function delete(int $id): void
    {
        FaIncludes::billing();
        $row = db_fetch(db_query(
            'SELECT trans_no FROM ' . TB_PREF . 'debtor_trans WHERE type = ' . ST_CUSTDELIVERY
            . ' AND trans_no = ' . db_escape($id) . ' FOR UPDATE',
            'could not lock the delivery'
        ));
        if (!$row) {
            throw new NotFound("There is no delivery $id.");
        }
        $invoiced = db_fetch_row(db_query(
            'SELECT COALESCE(SUM(qty_done), 0) FROM ' . TB_PREF . 'debtor_trans_details WHERE debtor_trans_type = '
            . ST_CUSTDELIVERY . ' AND debtor_trans_no = ' . db_escape($id),
            'could not read the delivery lines'
        ));
        if ((float) $invoiced[0] > 0) {
            $message = 'This delivery has been invoiced: void the invoice first.';
            throw new FaRejected($message, [$message]);
        }
        $this->voider->void(ST_CUSTDELIVERY, $id, self::VOID_MEMO);
    }

    /**
     * check_quantities() :247-279 for a new delivery: given lines set their quantity,
     * lines not given deliver nothing.
     *
     * @param array<int, array<string, mixed>> $lines
     */
    private function setQuantities(\Cart $delivery, array $lines): void
    {
        $byOrderLine = [];
        foreach ($delivery->line_items as $key => $line) {
            $byOrderLine[(int) $line->src_id] = $key;
            $line->qty_dispatched = 0;
        }
        $seen = [];
        foreach ($lines as $index => $given) {
            $field = "lines.$index";
            $orderLineId = IntKey::parse($given['orderLineId'] ?? null, "$field.orderLineId");
            if (!isset($byOrderLine[$orderLineId])) {
                throw new BadInput("Order line $orderLineId is not on this order.", "$field.orderLineId");
            }
            if (isset($seen[$orderLineId])) {
                throw new BadInput("Order line $orderLineId is given twice.", "$field.orderLineId");
            }
            $seen[$orderLineId] = true;
            $line = $delivery->line_items[$byOrderLine[$orderLineId]];
            $quantity = (float) ($given['quantity'] ?? 0);
            $max = round2($line->quantity - $line->qty_done, get_qty_dec($line->stock_id));
            if ($quantity < 0 || $quantity > $max) {
                throw new BadInput(
                    "The quantity must be between 0 and $max, what is left to deliver.",
                    "$field.quantity"
                );
            }
            $line->qty_dispatched = $quantity;
            if (isset($given['description']) && strlen((string) $given['description']) > 0) {
                $line->item_description = (string) $given['description'];
            }
        }
    }

    /**
     * The given reference, or FrontAccounting's next delivery reference for this date,
     * customer and branch — recomputed after the date is set, since prepare_child()
     * computed it from the order's (upstream) or today's (fork) date. Either must match
     * the delivery pattern (:177-183).
     */
    private function reference(\Cart $delivery, ?string $given): string
    {
        global $Refs;

        if ($given !== null && strtolower(trim($given)) === 'auto') {
            // 'auto' is FrontAccounting's marker for a document it writes itself (never
            // saved to refs, includes/references.inc:358-361); a client may not use it.
            throw new BadInput('"auto" is not a reference.', 'reference');
        }
        $reference = $given !== null && $given !== ''
            ? $given
            : $Refs->get_next(ST_CUSTDELIVERY, null, [
                'date' => $delivery->document_date,
                'customer' => $delivery->customer_id,
                'branch' => $delivery->Branch,
            ]);
        if (!$Refs->is_valid($reference, ST_CUSTDELIVERY)) {
            throw new BadInput('The reference does not match the delivery reference pattern.', 'reference');
        }

        return $reference;
    }

    /**
     * @param mixed $value
     */
    private static function version($value): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]{0,2})$/', $value) === 1) {
            return (int) $value;
        }
        throw new BadInput('orderVersion must be the version of the order you read.', 'orderVersion');
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function given(array $input, string $key): bool
    {
        return array_key_exists($key, $input) && $input[$key] !== null;
    }
}
