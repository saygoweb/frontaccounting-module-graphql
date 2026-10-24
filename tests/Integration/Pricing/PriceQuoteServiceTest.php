<?php

namespace FA\GraphQL\Tests\Integration\Pricing;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\NotFound;
use FA\GraphQL\Fa\Service\PriceQuoteService;
use FA\GraphQL\Tests\Integration\SalesOrder\SalesOrderTestCase;
use FA\GraphQL\Tests\Support\FaTestRows;

/**
 * priceQuote against FrontAccounting's demo company (Items and prices spec §3.1):
 * Retail (1, factor 1, the base list) and Wholesale (2, factor 0.7); USD prices on
 * Retail for 101 (300), 102 (250) and 103 (50); 202 and 301 unpriced; 501 a kit;
 * customer 1 USD on Retail, customer 2 EUR on Retail; add_pct -1.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class PriceQuoteServiceTest extends SalesOrderTestCase
{
    /** @var int[] prices rows this test added */
    private array $priceIds = [];

    protected function tearDown(): void
    {
        $pdo = FaTestRows::connect();
        foreach ($this->priceIds as $id) {
            $pdo->prepare('DELETE FROM 0_prices WHERE id = ?')->execute([$id]);
        }
        $this->priceIds = [];
        parent::tearDown();
    }

    private function addPrice(string $stockId, int $salesTypeId, string $currency, float $price): void
    {
        $pdo = FaTestRows::connect();
        $pdo->prepare('INSERT INTO 0_prices (stock_id, sales_type_id, curr_abrev, price) VALUES (?, ?, ?, ?)')
            ->execute([$stockId, $salesTypeId, $currency, $price]);
        $this->priceIds[] = (int) $pdo->lastInsertId();
    }

    private function quotes(): PriceQuoteService
    {
        return $this->container->get(PriceQuoteService::class);
    }

    public function testACustomerIsQuotedOnItsOwnListInItsCurrency(): void
    {
        $this->assertSame(
            ['stockId' => '101', 'price' => 300.0, 'currency' => 'USD', 'salesTypeId' => 1],
            $this->quotes()->quote('101', 1)
        );
    }

    public function testAListWithNoPriceFallsBackToTheBaseTimesItsFactor(): void
    {
        $quote = $this->quotes()->quote('101', null, 2);

        $this->assertEqualsWithDelta(210.0, $quote['price'], 0.001);
        $this->assertSame(2, $quote['salesTypeId']);
        $this->assertSame('USD', $quote['currency']);
    }

    public function testAPriceOnTheListWinsOverTheFallback(): void
    {
        $this->addPrice('103', 2, 'USD', 40.0);

        $this->assertSame(40.0, $this->quotes()->quote('103', null, 2)['price']);
    }

    public function testAGivenListWinsOverTheCustomersInTheCustomersCurrency(): void
    {
        $quote = $this->quotes()->quote('101', 1, 2);

        $this->assertEqualsWithDelta(210.0, $quote['price'], 0.001);
        $this->assertSame(2, $quote['salesTypeId']);
        $this->assertSame('USD', $quote['currency']);
    }

    public function testNeitherIdQuotesTheBaseListInTheHomeCurrency(): void
    {
        $this->assertSame(
            ['stockId' => '101', 'price' => 300.0, 'currency' => 'USD', 'salesTypeId' => 1],
            $this->quotes()->quote('101')
        );
    }

    public function testAForeignCurrencyCustomerGetsFasConvertedHomePrice(): void
    {
        $quote = $this->quotes()->quote('101', 2);

        $this->assertSame('EUR', $quote['currency']);
        $this->assertNotNull($quote['price']);
        $this->assertEqualsWithDelta((float) get_price('101', 'EUR', 1, null, \Today()), $quote['price'], 0.001);
    }

    public function testAnUnpricedItemIsNullNotZero(): void
    {
        $this->assertNull($this->quotes()->quote('202', 1)['price']);
    }

    public function testAZeroPriceIsZero(): void
    {
        $this->addPrice('301', 1, 'USD', 0.0);

        $this->assertSame(0.0, $this->quotes()->quote('301', 1)['price']);
    }

    public function testWithAddPctAnUnpricedItemIsPricedFromCost(): void
    {
        $GLOBALS['SysPrefs']->prefs['add_pct'] = '10';

        $quote = $this->quotes()->quote('202', 1);

        $this->assertNotNull($quote['price']);
        $this->assertEqualsWithDelta((float) get_price('202', 'USD', 1, null, \Today()), $quote['price'], 0.001);
    }

    public function testNoBaseListIsBadInputWhenNoListIsNamed(): void
    {
        $GLOBALS['SysPrefs']->prefs['base_sales'] = '0';

        $this->expectException(BadInput::class);
        $this->quotes()->quote('101');
    }

    /**
     * @return array<string, array{0: string, 1: ?int, 2: ?int}>
     */
    public function unknowns(): array
    {
        return [
            'item' => ['NOPE', null, null],
            'kit' => ['501', null, null],
            'customer' => ['101', 999999, null],
            'list' => ['101', null, 999999],
        ];
    }

    /**
     * @dataProvider unknowns
     */
    public function testUnknownsAreNotFound(string $stockId, ?int $customerId, ?int $salesTypeId): void
    {
        $this->expectException(NotFound::class);
        $this->quotes()->quote($stockId, $customerId, $salesTypeId);
    }
}
