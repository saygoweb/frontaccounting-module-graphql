<?php

namespace FA\GraphQL\Tests\Integration\SalesOrder;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\FaRejected;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Fa\Warnings;
use FA\GraphQL\Type\SalesOrder\SalesOrderType;

/**
 * salesOrderCreate's rules, each ported from sales/sales_order_entry.php and
 * sales/includes/ui/sales_order_ui.inc (Release 2 spec section 4.4).
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SalesOrderCreateTest extends SalesOrderTestCase
{
    /**
     * @param array<string, mixed> $overrides
     */
    private function refused(array $overrides, string $class, ?string $field = null): void
    {
        try {
            $this->track($this->createOrderOrFail($overrides));
            $this->fail('accepted');
        } catch (BadInput $e) {
            $this->assertSame(BadInput::class, $class, $e->getMessage());
            $this->assertSame($field, $e->field(), $e->getMessage());
        } catch (FaRejected $e) {
            $this->assertSame(FaRejected::class, $class, $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createOrderOrFail(array $overrides): int
    {
        $input = $this->orderInput($overrides);

        return ServiceCall::run(function () use ($input): int {
            return $this->service()->create($input);
        });
    }

    public function testAnOrderTakesItsDefaultsFromTheCustomerAndBranch(): void
    {
        global $SysPrefs;
        $orderNo = $this->createOrder();

        $order = $this->orderRow($orderNo);
        $this->assertSame('1', $order['debtor_no']);
        $this->assertSame('1', $order['branch_code']);
        $this->assertSame('1', $order['order_type'], 'the customer\'s price list (Retail)');
        $this->assertSame('DEF', $order['from_stk_loc'], 'the branch\'s location');
        $this->assertSame('1', $order['ship_via'], 'the branch\'s shipper');
        $this->assertSame('3', $order['payment_terms']);
        $this->assertSame($this->today(), $order['ord_date']);
        $this->assertSame(
            date2sql(add_days(sql2date($this->today()), $SysPrefs->default_delivery_required_by())),
            $order['delivery_date'],
            'the order date plus the company\'s delivery lead time'
        );
        $this->assertNotSame('', $order['reference']);
        global $Refs;
        $this->assertSame(1, (int) $Refs->is_valid($order['reference'], ST_SALESORDER));

        $lines = $this->lineRows($orderNo);
        $this->assertCount(1, $lines);
        $this->assertSame('101', $lines[0]['stk_code']);
        $this->assertEquals(2, $lines[0]['quantity']);
        $this->assertEquals(300, $lines[0]['unit_price'], 'Retail USD price of 101');
        $this->assertEquals(0, $lines[0]['discount_percent'], 'the customer\'s discount');
    }

    public function testGivenHeaderFieldsWin(): void
    {
        $orderNo = $this->createOrder([
            'salesTypeId' => 2,
            'shipperId' => 1,
            'locationId' => 'DEF',
            'deliveryDate' => new \DateTimeImmutable('+10 days'),
            'freight' => 12.5,
            'customerRef' => 'PO-77',
            'comments' => 'Test order',
            'phone' => '555-0100',
            'reference' => null,
        ]);

        $order = $this->orderRow($orderNo);
        $this->assertSame('2', $order['order_type']);
        $this->assertEquals(12.5, $order['freight_cost']);
        $this->assertSame('PO-77', $order['customer_ref']);
        $this->assertSame('Test order', $order['comments']);
        $this->assertSame('555-0100', $order['contact_phone']);
        $this->assertSame((new \DateTimeImmutable('+10 days'))->format('Y-m-d'), $order['delivery_date']);
    }

    public function testAGivenPriceAndDiscountAreKeptAndTheDiscountIsStoredAsAFraction(): void
    {
        $orderNo = $this->createOrder([
            'lines' => [['stockId' => '101', 'quantity' => 1.0, 'unitPrice' => 123.45, 'discountPercent' => 10.0]],
        ]);

        $line = $this->lineRows($orderNo)[0];
        $this->assertEquals(123.45, $line['unit_price']);
        $this->assertEquals(0.1, $line['discount_percent']);
    }

    public function testAKitIsExpandedIntoItsComponentsAtItsPrice(): void
    {
        // 501 "iPhone Pack" = 102 + 103; it has no price of its own, so the kit is
        // priced as its components' sum (get_kit_price, sales_db.inc:141-167): 250 + 50.
        $orderNo = $this->createOrder(['lines' => [['stockId' => '501', 'quantity' => 1.0]]]);

        $lines = $this->lineRows($orderNo);
        $this->assertSame(['102', '103'], array_column($lines, 'stk_code'));
        $this->assertEqualsWithDelta(300, array_sum(array_map(function (array $l): float {
            return $l['unit_price'] * $l['quantity'];
        }, $lines)), 0.01);
    }

    public function testAnOrderNeedsALine(): void
    {
        $this->refused(['lines' => []], BadInput::class, 'lines');
    }

    public function testAnUnknownCustomerIsBadInput(): void
    {
        $this->refused(['customerId' => 999999], BadInput::class, 'customerId');
    }

    /**
     * @dataProvider notWholeNumbers
     */
    public function testAReferenceThatIsNotAWholeNumberIsBadInput(string $field, string $value): void
    {
        // Release 2 spec section 2.1 (Checkpoint B review M-1): MySQL would read "1 x" as 1.
        $this->refused([$field => $value], BadInput::class, $field);
    }

    public function notWholeNumbers(): array
    {
        return [
            'customer' => ['customerId', '1 x'],
            'branch' => ['branchId', '1.0'],
            'price list' => ['salesTypeId', '1 x'],
            'payment terms' => ['paymentTermsId', ' 3'],
            'shipper' => ['shipperId', '1e0'],
        ];
    }

    /**
     * @dataProvider unknownReferences
     */
    public function testAnUnknownReferenceIsBadInput(string $field, $value): void
    {
        $this->refused([$field => $value], BadInput::class, $field);
    }

    public function unknownReferences(): array
    {
        return [
            'price list' => ['salesTypeId', 999],
            'payment terms' => ['paymentTermsId', 999],
            'shipper' => ['shipperId', 999],
            'location' => ['locationId', 'NOLOC'],
        ];
    }

    public function testAnotherCustomersBranchIsBadInput(): void
    {
        $this->refused(['branchId' => 2], BadInput::class, 'branchId');
    }

    public function testAnUnknownItemIsBadInput(): void
    {
        $this->refused(
            ['lines' => [['stockId' => 'NO-SUCH-ITEM', 'quantity' => 1.0]]],
            BadInput::class,
            'lines.0.stockId'
        );
    }

    /**
     * @dataProvider badLines
     */
    public function testLineRulesFromCheckItemData(array $line, string $field): void
    {
        $this->refused(['lines' => [$line]], BadInput::class, $field);
    }

    public function badLines(): array
    {
        return [
            'negative quantity' => [['stockId' => '101', 'quantity' => -1.0], 'lines.0.quantity'],
            'discount over 100' => [
                ['stockId' => '101', 'quantity' => 1.0, 'discountPercent' => 101.0],
                'lines.0.discountPercent',
            ],
            'negative discount' => [
                ['stockId' => '101', 'quantity' => 1.0, 'discountPercent' => -1.0],
                'lines.0.discountPercent',
            ],
            'negative price, stock item' => [
                ['stockId' => '101', 'quantity' => 1.0, 'unitPrice' => -5.0],
                'lines.0.unitPrice',
            ],
        ];
    }

    public function testCreditTermsNeedSomeoneToDeliverTo(): void
    {
        $this->refused(['deliverTo' => 'x'], BadInput::class, 'deliverTo');
    }

    public function testCreditTermsNeedADeliveryAddress(): void
    {
        $this->refused(['deliveryAddress' => 'y'], BadInput::class, 'deliveryAddress');
    }

    public function testTheDeliveryDateCannotBeBeforeTheOrder(): void
    {
        $this->refused(['deliveryDate' => new \DateTimeImmutable('-1 day')], BadInput::class, 'deliveryDate');
    }

    public function testNegativeFreightIsBadInput(): void
    {
        $this->refused(['freight' => -1.0], BadInput::class, 'freight');
    }

    public function testPrepaidTermsNeedAPrepaymentWithinTheTotal(): void
    {
        $this->refused(['paymentTermsId' => 5], BadInput::class, 'prepaymentAmount');
        $this->refused(['paymentTermsId' => 5, 'prepaymentAmount' => 1000000.0], BadInput::class, 'prepaymentAmount');

        $orderNo = $this->createOrder(['paymentTermsId' => 5, 'prepaymentAmount' => 10.0]);
        $this->assertEquals(10, $this->orderRow($orderNo)['prep_amount']);
    }

    public function testCashTermsTakeThePointOfSaleLocationAndSkipTheDeliveryChecks(): void
    {
        // copy_to_cart() applies delivery details only for credit terms
        // (sales_order_entry.php:296-305); can_process() checks them only then (:402).
        $orderNo = $this->createOrder(['paymentTermsId' => 4, 'deliverTo' => 'x', 'deliveryAddress' => 'y']);

        $order = $this->orderRow($orderNo);
        $this->assertSame('4', $order['payment_terms']);
        $this->assertSame('DEF', $order['from_stk_loc'], 'the point of sale\'s location (0_sales_pos row 1)');
        $this->assertSame($order['ord_date'], $order['delivery_date']);
    }

    public function testADateNoFiscalYearCoversIsBadInput(): void
    {
        $this->refused(['orderDate' => new \DateTimeImmutable('1999-01-04')], BadInput::class, 'orderDate');
    }

    public function testAForeignCurrencyNeedsAnExchangeRateForTheDate(): void
    {
        // Customer 2 pays in EUR; the demo's only EUR rate is dated 2021-05-07. Fiscal
        // 2021 exists (closed), so only the rate is missing on 2021-01-04.
        $this->refused([
            'customerId' => 2,
            'branchId' => 2,
            'orderDate' => new \DateTimeImmutable('2021-01-04'),
            'deliveryDate' => new \DateTimeImmutable('2021-01-05'),
        ], BadInput::class, 'orderDate');
    }

    public function testAnInvalidReferenceIsBadInput(): void
    {
        $this->refused(['reference' => '!!'], BadInput::class, 'reference');
    }

    public function testAReferenceInUseIsBadInput(): void
    {
        $first = $this->createOrder();
        $reference = $this->orderRow($first)['reference'];

        $this->refused(['reference' => $reference], BadInput::class, 'reference');
    }

    public function testAPriceBelowCostIsAWarningNotARefusal(): void
    {
        // check_item_data() warns, and places the order (sales_order_entry.php:555-571).
        // 101's standard cost in the demo data is above 0.01.
        $orderNo = $this->createOrder(['lines' => [['stockId' => '101', 'quantity' => 1.0, 'unitPrice' => 0.01]]]);

        $this->assertNotNull($this->orderRow($orderNo));
        $this->assertNotEmpty(array_filter(Warnings::all(), function (string $w): bool {
            return stripos($w, 'below Standard Cost') !== false;
        }), json_encode(Warnings::all()));
    }

    /**
     * Release 2 spec section 3.1: a batch is one transaction. The second input fails
     * after the first was written; nothing of the first survives, the error names
     * index 1, and a later write in the same process still commits (the transaction
     * level was reset).
     */
    public function testARefusalMidBatchRollsTheWholeBatchBack(): void
    {
        $marker = 'batch-' . uniqid();
        $type = $this->container->get(SalesOrderType::class);
        try {
            $type->resolveCreate(null, ['input' => [
                $this->orderInput(['customerRef' => $marker]),
                $this->orderInput(['customerRef' => $marker, 'lines' => []]),
            ]], $this->container);
            $this->fail('accepted');
        } catch (BadInput $e) {
            $this->assertSame(1, $e->index());
            $this->assertSame('lines', $e->field());
        }

        $count = $this->pdo()->prepare('SELECT COUNT(*) FROM 0_sales_orders WHERE customer_ref = ?');
        $count->execute([$marker]);
        $this->assertSame(0, (int) $count->fetchColumn());
        $this->assertSame(0, (int) ($GLOBALS['transaction_level'] ?? 0));

        $rows = $type->resolveCreate(
            null,
            ['input' => [$this->orderInput(['customerRef' => $marker])]],
            $this->container
        );
        $this->track((int) $rows[0]['id']);
        $count->execute([$marker]);
        $this->assertSame(1, (int) $count->fetchColumn(), 'visible on another connection: committed');
    }

    public function testResolveCreateReturnsTheOrderAsTheTypeReadsIt(): void
    {
        $type = $this->container->get(SalesOrderType::class);
        $rows = $type->resolveCreate(null, ['input' => [$this->orderInput()]], $this->container);
        $this->track((int) $rows[0]['id']);

        $this->assertSame(1, (int) $rows[0]['customerId']);
        $this->assertSame(30, (int) $rows[0]['transType']);
        $this->assertInstanceOf(\DateTimeInterface::class, $rows[0]['orderDate']);
        $lines = SalesOrderType::linesOf((int) $rows[0]['id'], $this->container);
        $this->assertCount(1, $lines);
        $this->assertSame('101', $lines[0]['stockId']);
        $this->assertEquals(0.0, $lines[0]['qtyDelivered']);
    }

    /**
     * The ledger's ruling (Release 2 spec section 2.1, Checkpoint B review M-3): a
     * role that may take orders (SA_SALESORDER) but not view sales transactions
     * (SA_SALESTRANSVIEW) still gets the order it created back, with its lines —
     * and still may not list orders.
     */
    public function testARoleThatMayOnlyTakeOrdersGetsItsCreatedOrderBack(): void
    {
        global $security_areas;
        $user = $_SESSION['wa_current_user'];
        $user->role_set = array_values(array_diff($user->role_set, [$security_areas['SA_SALESTRANSVIEW'][0]]));
        $this->assertFalse($user->can_access('SA_SALESTRANSVIEW'));
        $this->assertTrue($user->can_access('SA_SALESORDER'));

        $input = $this->orderInput();
        $input['orderDate'] = $this->today();
        $result = \GraphQL\GraphQL::executeQuery(
            $this->container->get(\FA\GraphQL\ApiSchema::class),
            'mutation ($input: [SalesOrderCreateInput!]!) {
                salesOrderCreate(input: $input) { id customerId lines { stockId quantity } }
            }',
            null,
            $this->container,
            ['input' => [$input]]
        )->toArray(\GraphQL\Error\DebugFlag::INCLUDE_DEBUG_MESSAGE);
        if (isset($result['data']['salesOrderCreate'][0]['id'])) {
            $this->track((int) $result['data']['salesOrderCreate'][0]['id']);
        }

        $this->assertArrayNotHasKey('errors', $result, json_encode($result));
        $order = $result['data']['salesOrderCreate'][0];
        $this->assertSame('1', $order['customerId']);
        $this->assertSame([['stockId' => '101', 'quantity' => 2.0]], $order['lines']);

        $list = \GraphQL\GraphQL::executeQuery(
            $this->container->get(\FA\GraphQL\ApiSchema::class),
            '{ salesOrderList { id } }',
            null,
            $this->container
        )->toArray();
        $this->assertSame('FORBIDDEN', $list['errors'][0]['extensions']['code'] ?? null, json_encode($list));
    }

    /**
     * qtyDelivered is qty_sent (spec section 4.4); a delivery made with
     * FrontAccounting's own Cart raises it. Also exercises the test support Task 8
     * builds on: deliver(), and purgeOrder() removing the delivery with the order.
     */
    public function testADeliveryShowsAsQtyDelivered(): void
    {
        $orderNo = $this->createOrder(['lines' => [
            ['stockId' => '101', 'quantity' => 3.0],
            ['stockId' => '103', 'quantity' => 1.0],
        ]]);
        [$first, $second] = $this->lineIds($orderNo);

        $delivery = $this->deliver($orderNo, [$first => 2.0]);
        $this->assertGreaterThan(0, $delivery);

        $lines = SalesOrderType::linesOf($orderNo, $this->container);
        $this->assertSame([2.0, 0.0], [(float) $lines[0]['qtyDelivered'], (float) $lines[1]['qtyDelivered']]);
        $this->assertSame([(string) $first, (string) $second], array_map('strval', array_column($lines, 'id')));
    }

    /**
     * get_customer_details_to_order() (sales_order_ui.inc :81-82): a customer whose
     * credit status disallows invoices is on hold, and the page offers no Place Order
     * button. The demo's credit statuses 3 and 4 disallow invoices; customer 1 is put
     * on 3 for the test and given its own status back, pass or fail.
     */
    public function testAnOnHoldCustomerIsRefused(): void
    {
        $pdo = $this->pdo();
        $status = (string) $pdo->query('SELECT credit_status FROM 0_debtors_master WHERE debtor_no = 1')->fetchColumn();
        $this->assertSame('1', (string) $pdo->query(
            'SELECT dissallow_invoices FROM 0_credit_status WHERE id = 3'
        )->fetchColumn(), 'the demo\'s credit status 3 disallows invoices');
        $pdo->exec('UPDATE 0_debtors_master SET credit_status = 3 WHERE debtor_no = 1');
        try {
            $this->track($this->createOrderOrFail([]));
            $this->fail('an on-hold customer\'s order was accepted');
        } catch (FaRejected $e) {
            $message = 'The selected customer account is currently on hold. '
                . 'Please contact the credit control personnel to discuss.';
            $this->assertSame($message, $e->getMessage());
            $this->assertSame([$message], $e->getExtensions()['messages'] ?? null);
        } finally {
            $pdo->prepare('UPDATE 0_debtors_master SET credit_status = ? WHERE debtor_no = 1')->execute([$status]);
        }
        $this->assertSame(
            $status,
            (string) $pdo->query('SELECT credit_status FROM 0_debtors_master WHERE debtor_no = 1')->fetchColumn()
        );
    }

    /**
     * can_process() (sales_order_entry.php :446-451): cash terms need a cash account
     * for the point of sale. The demo's only cash account (bank account 2, type 3) is
     * made a current account for the test and given its type back, pass or fail.
     */
    public function testCashTermsWithoutACashAccountAreRefused(): void
    {
        $pdo = $this->pdo();
        $cash = $pdo->query('SELECT id FROM 0_bank_accounts WHERE account_type = 3')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertNotEmpty($cash, 'the demo has a cash account');
        $ids = implode(', ', array_map('intval', $cash));
        $pdo->exec("UPDATE 0_bank_accounts SET account_type = 0 WHERE id IN ($ids)");
        try {
            $this->track($this->createOrderOrFail(['paymentTermsId' => 4]));
            $this->fail('a cash order with no cash account was accepted');
        } catch (FaRejected $e) {
            $message = 'You need to define a cash account for your Sales Point.';
            $this->assertSame($message, $e->getMessage());
            $this->assertSame([$message], $e->getExtensions()['messages'] ?? null);
        } finally {
            $pdo->exec("UPDATE 0_bank_accounts SET account_type = 3 WHERE id IN ($ids)");
        }
        $this->assertSame(
            count($cash),
            (int) $pdo->query(
                "SELECT COUNT(*) FROM 0_bank_accounts WHERE account_type = 3 AND id IN ($ids)"
            )->fetchColumn()
        );
    }
}
