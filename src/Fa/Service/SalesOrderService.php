<?php

namespace FA\GraphQL\Fa\Service;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\FaRejected;
use FA\GraphQL\Error\Forbidden;
use FA\GraphQL\Error\NotFound;
use FA\GraphQL\Fa\DateConversion;

/**
 * Sales orders written through FrontAccounting's Cart, as sales/sales_order_entry.php
 * writes them, without the page: no $_POST, no $_SESSION['Items'], every date set
 * explicitly (Release 2 spec section 4.4). Every method runs inside its caller's
 * ServiceCall — one FaTransaction per mutation — and commits nothing itself.
 *
 * The rules are the page's, each ported with the line it comes from in upstream
 * FrontAccounting master: sales/sales_order_entry.php can_process() (:367-476),
 * copy_to_cart() (:272-322), check_item_data() (:531-574); sales/includes/ui/
 * sales_order_ui.inc add_to_order() (:15-70), get_customer_details_to_order()
 * (:72-136). Input field names are the generated Inputs' (SalesOrderModel's).
 */
class SalesOrderService
{
    public const TRANS_TYPE = 30; // ST_SALESORDER
    public const DELETED = 'DELETED';
    public const CLOSED = 'CLOSED';
    public const STALE = 'The order was changed by someone else; read it again.';
    public const VERSION_LIMIT = 255;
    public const EDIT_LIMIT = "This order has reached FrontAccounting's edit limit of 255 versions; "
        . 'create a new order.';
    private const FROZEN = 'Something on this order has been delivered or invoiced: its customer, branch, price list, '
        . 'date, payment terms and prepayment can no longer change.';

    private RecurringSchedule $schedule;

    public function __construct(RecurringSchedule $schedule)
    {
        $this->schedule = $schedule;
    }

    /**
     * @param array<string, mixed> $input a SalesOrderCreateInput
     * @return int the new order's number
     */
    public function create(array $input): int
    {
        FaIncludes::orders();

        $lines = isset($input['lines']) ? array_values($input['lines']) : [];
        if (count($lines) === 0) {
            // can_process() :393-397
            throw new BadInput('An order needs at least one line.', 'lines');
        }
        $date = DateConversion::toFa($input['orderDate'] ?? null, 'orderDate');
        $this->assertFiscalYear($date, 'orderDate');

        // cart_class.inc :94-110, read() :246-286: a new cart with a default date and
        // reference. The date is replaced before anything is computed from it.
        if (self::given($input, 'recurring')) {
            // Refused before anything is written: sgw_sales absent, or a bad schedule.
            $this->schedule->assertWritable('recurring');
            RecurringSchedule::toColumns($input['recurring']);
        }

        $cart = new \Cart(ST_SALESORDER, 0);
        $cart->document_date = $date;
        $cart->cust_ref = '';
        $cart->Comments = '';
        $this->setCustomer($cart, $input['customerId'] ?? null, $input['branchId'] ?? null, 'customerId');
        $this->applyHeader($cart, $input);
        // Before any line is priced: pricing and the below-cost check convert through
        // the order date's exchange rate.
        $this->assertCurrencyRate($cart);
        $cart->reference = $this->reference($cart, $input['reference'] ?? null);

        foreach ($lines as $index => $line) {
            $this->addLine($cart, $line, 'lines.' . $index);
        }
        $this->validate($cart);

        // cart_class.inc :288-347: -1 when the reference is in use and
        // ref_no_auto_increase is off (sales_order_entry.php :486-495 shows the error).
        $orderNo = $cart->write(1);
        if ($orderNo == -1) {
            throw new BadInput('The entered reference is already in use.', 'reference');
        }
        if (self::given($input, 'recurring')) {
            $this->schedule->write((int) $orderNo, $input['recurring']);
        }

        return (int) $orderNo;
    }

