<?php

namespace FA\GraphQL\Tests\Integration\Pricing;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\NotFound;
use FA\GraphQL\Fa\Service\FaIncludes;
use FA\GraphQL\Fa\Service\PriceQuoteService;
use FA\GraphQL\Fa\Service\PriceService;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Tests\Integration\SalesOrder\SalesOrderTestCase;
use FA\GraphQL\Tests\Support\FaTestRows;

/**
 * priceSet, priceDelete and their reads, against FrontAccounting's demo company
 * (Items and prices spec section 4.3). Items are made with FrontAccounting's own
 * add_item, in category 4, under a prefix swept in tearDown; item 102's list 2 rows
 * are removed in tearDown.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class PriceServiceTest extends SalesOrderTestCase
{
    private const PREFIX = 'ZPRT';

    private string $code = self::PREFIX . '1';

    protected function setUp(): void
    {
        parent::setUp();
        FaIncludes::items();
        FaTestRows::sweepItems(FaTestRows::connect(), self::PREFIX);
        $this->pdo()->exec("DELETE FROM 0_prices WHERE stock_id = '102' AND sales_type_id = 2");
        \add_item(
            $this->code,
            'Price test item',
            '',
            4,
            1,
            'hr',
            'D',
            '4010',
            '1510',
            '5010',
            '5040',
            '1530',
            0,
            0,
            0,
            0,
            0
        );
    }

    protected function tearDown(): void
    {
        $pdo = FaTestRows::connect();
        FaTestRows::sweepItems($pdo, self::PREFIX);
        $pdo->exec("DELETE FROM 0_prices WHERE stock_id = '102' AND sales_type_id = 2");
        parent::tearDown();
    }

    private function prices(): PriceService
    {
        return $this->container->get(PriceService::class);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function set(array $input): int
    {
        return ServiceCall::run(function () use ($input): int {
            return $this->prices()->set($input);
        });
    }

    /**
     * @param array<string, mixed> $input
     */
    private function remove(array $input): int
    {
        return ServiceCall::run(function () use ($input): int {
            $id = $this->prices()->find($input);
            $this->prices()->removeById($id);

            return $id;
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(string $code): array
    {
        $statement = $this->pdo()->prepare('SELECT * FROM 0_prices WHERE stock_id = ? ORDER BY id');
        $statement->execute([$code]);

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * @return array<string, mixed>
     */
    private function key(string $code, $list = '2', string $currency = 'USD'): array
    {
        return ['stockId' => $code, 'salesTypeId' => $list, 'currency' => $currency];
    }

    /**
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    private function graphql(string $query, array $variables = []): array
    {
        return \GraphQL\GraphQL::executeQuery(
            $this->container->get(\FA\GraphQL\ApiSchema::class),
            $query,
            null,
            $this->container,
            $variables
        )->toArray(\GraphQL\Error\DebugFlag::INCLUDE_DEBUG_MESSAGE);
    }

    public function testSetInsertsThenUpdatesTheSameRow(): void
    {
        $id = $this->set($this->key($this->code, '1') + ['price' => 12.5]);
        $rows = $this->rows($this->code);
        $this->assertCount(1, $rows);
        $this->assertSame($id, (int) $rows[0]['id']);
        $this->assertEquals(12.5, $rows[0]['price']);

        $again = $this->set($this->key($this->code, '1') + ['price' => 15]);
        $rows = $this->rows($this->code);
        $this->assertCount(1, $rows);
        $this->assertSame($id, $again);
        $this->assertEquals(15, $rows[0]['price']);
    }

    public function testTheKeyIsMatchedByCaseAndPadding(): void
    {
        $id = $this->set($this->key($this->code, '1') + ['price' => 10]);

        $this->assertSame($id, $this->set($this->key(strtolower($this->code), '1') + ['price' => 11]));
        $this->assertSame($id, $this->set($this->key($this->code . ' ', '1') + ['price' => 12]));

        $rows = $this->rows($this->code);
        $this->assertCount(1, $rows);
        $this->assertSame($this->code, $rows[0]['stock_id']);
        $this->assertEquals(12, $rows[0]['price']);
    }

    /**
     * @dataProvider badInputs
     * @param array<string, mixed> $override
     */
    public function testBadInputNamesTheField(array $override, string $field, string $message): void
    {
        try {
            $this->set($override + $this->key($this->code, '1') + ['price' => 5]);
            $this->fail('expected BadInput');
        } catch (BadInput $e) {
            $this->assertSame($field, $e->field());
            $this->assertSame($message, $e->getMessage());
        }
        $this->assertSame([], $this->rows($this->code));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: string}>
     */
    public function badInputs(): array
    {
        return [
            'unknown item' => [['stockId' => 'NOPE'], 'stockId', "There is no item 'NOPE'."],
            'kit' => [['stockId' => '501'], 'stockId', "There is no item '501'."],
            'unknown list' => [['salesTypeId' => '999'], 'salesTypeId', "There is no sales type '999'."],
            'bad list key' => [['salesTypeId' => 'x'], 'salesTypeId', 'salesTypeId must be a positive whole number.'],
            'unknown currency' => [['currency' => 'ZZZ'], 'currency', "There is no currency 'ZZZ'."],
            'negative price' => [['price' => -1], 'price', 'The price entered must be numeric.'],
        ];
    }

    public function testAPriceIsQuotedAfterItIsSet(): void
    {
        $this->set($this->key($this->code, '2') + ['price' => 99]);

        $quote = $this->container->get(PriceQuoteService::class)->quote($this->code, null, 2);
        $this->assertSame(99.0, $quote['price']);
    }

    public function testRemoveDeletesTheRowAndAMissingKeyIsNotFound(): void
    {
        $id = $this->set($this->key($this->code, '2') + ['price' => 99]);

        $this->assertSame($id, $this->remove($this->key($this->code)));
        $this->assertSame([], $this->rows($this->code));

        try {
            $this->remove($this->key($this->code));
            $this->fail('expected NotFound');
        } catch (NotFound $e) {
            $this->assertSame("There is no price for '{$this->code}' on list 2 in USD.", $e->getMessage());
        }
    }

    public function testADeleteBatchWithAMissingKeyIsAtomic(): void
    {
        $this->set($this->key($this->code, '2') + ['price' => 99]);

        $result = $this->graphql(
            'mutation ($input: [PriceKeyInput!]!) { priceDelete(input: $input) { id } }',
            ['input' => [$this->key($this->code), $this->key($this->code, '2', 'EUR')]]
        );

        $this->assertSame('NOT_FOUND', $result['errors'][0]['extensions']['code'] ?? null, json_encode($result));
        $this->assertSame(1, $result['errors'][0]['extensions']['index'] ?? null, json_encode($result));
        $this->assertCount(1, $this->rows($this->code));
    }

    public function testALastItemMissingFromADeleteBatchNamesItsIndex(): void
    {
        $this->set($this->key($this->code, '1') + ['price' => 5]);
        $this->set($this->key($this->code, '2') + ['price' => 6]);

        $result = $this->graphql(
            'mutation ($input: [PriceKeyInput!]!) { priceDelete(input: $input) { id } }',
            ['input' => [
                $this->key($this->code, '1'),
                $this->key($this->code, '2'),
                $this->key($this->code, '2', 'EUR'),
            ]]
        );

        $this->assertSame('NOT_FOUND', $result['errors'][0]['extensions']['code'] ?? null, json_encode($result));
        $this->assertSame(2, $result['errors'][0]['extensions']['index'] ?? null, json_encode($result));
        $this->assertCount(2, $this->rows($this->code));
    }

    public function testPricesFilterBySalesTypeId(): void
    {
        $this->set($this->key($this->code, '1') + ['price' => 5]);
        $this->set($this->key($this->code, '2') + ['price' => 6]);

        $list = $this->graphql(
            'query ($q: MangoInput) { priceList(query: $q) { salesTypeId price } priceCount(query: $q) }',
            ['q' => ['selector' => json_encode(['stockId' => $this->code, 'salesTypeId' => 2])]]
        );

        $this->assertArrayNotHasKey('errors', $list, json_encode($list));
        $this->assertSame([['salesTypeId' => '2', 'price' => 6.0]], $list['data']['priceList']);
        $this->assertSame(1, $list['data']['priceCount']);
    }

    public function testDeletingWithoutSalesPriceAccessIsForbidden(): void
    {
        $id = $this->set($this->key($this->code) + ['price' => 1]);
        global $security_areas;
        $user = $_SESSION['wa_current_user'];
        $user->role_set = array_values(array_diff($user->role_set, [$security_areas['SA_SALESPRICE'][0]]));
        $this->assertFalse($user->can_access('SA_SALESPRICE'));

        $result = $this->graphql(
            'mutation ($input: [PriceKeyInput!]!) { priceDelete(input: $input) { id } }',
            ['input' => [$this->key($this->code)]]
        );

        $this->assertSame('FORBIDDEN', $result['errors'][0]['extensions']['code'] ?? null, json_encode($result));
        $this->assertSame([$id], array_map('intval', array_column($this->rows($this->code), 'id')));
    }

    public function testSetAndDeleteThroughTheSchemaAndReadThemBack(): void
    {
        $set = $this->graphql(
            'mutation ($input: [PriceSetInput!]!) {
                priceSet(input: $input) { id stockId salesTypeId currency price }
            }',
            ['input' => [
                $this->key($this->code, '2') + ['price' => 20],
                $this->key('102', '2') + ['price' => 30],
            ]]
        );
        $this->assertArrayNotHasKey('errors', $set, json_encode($set));
        $rows = $set['data']['priceSet'];
        $this->assertSame([$this->code, '102'], array_column($rows, 'stockId'));
        $this->assertSame([20.0, 30.0], array_column($rows, 'price'));
        $this->assertSame(['2', '2'], array_column($rows, 'salesTypeId'));
        $this->assertSame(['USD', 'USD'], array_column($rows, 'currency'));

        $list = $this->graphql(
            'query ($q: MangoInput) { priceList(query: $q) { price } priceCount(query: $q) }',
            ['q' => ['selector' => json_encode(['stockId' => $this->code])]]
        );
        $this->assertArrayNotHasKey('errors', $list, json_encode($list));
        $this->assertSame([['price' => 20.0]], $list['data']['priceList']);
        $this->assertSame(1, $list['data']['priceCount']);

        $deleted = $this->graphql(
            'mutation ($input: [PriceKeyInput!]!) { priceDelete(input: $input) { id stockId price } }',
            ['input' => [$this->key($this->code)]]
        );
        $this->assertArrayNotHasKey('errors', $deleted, json_encode($deleted));
        $this->assertSame($rows[0]['id'], $deleted['data']['priceDelete'][0]['id']);
        $this->assertSame(20.0, $deleted['data']['priceDelete'][0]['price']);
        $this->assertSame([], $this->rows($this->code));
    }

    public function testWithoutSalesPriceAccessIsForbidden(): void
    {
        global $security_areas;
        $user = $_SESSION['wa_current_user'];
        $user->role_set = array_values(array_diff($user->role_set, [$security_areas['SA_SALESPRICE'][0]]));
        $this->assertFalse($user->can_access('SA_SALESPRICE'));

        $set = $this->graphql(
            'mutation ($input: [PriceSetInput!]!) { priceSet(input: $input) { id } }',
            ['input' => [$this->key($this->code) + ['price' => 1]]]
        );
        $this->assertSame('FORBIDDEN', $set['errors'][0]['extensions']['code'] ?? null, json_encode($set));
        $this->assertSame([], $this->rows($this->code));

        $list = $this->graphql('{ priceList { id } }');
        $this->assertSame('FORBIDDEN', $list['errors'][0]['extensions']['code'] ?? null, json_encode($list));
    }
}
