<?php

namespace FA\GraphQL\Tests\Integration\SalesOrder;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\FaRejected;
use FA\GraphQL\Error\Forbidden;
use FA\GraphQL\Error\NotFound;
use FA\GraphQL\Fa\Service\SalesOrderService;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Type\SalesOrder\SalesOrderType;

/**
 * salesOrderUpdate's rules, ported from sales/sales_order_entry.php and
 * sales_order_ui.inc (Release 2 spec section 4.4).
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SalesOrderUpdateTest extends SalesOrderTestCase
{
    private function version(int $orderNo): int
    {
        return (int) $this->orderRow($orderNo)['version'];
    }

    /**
     * @param array<string, mixed> $patch
     */
    private function update(int $orderNo, array $patch, ?int $version = null): void
    {
        $input = array_merge(['id' => $orderNo, 'version' => $version ?? $this->version($orderNo)], $patch);
        ServiceCall::run(function () use ($input): void {
            $this->service()->update($input);
        });
    }

    /**
     * @param array<string, mixed> $patch
     */
    private function refused(int $orderNo, array $patch, string $class, ?string $field = null): \Throwable
    {
        try {
            $this->update($orderNo, $patch);
            $this->fail('accepted');
        } catch (\Throwable $e) {
            $this->assertInstanceOf($class, $e, $e->getMessage());
            if ($field !== null) {
                $this->assertSame($field, $e->field(), $e->getMessage());
            }

            return $e;
        }
    }

    public function testAnUpdateChangesTheHeaderAndBumpsTheVersion(): void
    {
        $orderNo = $this->createOrder();
        $before = $this->version($orderNo);

        $this->update($orderNo, ['comments' => 'changed', 'customerRef' => 'PO-9', 'freight' => 3.0]);

        $order = $this->orderRow($orderNo);
        $this->assertSame('changed', $order['comments']);
        $this->assertSame('PO-9', $order['customer_ref']);
        $this->assertEquals(3, $order['freight_cost']);
        $this->assertSame($before + 1, (int) $order['version']);
        $this->assertCount(1, $this->lineRows($orderNo), 'lines not given: kept');
    }

    public function testAStaleVersionIsRefusedAndNothingChanges(): void
    {
        $orderNo = $this->createOrder();
        $read = $this->version($orderNo);
        $this->update($orderNo, ['comments' => 'first'], $read);

        try {
            $this->update($orderNo, ['comments' => 'second'], $read);
            $this->fail('accepted');
        } catch (FaRejected $e) {
            $this->assertSame(SalesOrderService::STALE, $e->getMessage());
        }
        $this->assertSame('first', $this->orderRow($orderNo)['comments']);
    }

    public function testAnUnknownOrderIsNotFound(): void
    {
        $this->expectException(NotFound::class);
        $input = ['id' => 999999, 'version' => 0, 'comments' => 'x'];
        ServiceCall::run(function () use ($input): void {
            $this->service()->update($input);
        });
    }

    public function testLinesReplaceTheSet(): void
    {
        $orderNo = $this->createOrder(['lines' => [
            ['stockId' => '101', 'quantity' => 2.0],
            ['stockId' => '103', 'quantity' => 1.0],
        ]]);
        [$first] = $this->lineIds($orderNo);

        $this->update($orderNo, ['lines' => [
            ['id' => $first, 'quantity' => 5.0, 'discountPercent' => 5.0],
            ['stockId' => '102', 'quantity' => 1.0],
        ]]);

        $lines = $this->lineRows($orderNo);
        $this->assertSame(['101', '102'], array_column($lines, 'stk_code'));
        $this->assertSame($first, (int) $lines[0]['id'], 'the kept line keeps its id');
        $this->assertEquals(5, $lines[0]['quantity']);
        $this->assertEquals(0.05, $lines[0]['discount_percent']);
    }

    public function testALineOfAnotherOrderIsBadInput(): void
    {
        $other = $this->createOrder();
        $orderNo = $this->createOrder();

        $this->refused(
            $orderNo,
            ['lines' => [['id' => $this->lineIds($other)[0], 'quantity' => 1.0]]],
            BadInput::class,
            'lines.0.id'
        );
    }

    public function testAnEmptyLineSetIsBadInput(): void
    {
        // update_sales_order() would build "id NOT IN ()" (sales_order_db.inc :164-170).
        $orderNo = $this->createOrder();

        $this->refused($orderNo, ['lines' => []], BadInput::class, 'lines');
    }

    public function testALinesItemCannotChange(): void
    {
        $orderNo = $this->createOrder();

        $this->refused(
            $orderNo,
            ['lines' => [['id' => $this->lineIds($orderNo)[0], 'stockId' => '102', 'quantity' => 1.0]]],
            BadInput::class,
            'lines.0.stockId'
        );
    }

    public function testANewLineNeedsAnItemAndAQuantity(): void
    {
        $orderNo = $this->createOrder();

        $this->refused($orderNo, ['lines' => [['quantity' => 1.0]]], BadInput::class, 'lines.0.stockId');
    }

    public function testAnotherPriceListRepricesTheLinesWithoutAGivenPrice(): void
    {
        $orderNo = $this->createOrder(['lines' => [
            ['stockId' => '101', 'quantity' => 1.0],
            ['stockId' => '103', 'quantity' => 1.0, 'unitPrice' => 7.0],
        ]]);
        [$first, $second] = $this->lineIds($orderNo);

        $this->update($orderNo, [
            'salesTypeId' => 2,
            'lines' => [['id' => $first], ['id' => $second, 'unitPrice' => 7.0]],
        ]);

        $expected = get_kit_price('101', 'USD', 2, get_sales_type(2)['factor'], sql2date($this->today()));
        $lines = $this->lineRows($orderNo);
        $this->assertEqualsWithDelta((float) $expected, (float) $lines[0]['unit_price'], 0.001);
        $this->assertEquals(7, $lines[1]['unit_price'], 'a price given in the update wins');
    }

    public function testADeliveredLineCannotBeRemoved(): void
    {
        $orderNo = $this->createOrder(['lines' => [
            ['stockId' => '101', 'quantity' => 2.0],
            ['stockId' => '103', 'quantity' => 1.0],
        ]]);
        [$first, $second] = $this->lineIds($orderNo);
        $this->deliver($orderNo, [$first => 1.0]);

        $this->refused($orderNo, ['lines' => [['id' => $second, 'quantity' => 1.0]]], BadInput::class, 'lines');
    }

    public function testAQuantityBelowWhatWasDeliveredIsRefused(): void
    {
        $orderNo = $this->createOrder();
        [$first] = $this->lineIds($orderNo);
        $this->deliver($orderNo, [$first => 2.0]);

        $this->refused(
            $orderNo,
            ['lines' => [['id' => $first, 'quantity' => 1.0]]],
            BadInput::class,
            'lines.0.quantity'
        );
    }

    /**
     * @dataProvider frozenFields
     */
    public function testTheHeaderFreezesOnceSomethingIsDelivered(string $field, $value): void
    {
        $orderNo = $this->createOrder();
        $this->deliver($orderNo, [$this->lineIds($orderNo)[0] => 1.0]);

        $this->refused($orderNo, [$field => $value], BadInput::class, $field);
    }

    public function frozenFields(): array
    {
        return [
            'customer' => ['customerId', 2],
            'branch' => ['branchId', 2],
            'price list' => ['salesTypeId', 2],
            'payment terms' => ['paymentTermsId', 1],
            'order date' => ['orderDate', new \DateTimeImmutable('+1 day')],
            'prepayment' => ['prepaymentAmount', 5.0],
        ];
    }

    public function testAStartedOrderStillTakesTheUnfrozenFields(): void
    {
        $orderNo = $this->createOrder();
        $this->deliver($orderNo, [$this->lineIds($orderNo)[0] => 1.0]);

        // Giving a frozen field its current value is not a change.
        $this->update($orderNo, ['deliveryAddress' => '2 Other Street', 'customerId' => 1, 'salesTypeId' => 1]);

        $this->assertSame('2 Other Street', $this->orderRow($orderNo)['delivery_address']);
    }

    public function testAPrepaidOrderWithAllocationsCannotBeEdited(): void
    {
        $orderNo = $this->createOrder(['paymentTermsId' => 5, 'prepaymentAmount' => 10.0]);
        $this->pdo()->prepare(
            'INSERT INTO 0_cust_allocations'
            . ' (person_id, amt, date_alloc, trans_no_from, trans_type_from, trans_no_to, trans_type_to)'
            . ' VALUES (1, 10, CURDATE(), 999999, 12, ?, 30)'
        )->execute([$orderNo]);

        $this->refused($orderNo, ['comments' => 'x'], FaRejected::class);
    }

    public function testAnotherUsersOrderNeedsEditOtherUsersTransactions(): void
    {
        global $security_areas;
        $orderNo = $this->createOrder();
        $this->pdo()->prepare('UPDATE 0_audit_trail SET user = 999 WHERE type = 30 AND trans_no = ?')
            ->execute([$orderNo]);
        // current_user::can_access() (includes/current_user.inc) looks the area's code
        // up in role_set; take SA_EDITOTHERSTRANS away from this session only.
        $user = $_SESSION['wa_current_user'];
        $user->role_set = array_values(array_diff($user->role_set, [$security_areas['SA_EDITOTHERSTRANS'][0]]));

        $this->refused($orderNo, ['comments' => 'x'], Forbidden::class);
    }

    public function testUpdateReturnsTheOrderWithItsNewVersion(): void
    {
        $orderNo = $this->createOrder();
        $type = $this->container->get(SalesOrderType::class);

        $rows = $type->resolveUpdate(null, ['input' => [[
            'id' => $orderNo,
            'version' => $this->version($orderNo),
            'comments' => 'via the Type',
        ]]], $this->container);

        $this->assertSame('via the Type', $rows[0]['comments']);
        $this->assertSame($this->version($orderNo), (int) $rows[0]['version']);
    }
}