    /**
     * The customer and branch, and everything FrontAccounting defaults from them:
     * price list, payment terms, currency, discount, delivery details, location,
     * phone and email (get_customer_details_to_order(), sales_order_ui.inc :72-136).
     *
     * @param mixed $customerId
     * @param mixed $branchId
     */
    protected function setCustomer(\Cart $cart, $customerId, $branchId, string $customerField): void
    {
        $branchField = preg_replace('/customerId$/', 'branchId', $customerField);
        $customerId = IntKey::parse($customerId, $customerField);
        $branchId = IntKey::parse($branchId, $branchField);
        // sales_order_db.inc :409-437 inner-joins credit_status and sales_types.
        $customer = get_customer_to_order($customerId);
        if (!$customer) {
            throw new BadInput(
                'There is no such customer, or its price list or credit status is missing.',
                $customerField
            );
        }
        $error = get_customer_details_to_order($cart, $customerId, $branchId);
        if ($error === '') {
            return;
        }
        $onHold = _('The selected customer account is currently on hold. '
            . 'Please contact the credit control personnel to discuss.');
        if ($error === $onHold) {
            // :81-82 (it goes on setting the cart): the page offers no Place Order button.
            throw new FaRejected($error, [$error]);
        }
        // Not this customer's branch (:100-103, it returns at once).
        throw new BadInput($error, $branchField);
    }

    /**
     * The header fields a client may give, over the defaults (copy_to_cart(),
     * sales_order_entry.php :272-322, and display_order_header()'s lists,
     * sales_order_ui.inc :243-470).
     *
     * @param array<string, mixed> $input
     */
    protected function applyHeader(\Cart $cart, array $input): void
    {
        if (self::given($input, 'salesTypeId')) {
            $type = get_sales_type(IntKey::parse($input['salesTypeId'], 'salesTypeId'));
            if (!$type) {
                throw new BadInput('There is no such price list.', 'salesTypeId');
            }
            $cart->set_sales_type($type['id'], $type['sales_type'], $type['tax_included'], $type['factor']);
        }
        if (self::given($input, 'paymentTermsId')) {
            $terms = get_payment_terms(IntKey::parse($input['paymentTermsId'], 'paymentTermsId'));
            if (!$terms) {
                throw new BadInput('There are no such payment terms.', 'paymentTermsId');
            }
            $cart->payment = $terms['terms_indicator'];
            $cart->payment_terms = $terms;
            if ($terms['cash_sale']) {
                // copy_to_cart() :289-295 on a change to cash terms; set_customer()
                // (cart_class.inc :358-361) and get_customer_details_to_order() (:130-133)
                // move a cash sale to the point of sale's location.
                $cart->due_date = $cart->document_date;
                $cart->phone = $cart->cust_ref = $cart->delivery_address = '';
                $cart->ship_via = 0;
                $cart->deliver_to = '';
                $cart->prep_amount = 0;
                $cart->set_location($cart->pos['pos_location'], $cart->pos['location_name']);
            }
        }
        if (!$cart->payment_terms['cash_sale']) {
            // copy_to_cart() :296-306: delivery details only for credit terms; on cash
            // terms FrontAccounting ignores them, and so does this.
            if (self::given($input, 'deliveryDate')) {
                $cart->due_date = DateConversion::toFa($input['deliveryDate'], 'deliveryDate');
            }
            if (self::given($input, 'customerRef')) {
                $cart->cust_ref = (string) $input['customerRef'];
            }
            if (self::given($input, 'deliverTo')) {
                $cart->deliver_to = (string) $input['deliverTo'];
            }
            if (self::given($input, 'deliveryAddress')) {
                $cart->delivery_address = (string) $input['deliveryAddress'];
            }
            if (self::given($input, 'phone')) {
                $cart->phone = (string) $input['phone'];
            }
            if (self::given($input, 'shipperId')) {
                $shipperId = IntKey::parse($input['shipperId'], 'shipperId');
                if (!get_shipper($shipperId)) {
                    throw new BadInput('There is no such shipper.', 'shipperId');
                }
                $cart->ship_via = $shipperId;
            }
            if (self::given($input, 'prepaymentAmount')) {
                // :303-304: only on a new order, or one nothing has been delivered or
                // invoiced from (Task 8 refuses the change on a started order).
                $cart->prep_amount = (float) $input['prepaymentAmount'];
            }
        }
        if (self::given($input, 'locationId')) {
            $location = get_item_location((string) $input['locationId']);
            if (!$location) {
                throw new BadInput('There is no such location.', 'locationId');
            }
            $cart->set_location($location['loc_code'], $location['location_name']);
        }
        if (self::given($input, 'freight')) {
            // can_process() :421-429: numeric and not negative.
            if ((float) $input['freight'] < 0) {
                throw new BadInput('The shipping cost cannot be negative.', 'freight');
            }
            $cart->freight_cost = (float) $input['freight'];
        }
        if (array_key_exists('comments', $input)) {
            $cart->Comments = (string) $input['comments'];
        }
    }

