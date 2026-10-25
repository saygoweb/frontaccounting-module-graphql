<?php

namespace FA\GraphQL\Tests\Integration\Pricing;

use FA\GraphQL\ApiSchema;
use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\NotFound;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Fa\Service\StockItemService;
use FA\GraphQL\Tests\Integration\SalesOrder\SalesOrderTestCase;
use FA\GraphQL\Tests\Support\FaTestRows;
use GraphQL\GraphQL;

/**
 * StockItemService against FrontAccounting's demo company, as apitest (Items and
 * prices spec §4.1). Category 4 'Services' is tax type 1, units 'hr', mb_flag 'D',
 * accounts 4010/5010/1510/5040/1530; 101 is an item and 501 a kit. Every item written
 * carries this test's prefix and is swept in tearDown. Each call goes through
 * ServiceCall::each, as the Type makes it, so the input is cleaned as FA's POST is.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class StockItemServiceTest extends SalesOrderTestCase
{
    private string $p;

    /** A temporary fixed-asset category, when a test made one. */
    private ?int $faCategory = null;

    /** A temporary item tax type, when a test made one. */
    private ?int $taxType = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p = FaTestRows::prefix();
    }

    protected function tearDown(): void
    {
        $pdo = FaTestRows::connect();
        FaTestRows::sweepItems($pdo, $this->p);
        if ($this->faCategory !== null) {
            $pdo->prepare('DELETE FROM 0_stock_category WHERE category_id = ?')->execute([$this->faCategory]);
            $this->faCategory = null;
        }
        if ($this->taxType !== null) {
            $pdo->prepare('DELETE FROM 0_item_tax_types WHERE id = ?')->execute([$this->taxType]);
            $this->taxType = null;
        }
        parent::tearDown();
    }

    private function items(): StockItemService
    {
        return $this->container->get(StockItemService::class);
    }

    /** @param array<string, mixed> $input */
    private function create(array $input): string
    {
        return ServiceCall::each([$input], function (array $input): string {
            return $this->items()->create($input);
        })[0];
    }

    /** @param array<string, mixed> $input */
    private function update(array $input): void
    {
        ServiceCall::each([$input], function (array $input): void {
            $this->items()->update($input);
        });
    }

    /** @return array<string, mixed> */
    private function input(string $suffix = 'NZ', array $overrides = []): array
    {
        return array_merge(
            ['stockId' => "{$this->p}-$suffix", 'description' => 'Domain .nz', 'categoryId' => 4],
            $overrides
        );
    }

    /** @return array<string, string|null>|null */
    private function row(string $sql, array $params): ?array
    {
        $statement = FaTestRows::connect()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : array_map(static function ($v): ?string {
            return $v === null ? null : (string) $v;
        }, $row);
    }

    private function rowCount(string $sql, array $params): int
    {
        $statement = FaTestRows::connect()->prepare($sql);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    /** @return array<string, string|null>|null */
    private function master(string $code): ?array
    {
        return $this->row('SELECT * FROM 0_stock_master WHERE stock_id = ?', [$code]);
    }

    /** @return array<string, string|null>|null */
    private function code(string $code): ?array
    {
        return $this->row('SELECT * FROM 0_item_codes WHERE item_code = ? AND stock_id = ?', [$code, $code]);
    }

    private function assertBadInput(string $field, string $message, callable $call): void
    {
        try {
            $call();
            $this->fail("expected BadInput on $field");
        } catch (BadInput $e) {
            $this->assertSame($field, $e->field(), $e->getMessage());
            $this->assertSame($message, $e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function graphql(string $document, array $variables): array
    {
        return GraphQL::executeQuery(
            $this->container->get(ApiSchema::class),
            $document,
            null,
            $this->container,
            $variables
        )->toArray(\GraphQL\Error\DebugFlag::INCLUDE_DEBUG_MESSAGE);
    }

    public function testApitestMayWriteItems(): void
    {
        $this->assertTrue($_SESSION['wa_current_user']->can_access('SA_ITEM'));
    }

    public function testANewItemTakesItsCategoryDefaultsAsThePageDoes(): void
    {
        $code = "{$this->p}-NZ";
        $this->assertSame($code, $this->create($this->input()));

        $row = $this->master($code);
        $this->assertNotNull($row);
        $this->assertSame('Domain .nz', $row['description']);
        $this->assertSame('4', $row['category_id']);
        $this->assertSame('1', $row['tax_type_id']);
        $this->assertSame('hr', $row['units']);
        $this->assertSame('D', $row['mb_flag']);
        $this->assertSame('4010', $row['sales_account']);
        $this->assertSame('5010', $row['cogs_account']);
        $this->assertSame('1510', $row['inventory_account']);
        $this->assertSame('5040', $row['adjustment_account']);
        $this->assertSame('1530', $row['wip_account']);
        $this->assertSame('1', $row['editable']);
        $this->assertSame('0', $row['no_sale']);
        $this->assertSame('0', $row['inactive']);

        $this->assertSame(1, $this->rowCount('SELECT COUNT(*) FROM 0_item_codes WHERE stock_id = ?', [$code]));
        $itemCode = $this->code($code);
        $this->assertNotNull($itemCode);
        $this->assertEquals(1, $itemCode['quantity']);
        $this->assertSame('0', $itemCode['is_foreign']);
        $this->assertSame(
            $this->rowCount('SELECT COUNT(*) FROM 0_locations', []),
            $this->rowCount('SELECT COUNT(*) FROM 0_loc_stock WHERE stock_id = ?', [$code])
        );
    }

    public function testTheOverridesWinOverTheCategory(): void
    {
        $pdo = FaTestRows::connect();
        $pdo->exec("INSERT INTO 0_item_tax_types (name, exempt) VALUES ('{$this->p} tax', 0)");
        $this->taxType = (int) $pdo->lastInsertId();
        $code = $this->create(
            $this->input('NZ', ['taxTypeId' => $this->taxType, 'units' => 'each', 'editable' => false])
        );

        $row = $this->master($code);
        $this->assertSame((string) $this->taxType, $row['tax_type_id']);
        $this->assertSame('each', $row['units']);
        $this->assertSame('0', $row['editable']);
    }

    public function testTheLongDescriptionIsStored(): void
    {
        $code = $this->create($this->input('NZ', ['longDescription' => 'A .nz domain, renewed yearly']));

        $this->assertSame('A .nz domain, renewed yearly', $this->master($code)['long_description']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public function badCodes(): array
    {
        $chars = 'The item code cannot contain any of the following characters -  & + OR a space OR quotes';
        $taken = 'This item code is already assigned to stock item or sale kit.';

        return [
            'empty' => ['', 'The item code cannot be empty'],
            'space' => ['A B', $chars],
            'quote' => ["A'B", $chars],
            'plus' => ['A+B', $chars],
            'ampersand' => ['A&B', $chars],
            'double quote' => ['A"B', $chars],
            'tab' => ["A\tB", $chars],
            'too long' => [str_repeat('x', 21), 'The item code cannot be longer than 20 characters.'],
            'an item' => ['101', $taken],
            'a kit' => ['501', $taken],
        ];
    }

    /**
     * @dataProvider badCodes
     */
    public function testABadCodeIsRefusedOnStockId(string $code, string $message): void
    {
        $this->assertBadInput('stockId', $message, function () use ($code): void {
            $this->create($this->input('NZ', ['stockId' => $code]));
        });
    }

    public function testAnEmptyDescriptionIsRefused(): void
    {
        $this->assertBadInput('description', 'The item name must be entered.', function (): void {
            $this->create($this->input('NZ', ['description' => '']));
        });
    }

    public function testAnUnknownCategoryIsRefused(): void
    {
        $this->assertBadInput('categoryId', "There is no item category '999'.", function (): void {
            $this->create($this->input('NZ', ['categoryId' => 999]));
        });
    }

    public function testAnUnknownTaxTypeIsRefused(): void
    {
        $this->assertBadInput('taxTypeId', "There is no tax type '999'.", function (): void {
            $this->create($this->input('NZ', ['taxTypeId' => 999]));
        });
    }

    public function testAnUnknownUnitIsRefused(): void
    {
        $this->assertBadInput('units', "There is no unit 'zz'.", function (): void {
            $this->create($this->input('NZ', ['units' => 'zz']));
        });
    }

    public function testAFixedAssetCategoryIsRefused(): void
    {
        $pdo = FaTestRows::connect();
        $pdo->prepare(
            "INSERT INTO 0_stock_category (description, dflt_tax_type, dflt_units, dflt_mb_flag, dflt_sales_act,
             dflt_cogs_act, dflt_inventory_act, dflt_adjustment_act, dflt_wip_act, dflt_dim1, dflt_dim2)
             VALUES (?, 1, 'each', 'F', '4010', '5010', '1510', '5040', '1530', 0, 0)"
        )->execute(["{$this->p} assets"]);
        $this->faCategory = (int) $pdo->lastInsertId();
        $id = $this->faCategory;

        $this->assertBadInput('categoryId', "Category '$id' is for fixed assets.", function () use ($id): void {
            $this->create($this->input('NZ', ['categoryId' => $id]));
        });
        $this->assertNull($this->master("{$this->p}-NZ"));
    }

    public function testInactiveOnCreateReachesBothTables(): void
    {
        $code = $this->create($this->input('NZ', ['inactive' => true]));

        $this->assertSame('1', $this->master($code)['inactive']);
        $this->assertSame('1', $this->code($code)['inactive']);
    }

    public function testABatchIsAtomicAndNamesTheFailingIndex(): void
    {
        $inputs = [
            $this->input('A'),
            $this->input('B'),
            $this->input('C', ['stockId' => '101']),
            $this->input('D'),
        ];
        try {
            ServiceCall::each($inputs, function (array $input): string {
                return $this->items()->create($input);
            });
            $this->fail('expected BadInput');
        } catch (BadInput $e) {
            $this->assertSame(2, $e->index());
            $this->assertSame('stockId', $e->field());
        }

        $like = ["{$this->p}%"];
        $this->assertSame(0, $this->rowCount('SELECT COUNT(*) FROM 0_stock_master WHERE stock_id LIKE ?', $like));
        $this->assertSame(0, $this->rowCount('SELECT COUNT(*) FROM 0_item_codes WHERE stock_id LIKE ?', $like));
        $this->assertSame(0, $this->rowCount('SELECT COUNT(*) FROM 0_loc_stock WHERE stock_id LIKE ?', $like));
    }

    public function testAnUpdateChangesOnlyWhatItIsGiven(): void
    {
        $code = $this->create($this->input('NZ', [
            'longDescription' => 'Long', 'units' => 'each', 'editable' => false,
        ]));
        $before = $this->master($code);

        $this->update(['id' => $code, 'description' => 'New']);

        $after = $this->master($code);
        $this->assertSame('New', $after['description']);
        $before['description'] = 'New';
        $this->assertSame($before, $after);
        $this->assertSame('New', $this->code($code)['description']);
    }

    public function testACategoryChangeKeepsTheAccountsAndTaxType(): void
    {
        $code = $this->create($this->input());
        $before = $this->master($code);

        $this->update(['id' => $code, 'categoryId' => 2]);

        $after = $this->master($code);
        $this->assertSame('2', $after['category_id']);
        $before['category_id'] = '2';
        $this->assertSame($before, $after);
        $this->assertSame('2', $this->code($code)['category_id']);
    }

    public function testInactiveOnUpdateReachesBothTables(): void
    {
        $code = $this->create($this->input());

        $this->update(['id' => $code, 'inactive' => true]);
        $this->assertSame('1', $this->master($code)['inactive']);
        $this->assertSame('1', $this->code($code)['inactive']);

        $this->update(['id' => $code, 'inactive' => false]);
        $this->assertSame('0', $this->master($code)['inactive']);
        $this->assertSame('0', $this->code($code)['inactive']);
    }

    public function testAnUpdateRefusesAnExplicitNull(): void
    {
        $code = $this->create($this->input());

        $this->assertBadInput('units', 'units cannot be null.', function () use ($code): void {
            $this->update(['id' => $code, 'units' => null]);
        });
    }

    public function testAnUpdateChecksItsReferences(): void
    {
        $code = $this->create($this->input());

        $this->assertBadInput('categoryId', "There is no item category '999'.", function () use ($code): void {
            $this->update(['id' => $code, 'categoryId' => 999]);
        });
        $this->assertBadInput('description', 'The item name must be entered.', function () use ($code): void {
            $this->update(['id' => $code, 'description' => '']);
        });
    }

    public function testTheCodeLimitCountsCharactersNotBytes(): void
    {
        // The session charset decides what a character is; make it, and the connection, UTF-8.
        $_SESSION['language']->encoding = 'utf-8';
        db_query('SET NAMES utf8mb4', 'could not set the connection charset');
        $max = str_repeat('Ä', 11);
        try {
            $this->assertSame($max, $this->create($this->input('NZ', ['stockId' => $max])));
        } finally {
            $pdo = FaTestRows::connect();
            $pdo->prepare('DELETE FROM 0_item_codes WHERE stock_id = ?')->execute([$max]);
            $pdo->prepare('DELETE FROM 0_stock_master WHERE stock_id = ?')->execute([$max]);
        }

        $this->assertBadInput(
            'stockId',
            'The item code cannot be longer than 20 characters.',
            function (): void {
                $this->create($this->input('NZ', ['stockId' => str_repeat('Ä', 21)]));
            }
        );
    }

    public function testAnItemWhoseStoredUnitIsGoneCanStillBeEdited(): void
    {
        $code = $this->create($this->input());
        FaTestRows::connect()->prepare('UPDATE 0_stock_master SET units = ? WHERE stock_id = ?')
            ->execute(['gone', $code]);

        $this->update(['id' => $code, 'description' => 'Renamed']);

        $row = $this->master($code);
        $this->assertSame('Renamed', $row['description']);
        $this->assertSame('gone', $row['units']);
    }

    public function testUpdatingAnUnknownIdOrAKitIsNotFound(): void
    {
        foreach (['NOPE', '501'] as $id) {
            try {
                $this->update(['id' => $id, 'description' => 'New']);
                $this->fail("expected NotFound for $id");
            } catch (NotFound $e) {
                $this->assertSame("Stock item id '$id' not found", $e->getMessage());
            }
        }
    }

    public function testTheSchemaCreatesAndUpdatesItems(): void
    {
        $code = "{$this->p}-NZ";
        $created = $this->graphql(
            'mutation ($input: [StockItemCreateInput!]!) {
                stockItemCreate(input: $input) { id description editable }
            }',
            ['input' => [
                ['stockId' => $code, 'description' => 'Domain .nz', 'categoryId' => '4', 'units' => 'each'],
            ]]
        );
        $this->assertArrayNotHasKey('errors', $created, json_encode($created));
        $this->assertSame(
            [['id' => $code, 'description' => 'Domain .nz', 'editable' => true]],
            $created['data']['stockItemCreate']
        );

        $updated = $this->graphql(
            'mutation ($input: [StockItemUpdateInput!]!) { stockItemUpdate(input: $input) { id description units } }',
            ['input' => [['id' => $code, 'description' => 'Domain .co.nz']]]
        );
        $this->assertArrayNotHasKey('errors', $updated, json_encode($updated));
        $this->assertSame(
            [['id' => $code, 'description' => 'Domain .co.nz', 'units' => 'each']],
            $updated['data']['stockItemUpdate']
        );
    }

    public function testWritingItemsNeedsSaItem(): void
    {
        global $security_areas;
        $user = $_SESSION['wa_current_user'];
        $user->role_set = array_values(array_diff($user->role_set, [$security_areas['SA_ITEM'][0]]));
        $this->assertFalse($user->can_access('SA_ITEM'));

        $result = $this->graphql(
            'mutation ($input: [StockItemCreateInput!]!) { stockItemCreate(input: $input) { id } }',
            ['input' => [['stockId' => "{$this->p}-NZ", 'description' => 'Domain .nz', 'categoryId' => '4']]]
        );
        $this->assertSame('FORBIDDEN', $result['errors'][0]['extensions']['code'] ?? null, json_encode($result));
        // The area's refusal, not FaModelType's "no write path" one.
        $this->assertStringNotContainsString('no write path', $result['errors'][0]['message']);
        $this->assertNull($this->master("{$this->p}-NZ"));

        $update = $this->graphql(
            'mutation ($input: [StockItemUpdateInput!]!) { stockItemUpdate(input: $input) { id } }',
            ['input' => [['id' => '101', 'description' => 'Changed']]]
        );
        $this->assertSame('FORBIDDEN', $update['errors'][0]['extensions']['code'] ?? null, json_encode($update));
        $this->assertStringNotContainsString('no write path', $update['errors'][0]['message']);
    }
}
