<?php

namespace FA\GraphQL\Tests\Integration\SalesOrder;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Fa\Service\PriceQuoteService;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Tests\Support\Extension\FakeExtension;
use FA\GraphQL\Tests\Support\Extension\FakeHooks;
use FA\GraphQL\Tests\Support\Extension\RecordingParticipant;
use FA\GraphQL\Type\SalesOrder\SalesOrderType;

/**
 * A line sent with its id and another stockId is switched in place (Items and prices
 * spec §3.2). Demo data: Retail (1) prices 101 at 300, 103 at 50; Wholesale (2) is
 * factor 0.7; 103 and 301 are not editable, 202 is; 501 is a kit; customer 1 is USD on
 * Retail.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class LineSwitchTest extends SalesOrderTestCase
{
    private RecordingParticipant $participant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->participant = new RecordingParticipant();
        $GLOBALS['Hooks']['fake_participant'] = new FakeHooks([
            new FakeExtension('fake_participant', ['participants' => [$this->participant]]),
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     */
    private function update(int $orderNo, array $lines): void
    {
        $input = ['id' => $orderNo, 'version' => (int) $this->orderRow($orderNo)['version'], 'lines' => $lines];
        ServiceCall::run(function () use ($input): void {
            $this->service()->update($input);
        });
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     */
    private function refused(int $orderNo, array $lines, string $field): BadInput
    {
        try {
            $this->update($orderNo, $lines);
            $this->fail('accepted');
        } catch (BadInput $e) {
            $this->assertSame($field, $e->field(), $e->getMessage());

            return $e;
        }
    }

    public function testASwitchKeepsTheIdAndIsRepricedFromTheList(): void
    {
        $orderNo = $this->createOrder(['lines' => [['stockId' => '103', 'quantity' => 1.0]]]);
        [$id] = $this->lineIds($orderNo);

        $this->update($orderNo, [['id' => $id, 'stockId' => '101', 'quantity' => 1.0]]);

        $line = $this->lineRows($orderNo)[0];
        $this->assertSame($id, (int) $line['id']);
        $this->assertSame('101', $line['stk_code']);
        $this->assertEquals(300, $line['unit_price']);
        $this->assertSame('iPad Air 2 16GB', $line['description'], 'a non-editable item brings its own');
    }

    public function testASwitchWithAPriceKeepsThatPrice(): void
    {
        $orderNo = $this->createOrder(['lines' => [['stockId' => '103', 'quantity' => 1.0]]]);
        [$id] = $this->lineIds($orderNo);

        $this->update($orderNo, [['id' => $id, 'stockId' => '101', 'quantity' => 1.0, 'unitPrice' => 12.5]]);

        $this->assertEquals(12.5, $this->lineRows($orderNo)[0]['unit_price']);
    }

    public function testASwitchToAnEditableItemKeepsTheGivenDescription(): void
    {
        $orderNo = $this->createOrder(['lines' => [['stockId' => '103', 'quantity' => 1.0]]]);
        [$id] = $this->lineIds($orderNo);

        $this->update($orderNo, [[
            'id' => $id, 'stockId' => '202', 'quantity' => 1.0, 'unitPrice' => 10.0,
            'description' => 'Domain Registration - example.nz',
        ]]);

        $this->assertSame('Domain Registration - example.nz', $this->lineRows($orderNo)[0]['description']);
    }

    public function testASwitchTakesTheGivenQuantityAndDiscount(): void
    {
        $orderNo = $this->createOrder(['lines' => [['stockId' => '103', 'quantity' => 1.0]]]);
        [$id] = $this->lineIds($orderNo);

        $this->update($orderNo, [['id' => $id, 'stockId' => '101', 'quantity' => 3.0, 'discountPercent' => 10.0]]);

        $line = $this->lineRows($orderNo)[0];
        $this->assertEquals(3, $line['quantity']);
        $this->assertEquals(0.1, $line['discount_percent']);
    }

    public function testResendingTheSameCodeIsNotASwitch(): void
    {
        // A switch would reprice the line to 50; resending its own code keeps 7.
        $orderNo = $this->createOrder(['lines' => [['stockId' => '103', 'quantity' => 1.0, 'unitPrice' => 7.0]]]);
        [$id] = $this->lineIds($orderNo);

        $this->update($orderNo, [['id' => $id, 'stockId' => '103', 'quantity' => 1.0]]);

        $this->assertEquals(7, $this->lineRows($orderNo)[0]['unit_price'], 'kept, not repriced');
    }

    public function testUnchangedLinesKeepTheirPriceWithOrWithoutUnitPrice(): void
    {
        $orderNo = $this->createOrder(['lines' => [
            ['stockId' => '101', 'quantity' => 1.0, 'unitPrice' => 7.0],
            ['stockId' => '103', 'quantity' => 1.0, 'unitPrice' => 8.0],
        ]]);
        [$first, $second] = $this->lineIds($orderNo);

        $this->update($orderNo, [
            ['id' => $first, 'stockId' => '101', 'quantity' => 1.0],
            ['id' => $second, 'stockId' => '103', 'quantity' => 1.0, 'unitPrice' => 8.0],
            ['stockId' => '101', 'quantity' => 1.0],
        ]);

        $this->assertSame(['7', '8', '300'], array_map(static function (array $line): string {
            return (string) (float) $line['unit_price'];
        }, $this->lineRows($orderNo)), 'a new line is priced from the list');
    }

    public function testASwitchToAKitIsRefused(): void
    {
        $orderNo = $this->createOrder(['lines' => [['stockId' => '103', 'quantity' => 1.0]]]);
        [$id] = $this->lineIds($orderNo);

        $this->refused($orderNo, [['id' => $id, 'stockId' => '501', 'quantity' => 1.0]], 'lines.0.stockId');
        $this->assertSame('103', $this->lineRows($orderNo)[0]['stk_code']);
    }

    public function testASwitchToAnUnknownItemIsRefused(): void
    {
        $orderNo = $this->createOrder(['lines' => [['stockId' => '103', 'quantity' => 1.0]]]);
        [$id] = $this->lineIds($orderNo);

        $this->refused($orderNo, [['id' => $id, 'stockId' => 'NOPE', 'quantity' => 1.0]], 'lines.0.stockId');
    }

    public function testADeliveredLineCannotSwitch(): void
    {
        $orderNo = $this->createOrder(['lines' => [['stockId' => '103', 'quantity' => 2.0]]]);
        [$id] = $this->lineIds($orderNo);
        $this->deliver($orderNo, [$id => 1.0]);

        $this->refused($orderNo, [['id' => $id, 'stockId' => '101', 'quantity' => 2.0]], 'lines.0.stockId');
    }

    public function testADeliveredLineOfARelaxedOrderSwitches(): void
    {
        $orderNo = $this->createOrder(['lines' => [['stockId' => '103', 'quantity' => 2.0]]]);
        [$id] = $this->lineIds($orderNo);
        $this->deliver($orderNo, [$id => 1.0]);
        $this->participant->relaxed = true;

        $this->update($orderNo, [['id' => $id, 'stockId' => '101', 'quantity' => 2.0]]);

        $line = $this->lineRows($orderNo)[0];
        $this->assertSame('101', $line['stk_code']);
        $this->assertEquals(1, $line['qty_sent'], 'what was delivered stays delivered');
    }

    /**
     * Spec §3.3: a switch is priced on the ORDER's list, which the Panel quotes with
     * priceQuote(customerId, salesTypeId: <the order's>).
     */
    public function testASwitchOnAnOrderOfAnotherListMatchesTheQuoteForThatList(): void
    {
        $orderNo = $this->createOrder([
            'salesTypeId' => 2,
            'lines' => [['stockId' => '103', 'quantity' => 1.0]],
        ]);
        [$id] = $this->lineIds($orderNo);

        $this->update($orderNo, [['id' => $id, 'stockId' => '101', 'quantity' => 1.0]]);

        $quotes = $this->container->get(PriceQuoteService::class);
        $charged = (float) $this->lineRows($orderNo)[0]['unit_price'];
        $this->assertEqualsWithDelta(210.0, $charged, 0.001);
        $this->assertEqualsWithDelta($quotes->quote('101', 1, 2)['price'], $charged, 0.001);
        $this->assertEqualsWithDelta(
            300.0,
            $quotes->quote('101', 1)['price'],
            0.001,
            "the customer's own list differs"
        );
    }

    public function testTheOrderReadsItsCustomersCurrency(): void
    {
        $orderNo = $this->createOrder();

        $this->assertSame('USD', SalesOrderType::currencyOf(1, $this->container));
        $this->assertNotNull($this->orderRow($orderNo));
    }
}