    /**
     * The reference: the given one, or FrontAccounting's next for this date, customer
     * and branch; either must match the sales-order reference pattern (can_process()
     * :452-456).
     */
    protected function reference(\Cart $cart, ?string $given): string
    {
        global $Refs;

        $reference = $given !== null && $given !== ''
            ? $given
            : $Refs->get_next(ST_SALESORDER, null, [
                'date' => $cart->document_date,
                'customer' => $cart->customer_id,
                'branch' => $cart->Branch,
            ]);
        if (!$Refs->is_valid($reference, ST_SALESORDER)) {
            throw new BadInput('The reference does not match the sales-order reference pattern.', 'reference');
        }

        return $reference;
    }

    /**
     * A new line, priced from the price list unless a price is given, with the
     * customer's discount unless one is given (the page's defaults,
     * sales_order_ui.inc :519-530), expanded if it is a kit.
     *
     * @param array<string, mixed> $line a SalesOrderLineCreateInput
     */
    protected function addLine(\Cart $cart, array $line, string $field): void
    {
        $stockId = (string) ($line['stockId'] ?? '');
        $quantity = (float) ($line['quantity'] ?? 0);
        $discount = self::given($line, 'discountPercent')
            ? (float) $line['discountPercent']
            : (float) $cart->default_discount * 100;
        $price = self::given($line, 'unitPrice') ? (float) $line['unitPrice'] : null;
        $this->checkLine($stockId, $quantity, $price, $discount, $field, 0.0, false);

        if ($price === null) {
            $price = (float) get_kit_price(
                $stockId,
                $cart->customer_currency,
                $cart->sales_type,
                $cart->price_factor,
                $cart->document_date
            );
        }
        $this->warnIfBelowCost($cart, $stockId, $price);
        $this->addToOrder($cart, $stockId, $quantity, $price, $discount / 100, $line['description'] ?? null);
    }

    /**
     * check_item_data(), sales_order_entry.php :531-553. The "description cannot be
     * empty" check (:536-540) does not apply: FrontAccounting takes the item's own.
     * $qtyDelivered and $recurring are for Task 8's updates and Task 9's recurring
     * orders.
     */
    protected function checkLine(
        string $stockId,
        float $quantity,
        ?float $price,
        float $discountPercent,
        string $field,
        float $qtyDelivered,
        bool $recurring
    ): void {
        global $SysPrefs;

        // add_to_cart() refuses an unknown code (cart_class.inc :398-410); a plain item
        // has an item_codes row for itself, a kit one per component.
        if (db_num_rows(get_item_kit($stockId)) === 0) {
            throw new BadInput("There is no item '$stockId'.", "$field.stockId");
        }
        if ($quantity < 0) {
            throw new BadInput('The quantity cannot be negative.', "$field.quantity");
        }
        if ($discountPercent < 0 || $discountPercent > 100) {
            throw new BadInput('The discount must be from 0 to 100 percent.', "$field.discountPercent");
        }
        if ($price !== null && $price < 0 && (!$SysPrefs->allow_negative_prices() || is_inventory_item($stockId))) {
            throw new BadInput(
                'Price for inventory item must be entered and can not be less than 0',
                "$field.unitPrice"
            );
        }
        if (!$recurring && $quantity < $qtyDelivered) {
            throw new BadInput(
                'The quantity cannot be less than has already been delivered.',
                "$field.quantity"
            );
        }
    }

    /**
     * check_item_data() :555-571: a price below standard cost is a warning, and the
     * order is placed. FrontAccounting's own text, through display_warning(), reaches
     * the response's extensions.warnings.
     */
    protected function warnIfBelowCost(\Cart $cart, string $stockId, float $price): void
    {
        $costHome = get_unit_cost($stockId);
        $cost = $costHome / get_exchange_rate_from_home_currency($cart->customer_currency, $cart->document_date);
        if ($price < $cost) {
            $dec = user_price_dec();
            $shown = number_format2($price, $dec);
            if ($costHome == $cost) {
                $standard = number_format2($costHome, $dec);
            } else {
                $shown = $cart->customer_currency . ' ' . $shown;
                $standard = $cart->customer_currency . ' ' . number_format2($cost, $dec);
            }
            display_warning(sprintf(_('Price %s is below Standard Cost %s'), $shown, $standard));
        }
    }

    /**
     * add_to_order(), sales_order_ui.inc :15-70, with the document date in place of
     * get_post('OrderDate'): a kit's price is spread over its components in proportion
     * to their standard prices, rounding going to the last; a nested kit recurses.
     */
    private function addToOrder(
        \Cart $cart,
        string $code,
        float $quantity,
        float $price,
        float $discount,
        ?string $description
    ): void {
        $standard = get_kit_price(
            $code,
            $cart->customer_currency,
            $cart->sales_type,
            $cart->price_factor,
            $cart->document_date,
            true
        );
        $priceFactor = $standard == 0 ? 0 : $price / $standard;

        $kit = get_item_kit($code);
        $left = db_num_rows($kit);
        while ($item = db_fetch($kit)) {
            $itemStandard = get_kit_price(
                $item['stock_id'],
                $cart->customer_currency,
                $cart->sales_type,
                $cart->price_factor,
                $cart->document_date,
                true
            );
            $left--;
            if ($left) {
                $price -= $item['quantity'] * $itemStandard * $priceFactor;
                $itemPrice = $itemStandard * $priceFactor;
            } else {
                if ($item['quantity']) {
                    $price = $price / $item['quantity'];
                }
                $itemPrice = $price;
            }
            $itemPrice = round($itemPrice, user_price_dec());

            if (!$item['is_foreign'] && $item['item_code'] != $item['stock_id']) {
                $this->addToOrder($cart, $item['stock_id'], $quantity * $item['quantity'], $itemPrice, $discount, null);
                continue;
            }
            foreach ($cart->line_items as $existing) {
                if (strcasecmp($existing->stock_id, $item['stock_id']) == 0) {
                    display_warning(_('For Part :') . $item['stock_id'] . ' '
                        . _('This item is already on this document. You have been warned.'));
                    break;
                }
            }
            $cart->add_to_cart(
                count($cart->line_items),
                $item['stock_id'],
                $quantity * $item['quantity'],
                $itemPrice,
                $discount,
                0,
                0,
                $description
            );
        }
    }

    /**
     * can_process(), sales_order_entry.php :367-476, over the whole cart.
     */
    protected function validate(\Cart $cart): void
    {
        // :373-384 customer and branch: setCustomer() refused unknown ones.
        // :386-390 the page skips the fiscal-year check for orders; assertFiscalYear()
        // checks what the audit trail needs. :398-402 check_qoh() returns nothing for a
        // sales order (cart_class.inc :581-586).
        if (count($cart->line_items) === 0) {
            throw new BadInput('An order needs at least one line.', 'lines');
        }
        if (!$cart->payment_terms['cash_sale']) {
            if (
                !$cart->is_started() && $cart->payment_terms['days_before_due'] == -1
                && ($cart->prep_amount <= 0 || $cart->prep_amount > $cart->get_trans_total())
            ) {
                // :403-408
                throw new BadInput(
                    'Pre-payment required have to be positive and less than total amount.',
                    'prepaymentAmount'
                );
            }
            if (strlen((string) $cart->deliver_to) <= 1) {
                // :409-413
                throw new BadInput(
                    'You must enter the person or company to whom delivery should be made to.',
                    'deliverTo'
                );
            }
            if (strlen((string) $cart->delivery_address) <= 1) {
                // :415-419
                throw new BadInput(
                    'You should enter the street address in the box provided. Orders cannot be accepted '
                    . 'without a valid street address.',
                    'deliveryAddress'
                );
            }
            // :421-429 freight: applyHeader(). :430-437 a valid delivery date: the Date scalar.
            if (date1_greater_date2($cart->document_date, $cart->due_date)) {
                // :438-444
                throw new BadInput('The requested delivery date is before the date of the order.', 'deliveryDate');
            }
        } elseif (!db_has_cash_accounts()) {
            // :446-451
            $message = 'You need to define a cash account for your Sales Point.';
            throw new FaRejected($message, [$message]);
        }
        // :452-456 the reference: reference(). :457-458 the exchange rate:
        // assertCurrencyRate(), before the lines were priced.
        if ($cart->get_items_total() < 0) {
            // :460-463
            throw new BadInput('The order total cannot be less than zero.', 'lines');
        }
    }

    /**
     * can_process() :457-458: an order in a foreign currency needs an exchange rate on
     * or before its date (the page shows nothing; an API has to say why).
     */
    protected function assertCurrencyRate(\Cart $cart): void
    {
        if (!db_has_currency_rates($cart->customer_currency, $cart->document_date)) {
            throw new BadInput(
                sprintf('There is no exchange rate for %s as of %s.', $cart->customer_currency, $cart->document_date),
                'orderDate'
            );
        }
    }

    /**
     * add_audit_trail() (includes/db/audit_trail_db.inc :13-37) stamps
     * audit_trail.fiscal_year, a NOT NULL column, from the document date: a date no
     * fiscal year covers would fail as a database error. is_date_in_fiscalyears()
     * with $closed = true counts closed years too, as the audit trail does.
     */
    protected function assertFiscalYear(string $faDate, string $field): void
    {
        if (!is_date_in_fiscalyears($faDate, true)) {
            throw new BadInput("No fiscal year covers $faDate.", $field);
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    protected static function given(array $input, string $key): bool
    {
        return array_key_exists($key, $input) && $input[$key] !== null;
    }

    /**
     * Edit an order as sales_order_entry.php?ModifyOrderNumber= does.
     *
     * @param array<string, mixed> $input a SalesOrderUpdateInput
     */
    public function update(array $input): void
    {
        FaIncludes::orders();
        if (self::given($input, 'recurring')) {
            $this->schedule->assertWritable('recurring');
            RecurringSchedule::toColumns($input['recurring']);
        }
        $id = (int) $input['id'];
        $this->lockVersion($id, (int) $input['version']);
        $this->assertEditable($id);

        // read_sales_order(), sales_order_db.inc :303-355.
        $cart = new \Cart(ST_SALESORDER, $id);
        $recurring = $this->isRecurringOrder($id, $input);
        if ($cart->is_started() && !$recurring) {
            $this->assertHeaderUnchanged($cart, $input);
        }

        $oldSalesType = $cart->sales_type;
        $oldCurrency = $cart->customer_currency;
        $oldDate = $cart->document_date;
        if (self::given($input, 'orderDate')) {
            $cart->document_date = DateConversion::toFa($input['orderDate'], 'orderDate');
            $this->assertFiscalYear($cart->document_date, 'orderDate');
        }
        $customerId = self::given($input, 'customerId')
            ? IntKey::parse($input['customerId'], 'customerId')
            : (int) $cart->customer_id;
        $branchId = self::given($input, 'branchId')
            ? IntKey::parse($input['branchId'], 'branchId')
            : (int) $cart->Branch;
        if ($customerId !== (int) $cart->customer_id || $branchId !== (int) $cart->Branch) {
            // A new customer or branch brings its defaults, as when the page's customer
            // list changes (sales_order_ui.inc :285-349).
            $this->setCustomer($cart, $customerId, $branchId, 'customerId');
        } elseif ($cart->document_date !== $oldDate && !self::given($input, 'deliveryDate')) {
            // display_order_header() :430-436: a new date moves the delivery date.
            global $SysPrefs;
            $cart->due_date = add_days($cart->document_date, $SysPrefs->default_delivery_required_by());
        }
        $this->applyHeader($cart, $input);
        // can_process() :457-458, before anything is priced (as in create()).
        $this->assertCurrencyRate($cart);
        if (self::given($input, 'reference') && (string) $input['reference'] !== (string) $cart->reference) {
            $cart->reference = $this->reference($cart, (string) $input['reference']);
            // Cart::write() checks a reference is new only for a new order
            // (cart_class.inc :293); an edit could otherwise take another order's.
            if (!is_new_reference($cart->reference, ST_SALESORDER, $id)) {
                throw new BadInput('The entered reference is already in use.', 'reference');
            }
        }

        $dateReprices = $cart->document_date !== $oldDate
            && !is_company_currency($cart->customer_currency) && get_base_sales_type() > 0;
        if ($cart->sales_type != $oldSalesType || $cart->customer_currency != $oldCurrency || $dateReprices) {
            $this->reprice($cart);
        }
        if (array_key_exists('lines', $input) && $input['lines'] !== null) {
            $this->replaceLines($cart, array_values($input['lines']), $recurring);
        }
        $this->validate($cart);

        // update_sales_order(), sales_order_db.inc :122-222: returns nothing.
        $cart->write(1);
        $this->afterUpdate($id, $input);
    }

    /**
     * FrontAccounting's cancel (handle_cancel_order(), sales_order_entry.php :634-648):
     * delivered-from orders are closed, others deleted. No version check: the generated
     * salesOrderDelete takes only ids (Release 2 spec section 4.4, revised); the row
     * lock makes the deliveries check and the delete one step.
     */
    public function delete(int $id): string
    {
        FaIncludes::orders();
        $this->lockOrder($id);
        $this->assertEditable($id);

        if (sales_order_has_deliveries($id)) {
            close_sales_order($id);
            $this->afterClose($id);

            return self::CLOSED;
        }
        delete_sales_order($id, ST_SALESORDER);
        $this->afterDelete($id);

        return self::DELETED;
    }

    /**
     * sgw_sales keys its relaxations to its "Recurring Order" box: an order that has a
     * schedule, or is being given one now.
     *
     * @param array<string, mixed> $input
     */
    protected function isRecurringOrder(int $id, array $input): bool
    {
        return self::given($input, 'recurring') || $this->schedule->read($id) !== null;
    }

    /**
     * @param array<string, mixed> $input
     */
    protected function afterUpdate(int $id, array $input): void
    {
        if (self::given($input, 'recurring')) {
            $this->schedule->write($id, $input['recurring']);
        }
    }

    protected function afterDelete(int $id): void
    {
        $this->schedule->delete($id);
    }

    protected function afterClose(int $id): void
    {
        $this->schedule->end($id, DateConversion::fromFa(\Today()));
    }

    /**
     * The row lock and the version check, inside the caller's transaction: the lock
     * holds until it commits, so nobody writes between this check and ours.
     * FrontAccounting's own check (update_sales_order()'s WHERE version = …) ignores a
     * zero-row update and rewrites the lines anyway.
     *
     * sales_orders.version is tinyint unsigned: FrontAccounting connects with
     * sql_mode = STRICT_ALL_TABLES, so a write that would take version past 255 fails
     * in FrontAccounting itself with an INTERNAL "out of range" error, and the order
     * can never be edited again (Checkpoint C review M-1). Refused here, before any
     * write, as FA_REJECTED instead.
     */
    private function lockVersion(int $id, int $version): void
    {
        $current = $this->lockOrder($id);
        if ($current >= self::VERSION_LIMIT) {
            throw new FaRejected(self::EDIT_LIMIT, [self::EDIT_LIMIT]);
        }
        if ($current !== $version) {
            throw new FaRejected(self::STALE, [self::STALE]);
        }
    }

    /**
     * @return int the order's version
     */
    private function lockOrder(int $id): int
    {
        $result = db_query(
            'SELECT version FROM ' . TB_PREF . 'sales_orders WHERE order_no = ' . db_escape($id)
            . ' AND trans_type = ' . ST_SALESORDER . ' FOR UPDATE',
            'could not lock the sales order'
        );
        $row = db_fetch($result);
        if (!$row) {
            throw new NotFound("There is no sales order $id.");
        }

        return (int) $row['version'];
    }

    /**
     * What the page checks before it shows an order for editing
     * (sales_order_entry.php :104-112).
     */
    private function assertEditable(int $id): void
    {
        if (is_prepaid_order_open($id)) {
            $message = 'This order cannot be edited because there are invoices or payments related to it, '
                . 'and prepayment terms were used.';
            throw new FaRejected($message, [$message]);
        }
        // check_is_editable(), includes/data_checks.inc :646-659.
        $user = $_SESSION['wa_current_user'];
        if (!$user->can_access('SA_EDITOTHERSTRANS')) {
            $audit = get_audit_trail_last(ST_SALESORDER, $id);
            // No audit row: FrontAccounting's comparison fails too.
            if (!$audit || (int) $user->user !== (int) $audit['user']) {
                throw new Forbidden('You have no edit access to transactions created by other users.');
            }
        }
    }

    /**
     * Once started, the page shows the customer, branch, price list and date
     * read-only (sales_order_ui.inc :254-263), freezes the payment terms (:378-379)
     * and the prepayment (copy_to_cart() :303-304). Giving a frozen field its current
     * value is not a change.
     *
     * @param array<string, mixed> $input
     */
    private function assertHeaderUnchanged(\Cart $cart, array $input): void
    {
        $current = [
            'customerId' => (int) $cart->customer_id,
            'branchId' => (int) $cart->Branch,
            'salesTypeId' => (int) $cart->sales_type,
            'paymentTermsId' => (int) $cart->payment,
        ];
        foreach ($current as $field => $value) {
            if (self::given($input, $field) && IntKey::parse($input[$field], $field) !== $value) {
                throw new BadInput(self::FROZEN, $field);
            }
        }
        if (
            self::given($input, 'orderDate')
            && DateConversion::iso($input['orderDate'], 'orderDate') !== DateConversion::fromFa($cart->document_date)
        ) {
            throw new BadInput(self::FROZEN, 'orderDate');
        }
        if (
            self::given($input, 'prepaymentAmount')
            && abs((float) $input['prepaymentAmount'] - (float) $cart->prep_amount) > 0.00001
        ) {
            throw new BadInput(self::FROZEN, 'prepaymentAmount');
        }
    }

    /**
     * display_order_header() :460-466: every line re-priced from the (new) price list.
     * Prices given in this update are applied afterwards and win.
     */
    private function reprice(\Cart $cart): void
    {
        foreach ($cart->line_items as $line) {
            $line->price = get_kit_price(
                $line->stock_id,
                $cart->customer_currency,
                $cart->sales_type,
                $cart->price_factor,
                $cart->document_date
            );
        }
    }

    /**
     * The given lines replace the order's: with an id, that line updated
     * (update_cart_item(), cart_class.inc :412-420); without, a new line (addLine());
     * omitted, deleted — unless delivered (handle_delete_item(), sales_order_entry.php
     * :591-599).
     *
     * @param array<int, array<string, mixed>> $lines
     */
    private function replaceLines(\Cart $cart, array $lines, bool $recurring): void
    {
        if (count($lines) === 0) {
            throw new BadInput('An order needs at least one line.', 'lines');
        }
        $existing = [];
        foreach ($cart->line_items as $line) {
            $existing[(int) $line->id] = $line;
        }

        $kept = [];
        $added = [];
        foreach ($lines as $index => $given) {
            $field = 'lines.' . $index;
            if (!self::given($given, 'id')) {
                $added[$index] = $given;
                continue;
            }
            $id = IntKey::parse($given['id'], "$field.id");
            if (!isset($existing[$id])) {
                throw new BadInput("Line $id is not a line of this order.", "$field.id");
            }
            $line = $existing[$id];
            unset($existing[$id]);
            if (self::given($given, 'stockId') && strcasecmp((string) $given['stockId'], $line->stock_id) !== 0) {
                throw new BadInput("A line's item cannot change: remove the line and add another.", "$field.stockId");
            }
            $quantity = self::given($given, 'quantity') ? (float) $given['quantity'] : (float) $line->quantity;
            $price = self::given($given, 'unitPrice') ? (float) $given['unitPrice'] : (float) $line->price;
            $discount = self::given($given, 'discountPercent')
                ? (float) $given['discountPercent']
                : (float) $line->discount_percent * 100;
            $this->checkLine(
                $line->stock_id,
                $quantity,
                $price,
                $discount,
                $field,
                (float) $line->qty_done,
                $recurring
            );
            if (self::given($given, 'unitPrice')) {
                $this->warnIfBelowCost($cart, $line->stock_id, $price);
            }
            $line->quantity = $quantity;
            $line->qty_dispatched = $quantity;
            $line->price = $price;
            $line->discount_percent = $discount / 100;
            if (self::given($given, 'description') && $line->descr_editable) {
                $line->item_description = (string) $given['description'];
            }
            $kept[] = $line;
        }
        foreach ($existing as $id => $line) {
            if ($line->qty_done != 0) {
                throw new BadInput("Line $id has been delivered and cannot be removed.", 'lines');
            }
        }

        $cart->line_items = $kept;
        foreach ($added as $index => $given) {
            if (!self::given($given, 'stockId') || !self::given($given, 'quantity')) {
                throw new BadInput('A new line needs a stockId and a quantity.', "lines.$index.stockId");
            }
            $this->addLine($cart, $given, 'lines.' . $index);
        }
    }
}
