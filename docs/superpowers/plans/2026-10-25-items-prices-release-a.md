# Items and prices, Release A — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use cjp:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task, wave by wave per the Execution Schedule. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `priceQuote`, which FA's own `get_price()` answers, and let
`salesOrderUpdate` switch an existing line to another item in place. Expose the
order's currency, so the Panel can quote exactly what a switch charges.

**Architecture:** A new `PriceQuoteService` (`src/Fa/Service/`) chooses the list and
the currency, calls `get_price()`, and returns null instead of 0 when FA has no
price, using a guard that repeats `get_price()`'s row selection. A hand-written
`priceQuote` root field calls it under `SA_SALESORDER`. `SalesOrderService::replaceLines()`
gains `switchItem()`, which rebuilds the cart line as a `line_details` for the new
item with the same id. `SalesOrderType` gains a `currency` field.

**Tech Stack:** PHP 7.4–8.3, webonyx/graphql-php through saygoweb/anorm-graphql,
FrontAccounting 2.4 loaded in-process, PHPUnit 9.6 in the FrontAccounting CI image.

**Spec:** `docs/superpowers/specs/2026-10-25-items-prices-design.md` (§3 and §6 for
this plan; §4 and §5 are later releases, not this plan).

## Global Constraints

- Runs on upstream FrontAccounting `master` and the `cambell-prince` fork, PHP 7.4 and
  8.3: no PHP 8-only syntax (no `match`, no constructor promotion, no union types in
  signatures, no named arguments, no nullsafe operator).
- Never re-implement FA's pricing arithmetic: the price is always `get_price()`'s
  (quote) or `get_kit_price()`'s (order line) result.
- `price` is `null` when FA has no price, never 0. A real 0 price is `0.0`.
- Errors: unknown item, customer or list → `NotFound`; bad input → `BadInput` naming
  the field. Both are in `src/Error/`.
- Access: `priceQuote` needs `SA_SALESORDER`, through `Guard::require()`.
- PSR-12 (`composer cs:check`), PHPStan (`composer analyze`). Every new file starts with
  the namespace and class docblock, in the style of its neighbours.
- In `ApiSchema`, keep each fields array in alphabetical order (the class docblock
  says so).
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## How to run tests

Everything that touches FA runs in the FrontAccounting CI image. From the worktree
(`/home/cambell/src/sgw/frontaccounting/.claude/worktrees/graphql-items-prices`):

```bash
T() { /home/cambell/src/sgw/frontaccounting/docker/ci/plugin-test.sh --dataset demo \
  --setup 'composer install --no-interaction --no-progress' \
  --with sgw_sales=https://github.com/saygoweb/frontaccounting-module-sgw_sales.git@master \
  . -- sh -c "export FA_DB_PREFIX=0_ FA_GRAPHQL_URL=\"\${FA_URL%/}/modules/graphql/\"; sh tools/init.sh >/dev/null && vendor/bin/phpunit $*"; }
T --filter PriceQuoteServiceTest          # one class
T --testsuite unit                        # unit suite only
Q() { /home/cambell/src/sgw/frontaccounting/docker/ci/plugin-test.sh --dataset demo --no-activate \
  --setup 'composer install --no-interaction --no-progress' . -- composer run quality; }
Q                                          # lint, phpcs, phpstan
```

Run `composer run quality` only through `Q`: phpstan reads FrontAccounting from
`../../`, which is the FA tree only inside the container (on the host, a worktree's
`../../` is `.claude/`).

Full gates: the same command with `sh tools/ci.sh` as the test command (it is the CI
job). One docker run at a time: the machine thermally throttles. Check `uptime`
before each run and wait while the 1-minute load is 10 or more.

## Review Focus

1. **A line switch on a kit or foreign code** must be refused before the cart is
   touched. Otherwise `line_details` silently builds an invalid line (`valid` unset) and
   `update_sales_order()` writes an empty `stk_code`. Task 2 tests kit `501`.
2. **Resending a line's own code** (the Panel echoes every line's `stockId`) is not a
   switch: the line keeps its stored price, and `strcasecmp` stays the test, so a
   case-only difference is not a switch either. A real switch stores `stock_master`'s
   spelling. Task 2 tests resending the same code without `unitPrice`.
3. **A switched line that also changes quantity or discount**: the given ones apply,
   and the delivered floor (`checkLine`) uses the old line's `qty_done`. Task 2 tests
   quantity and discount on a switch.
4. **`priceQuote` with ids that are not whole numbers** (`customerId: "1 OR 1"`) is
   BAD_INPUT through `IntKey`, never SQL. Task 1 tests it.
5. **A foreign-currency customer with no price in its currency** falls back to the
   home-currency price converted. That price must equal `get_price()`'s; the guard
   counts the home-currency rows. Task 1 tests customer 2 (EUR).

## Execution Schedule

| Wave | Tasks | Model | Notes |
|------|-------|-------|-------|
| 1 | Task 1 | sonnet | `priceQuote` service, type and root field; complete code given |
| 2 | Task 2 | opus | line switch in `SalesOrderService`, `currency` on `SalesOrderType`; its parity test uses Task 1's service. Sequential: both tasks run the one CI container |
| CP1 | — | — | base..end of wave 2: code-review medium + spec check (spec §3.1–§3.4). Runs before wave 3, which touches docs and tests only |
| 3 | Task 3 | sonnet | HTTP test with the Panel's exact document, README |
| CP2 | — | — | base..end of wave 3: code-review medium + spec check (full §3, §6) |
| 4 | Controller | — | `sh tools/ci.sh` gates, push, PR, CI green, squash-merge (merge-when-green), tag v1.3.0 after asking the user |

---

### Task 1: `priceQuote`

**Files:**
- Create: `src/Fa/Service/PriceQuoteService.php`
- Create: `src/Type/Price/PriceQuoteType.php`
- Modify: `src/ApiSchema.php` (the `use` list, and one Query field placed alphabetically, after the `paymentTerms*` entries)
- Test: `tests/Integration/Pricing/PriceQuoteServiceTest.php` (new)
- Test: `tests/Unit/Type/PriceQuoteAreaTest.php` (new)
- Test: `tests/Unit/ApiSchemaTest.php` (one assertion)

**Interfaces:**
- Produces: `FA\GraphQL\Fa\Service\PriceQuoteService::quote(string $stockId, ?int $customerId = null, ?int $salesTypeId = null): array`. It returns `['stockId' => string, 'price' => ?float, 'currency' => string, 'salesTypeId' => int]`. It throws `NotFound` and `BadInput`. Task 2's test calls it.
- Produces: GraphQL `priceQuote(stockId: String!, customerId: ID, salesTypeId: ID): PriceQuote!` and `type PriceQuote { stockId: String!, price: Float, currency: String!, salesTypeId: ID! }`.

- [ ] **Step 1: Write the failing integration test**

`tests/Integration/Pricing/PriceQuoteServiceTest.php`:

```php
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
```

- [ ] **Step 2: Write the failing unit tests**

`tests/Unit/Type/PriceQuoteAreaTest.php`. The fake user is the one
`LookupAreasTest` uses:

```php
<?php

namespace FA\GraphQL\Tests\Unit\Type;

use DI\ContainerBuilder;
use FA\GraphQL\ApiSchema;
use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\Forbidden;
use GraphQL\GraphQL;
use PHPUnit\Framework\TestCase;

/**
 * priceQuote needs SA_SALESORDER, the area order entry prices a line under (Items
 * and prices spec §3.1), and checks its ids before FrontAccounting is touched.
 */
class PriceQuoteAreaTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SESSION['wa_current_user']);
    }

    private function signIn(array $areas): void
    {
        $_SESSION['wa_current_user'] = new class ($areas) {
            private array $areas;

            public function __construct(array $areas)
            {
                $this->areas = $areas;
            }

            // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
            public function logged_in(): bool
            {
                return true;
            }

            // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
            public function can_access(string $area): bool
            {
                return in_array($area, $this->areas, true);
            }
        };
    }

    private function run(string $query): array
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        $result = GraphQL::executeQuery(new ApiSchema($builder->build()), $query);

        return [$result->data, $result->errors];
    }

    public function testWithoutSalesOrdersItIsForbidden(): void
    {
        $this->signIn(['SA_SALESTRANSVIEW', 'SA_CUSTOMER']);

        [, $errors] = $this->run('{ priceQuote(stockId: "101") { price } }');

        $this->assertInstanceOf(Forbidden::class, $errors[0]->getPrevious());
    }

    public function testACustomerIdThatIsNotAWholeNumberIsBadInput(): void
    {
        $this->signIn(['SA_SALESORDER']);

        [, $errors] = $this->run('{ priceQuote(stockId: "101", customerId: "1 OR 1") { price } }');

        $this->assertInstanceOf(BadInput::class, $errors[0]->getPrevious());
        $this->assertSame('customerId', $errors[0]->getPrevious()->field());
    }
}
```

In `tests/Unit/ApiSchemaTest.php::testTheSurfaceIsWhatTheSpecSays`, add:

```php
        $this->assertStringContainsString(
            'priceQuote(stockId: String!, customerId: ID, salesTypeId: ID): PriceQuote!',
            $sdl
        );
        $this->assertStringContainsString('salesTypeId: ID!', $sdl);
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `T --filter 'PriceQuote|ApiSchemaTest'`
Expected: FAIL. The service and type classes do not exist, and the SDL lacks `priceQuote`.

- [ ] **Step 4: Write `PriceQuoteService`**

`src/Fa/Service/PriceQuoteService.php`:

```php
<?php

namespace FA\GraphQL\Fa\Service;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\NotFound;

/**
 * What FrontAccounting would charge for an item (Items and prices spec §3.1): the
 * price is get_price()'s (sales/includes/sales_db.inc:73-139), called as order entry
 * calls it, so the fallback to the base list times the list's factor, the home
 * currency conversion and the rounding are all FrontAccounting's.
 *
 * get_price() answers 0 both for a price of 0 and for no price at all; hasPrice()
 * tells them apart, so that a client sees null for "not priced".
 */
class PriceQuoteService
{
    /**
     * The customer's list and currency; a given list wins over the customer's; with
     * neither, the base list in the home currency.
     *
     * @return array{stockId: string, price: ?float, currency: string, salesTypeId: int}
     */
    public function quote(string $stockId, ?int $customerId = null, ?int $salesTypeId = null): array
    {
        FaIncludes::customers();
        $stockId = self::stockId($stockId);

        $currency = get_company_currency();
        $listId = null;
        if ($customerId !== null) {
            $customer = db_fetch(db_query(
                'SELECT sales_type, curr_code FROM ' . TB_PREF . 'debtors_master WHERE debtor_no = '
                . db_escape($customerId),
                'could not read the customer'
            ));
            if (!$customer) {
                throw new NotFound("There is no customer $customerId.");
            }
            $currency = (string) $customer['curr_code'];
            $listId = (int) $customer['sales_type'];
        }
        if ($salesTypeId !== null) {
            if (!get_sales_type($salesTypeId)) {
                throw new NotFound("There is no price list $salesTypeId.");
            }
            $listId = $salesTypeId;
        }
        if ($listId === null) {
            $listId = (int) get_base_sales_type();
            if ($listId <= 0) {
                throw new BadInput('No base price list is set.', 'salesTypeId');
            }
        }

        return [
            'stockId' => $stockId,
            'price' => self::hasPrice($stockId, $currency, $listId)
                ? (float) get_price($stockId, $currency, $listId, null, \Today())
                : null,
            'currency' => $currency,
            'salesTypeId' => $listId,
        ];
    }

    /**
     * The item's code as stock_master spells it. A kit or a foreign code has
     * item_codes rows but no stock_master row, and is not an item here.
     */
    private static function stockId(string $stockId): string
    {
        $row = db_fetch(db_query(
            'SELECT stock_id FROM ' . TB_PREF . 'stock_master WHERE stock_id = ' . db_escape($stockId),
            'could not read the item'
        ));
        if (!$row) {
            throw new NotFound("There is no item '$stockId'.");
        }

        return (string) $row['stock_id'];
    }

    /**
     * Whether get_price() finds a price rather than falling through to its 0. These
     * are its selection conditions, none of its arithmetic: one of its four lookups
     * hits (a row on the list or the base list, in the currency or the home
     * currency), or the item has no row in either currency and add_pct is not -1,
     * when FrontAccounting prices from cost (sales_db.inc:96-131).
     */
    private static function hasPrice(string $stockId, string $currency, int $listId): bool
    {
        $home = get_company_currency();
        $row = db_fetch_row(db_query(
            'SELECT COUNT(*), SUM(sales_type_id IN (' . db_escape($listId) . ', '
            . db_escape((int) get_base_sales_type()) . ')) FROM ' . TB_PREF . 'prices WHERE stock_id = '
            . db_escape($stockId) . ' AND curr_abrev IN (' . db_escape($currency) . ', ' . db_escape($home) . ')',
            'could not read the prices'
        ));
        if ((int) $row[1] > 0) {
            return true;
        }

        return (int) $row[0] === 0 && (int) get_company_pref('add_pct') !== -1;
    }
}
```

- [ ] **Step 5: Write `PriceQuoteType` and the root field**

`src/Type/Price/PriceQuoteType.php`:

```php
<?php

namespace FA\GraphQL\Type\Price;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * What FrontAccounting would charge for an item (Items and prices spec §3.1).
 */
class PriceQuoteType extends ObjectType
{
    public function __construct()
    {
        parent::__construct([
            'name' => 'PriceQuote',
            'fields' => [
                'stockId' => ['type' => Type::nonNull(Type::string())],
                'price' => [
                    'type' => Type::float(),
                    'description' => 'FrontAccounting\'s price; null when the item has no price on this list '
                        . 'or the base list.',
                ],
                'currency' => [
                    'type' => Type::nonNull(Type::string()),
                    'description' => 'The customer\'s currency, else the company\'s.',
                ],
                'salesTypeId' => [
                    'type' => Type::nonNull(Type::id()),
                    'description' => 'The price list quoted.',
                ],
            ],
        ]);
    }
}
```

In `src/ApiSchema.php`, add `use FA\GraphQL\Fa\Service\IntKey;`,
`use FA\GraphQL\Fa\Service\PriceQuoteService;` and
`use FA\GraphQL\Type\Price\PriceQuoteType;`, keeping the `use` list sorted. Then add
this Query entry alphabetically (after the last `paymentTerms…` entry, before the next
key):

```php
                    'priceQuote' => [
                        'type' => Type::nonNull($this->type(PriceQuoteType::class)),
                        'description' => "What FrontAccounting would charge for an item: get_price() on the "
                            . "customer's price list in its currency, or on the given list (which wins), or on "
                            . 'the base list. Needs SA_SALESORDER.',
                        'args' => [
                            'stockId' => ['type' => Type::nonNull(Type::string())],
                            'customerId' => ['type' => Type::id()],
                            'salesTypeId' => ['type' => Type::id()],
                        ],
                        'resolve' => function ($root, array $args) {
                            Guard::require('SA_SALESORDER');
                            $customerId = isset($args['customerId'])
                                ? IntKey::parse($args['customerId'], 'customerId')
                                : null;
                            $salesTypeId = isset($args['salesTypeId'])
                                ? IntKey::parse($args['salesTypeId'], 'salesTypeId')
                                : null;

                            return $this->type(PriceQuoteService::class)
                                ->quote($args['stockId'], $customerId, $salesTypeId);
                        },
                    ],
```

(`$this->type()` is the container lookup ApiSchema already uses for
`InvoiceMailer`.)

- [ ] **Step 6: Run the tests to verify they pass**

Run: `T --filter 'PriceQuote|ApiSchemaTest'`
Expected: PASS. If the unit test's `getPrevious()` is null because the error
formatter wraps differently, assert on `$errors[0]->getMessage()` containing
`SA_SALESORDER` (Forbidden) and `customerId` (BadInput) instead. Do not loosen the
integration tests.

Then run: `Q`. Expected: clean.

- [ ] **Step 7: Commit**

```bash
git add src/Fa/Service/PriceQuoteService.php src/Type/Price/PriceQuoteType.php src/ApiSchema.php \
  tests/Integration/Pricing/PriceQuoteServiceTest.php tests/Unit/Type/PriceQuoteAreaTest.php tests/Unit/ApiSchemaTest.php
git commit -m "priceQuote: FrontAccounting's get_price() for a customer, a list or the base list

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Switching a line's item; the order's currency

**Files:**
- Modify: `src/Fa/Service/SalesOrderService.php` (`replaceLines()` at the `stockId` check, about line 748; new private `switchItem()` below it)
- Modify: `src/Type/SalesOrder/SalesOrderType.php` (`fields()`; new `currencyOf()`)
- Test: `tests/Integration/SalesOrder/LineSwitchTest.php` (new)
- Test: `tests/Integration/SalesOrder/SalesOrderUpdateTest.php` (delete `testALinesItemCannotChange`)

**Interfaces:**
- Consumes: `PriceQuoteService::quote(string, ?int, ?int): array` (Task 1).
- Produces: `SalesOrder.currency: String!`. `salesOrderUpdate` accepts a changed `stockId` on a line with an `id`.

- [ ] **Step 1: Write the failing tests**

Delete `testALinesItemCannotChange` from `SalesOrderUpdateTest.php`.
`LineSwitchTest` replaces it.

`tests/Integration/SalesOrder/LineSwitchTest.php`:

```php
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
        $this->assertEqualsWithDelta(300.0, $quotes->quote('101', 1)['price'], 0.001, "the customer's own list differs");
    }

    public function testTheOrderReadsItsCustomersCurrency(): void
    {
        $orderNo = $this->createOrder();

        $this->assertSame('USD', SalesOrderType::currencyOf(1, $this->container));
        $this->assertNotNull($this->orderRow($orderNo));
    }
}
```

The `RecordingParticipant` setup comes from `tests/Integration/Extension/SalesOrderParticipantTest.php`.
The service builds its participants lazily, on first write, so `relaxed` set before
`update()` applies.

- [ ] **Step 2: Run them to verify they fail**

Run: `T --filter LineSwitchTest`
Expected: FAIL. Switches are refused with "A line's item cannot change", and
`currencyOf` is undefined.

- [ ] **Step 3: Implement the switch**

In `src/Fa/Service/SalesOrderService.php` `replaceLines()`, replace

```php
            if (self::given($given, 'stockId') && strcasecmp((string) $given['stockId'], $line->stock_id) !== 0) {
                throw new BadInput("A line's item cannot change: remove the line and add another.", "$field.stockId");
            }
```

with

```php
            if (self::given($given, 'stockId') && strcasecmp((string) $given['stockId'], $line->stock_id) !== 0) {
                $kept[] = $this->switchItem($cart, $line, $given, $field, $relaxed);
                continue;
            }
```

Then update the method's docblock: "with an id, that line updated (update_cart_item(),
cart_class.inc :412-420), or moved to another item (switchItem()); …". Add below
`replaceLines()`:

```php
    /**
     * A line moved to another item in place (Items and prices spec §3.2): rebuilt as a
     * line_details for the new item with the same id, which update_sales_order() writes
     * to the same row (sales_order_db.inc :186-198). Priced as a new line is (addLine())
     * unless a price is given; the description is the given one if the new item's is
     * editable, else the item's (line_details' constructor). A kit or foreign code would
     * expand into several lines, and a delivered line keeps its deliveries on the old
     * item, so both are refused, the latter unless the order is relaxed (a recurring one).
     *
     * @param array<string, mixed> $given a SalesOrderLineUpdateInput
     */
    private function switchItem(\Cart $cart, \line_details $line, array $given, string $field, bool $relaxed): \line_details
    {
        $code = (string) $given['stockId'];
        $quantity = self::given($given, 'quantity') ? (float) $given['quantity'] : (float) $line->quantity;
        $discount = self::given($given, 'discountPercent')
            ? (float) $given['discountPercent']
            : (float) $line->discount_percent * 100;
        $price = self::given($given, 'unitPrice') ? (float) $given['unitPrice'] : null;
        $this->checkLine($code, $quantity, $price, $discount, $field, (float) $line->qty_done, $relaxed);

        $item = db_fetch(db_query(
            'SELECT stock_id FROM ' . TB_PREF . 'stock_master WHERE stock_id = ' . db_escape($code),
            'could not read the item'
        ));
        if (!$item) {
            throw new BadInput('A line can only change to a stock item, not a kit.', "$field.stockId");
        }
        $stockId = (string) $item['stock_id'];
        if (!$relaxed && (float) $line->qty_done != 0) {
            throw new BadInput("Line {$line->id} has been delivered: its item cannot change.", "$field.stockId");
        }

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

        return new \line_details(
            $stockId,
            $quantity,
            $price,
            $discount / 100,
            $line->qty_done,
            get_unit_cost($stockId),
            self::given($given, 'description') ? (string) $given['description'] : null,
            $line->id,
            $line->src_no,
            $line->src_id
        );
    }
```

`checkLine()` refuses an unknown code ("There is no item"), a negative quantity, a
bad discount and the delivered floor, on the old line's `qty_done`. The
`stock_master` lookup then separates a kit or foreign code, which has `item_codes`
rows but no `stock_master` row.

If the signature line exceeds phpcs's line length, wrap the parameters one per line,
as `checkLine()` does.

- [ ] **Step 4: Add `currency` to `SalesOrderType`**

In `src/Type/SalesOrder/SalesOrderType.php` `fields()`, add before `lines`:

```php
            FieldBuilder::create('currency', Type::nonNull(Type::string()))
                ->setDescription("The order's currency: its customer's. FrontAccounting keeps none on the order "
                    . '(read_sales_order() takes the customer\'s).')
                ->setResolver(function (array $row, $args, $context): string {
                    return self::currencyOf((int) $row['customerId'], $context);
                })
                ->build(),
```

and the static method (next to `linesOf()`):

```php
    /**
     * A customer's currency, debtors_master.curr_code, which every one of its orders
     * is priced and invoiced in.
     *
     * @param mixed $context the container
     */
    public static function currencyOf(int $customerId, $context): string
    {
        $statement = $context->get(\PDO::class)->prepare(
            'SELECT curr_code FROM `' . CompanyContext::prefix() . 'debtors_master` WHERE debtor_no = ?'
        );
        $statement->execute([$customerId]);

        return (string) $statement->fetchColumn();
    }
```

with `use FA\GraphQL\Fa\CompanyContext;`. Check how `CompanyContext::prefix()` is used
in `src/Model/SalesOrderModel.php` and match it.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `T --filter 'LineSwitchTest|SalesOrderUpdateTest|SalesOrderParticipantTest|ApiSchemaTest'`
Expected: PASS. Then `Q`: clean. PHPStan may not
know `\line_details`. Check how existing code refers to FA classes (`\Cart`); if the
neon stubs FA, `\line_details` is covered the same way.

- [ ] **Step 6: Commit**

```bash
git add src/Fa/Service/SalesOrderService.php src/Type/SalesOrder/SalesOrderType.php \
  tests/Integration/SalesOrder/LineSwitchTest.php tests/Integration/SalesOrder/SalesOrderUpdateTest.php
git commit -m "salesOrderUpdate: switch a line to another item in place; SalesOrder.currency

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Over HTTP, and the README

**Files:**
- Create: `tests/Http/PriceQuoteEndpointTest.php`
- Modify: `README.md` (the "What it covers" table; the line "Not yet: … inventory maintenance"; a short "Pricing" section after the sales orders section, before deliveries)

**Interfaces:**
- Consumes: `priceQuote` (Task 1), `salesOrderUpdate` switch and `SalesOrder.currency` (Task 2).

- [ ] **Step 1: Write the HTTP test**

`tests/Http/PriceQuoteEndpointTest.php`. Use `GraphQLClient` as `LookupsTest` does:

```php
<?php

namespace FA\GraphQL\Tests\Http;

use PHPUnit\Framework\TestCase;

/**
 * priceQuote over the endpoint, with the Panel's own document (Items and prices
 * spec §3.1), as a role that only takes orders (SA_SALESORDER).
 */
class PriceQuoteEndpointTest extends TestCase
{
    use GraphQLClient;

    private const PANEL_DOCUMENT = 'query priceQuote($stockId: String!, $customerId: ID) '
        . '{ priceQuote(stockId: $stockId, customerId: $customerId) { stockId price currency } }';

    public function testThePanelsDocumentQuotesACustomer(): void
    {
        $token = $this->login('apiorders')['accessToken'];

        $response = $this->gql(self::PANEL_DOCUMENT, ['stockId' => '101', 'customerId' => '1'], $token);

        $this->assertArrayNotHasKey('errors', $response['body'], $response['raw']);
        $this->assertSame(
            ['stockId' => '101', 'price' => 300, 'currency' => 'USD'],
            $response['body']['data']['priceQuote']
        );
    }

    public function testAnUnpricedItemIsNull(): void
    {
        $token = $this->login('apiorders')['accessToken'];

        $response = $this->gql(self::PANEL_DOCUMENT, ['stockId' => '202', 'customerId' => '1'], $token);

        $this->assertNull($response['body']['data']['priceQuote']['price'], $response['raw']);
    }

    public function testAnUnknownItemIsNotFound(): void
    {
        $token = $this->login('apiorders')['accessToken'];

        $response = $this->gql(self::PANEL_DOCUMENT, ['stockId' => 'NOPE', 'customerId' => '1'], $token);

        $this->assertSame('NOT_FOUND', $response['body']['errors'][0]['extensions']['code'] ?? null, $response['raw']);
    }
}
```

JSON decodes `300.0` as int `300`; if the body has `300.0` as a float, use
`assertEquals` for the price and `assertSame` for the other two keys.

- [ ] **Step 2: Run it**

Run: `T --filter PriceQuoteEndpointTest`
Expected: PASS.

- [ ] **Step 3: README**

- In the "What it covers" table, add after the "Sales orders" row:

  `| Pricing | priceQuote(stockId, customerId, salesTypeId) — FrontAccounting's get_price() for a customer, a price list or the base list; price null when unpriced | — |`

  In the Sales orders row, add to the mutations cell: "an existing line may switch to
  another item, keeping its id".
- In the "Not yet:" line, change "inventory maintenance" to "inventory maintenance
  (items, price lists and prices: next)".
- Add a section `## Pricing`, after the sales orders section and before
  deliveries. Mirror the spec §3.1 table (list and currency by what is given), and
  include:
  - null versus 0;
  - `SA_SALESORDER`;
  - the line-switch rules from §3.2 (id kept; repriced from the order's list unless a
    price is given; kits refused; delivered lines only on recurring orders);
  - that a switch to a non-editable item replaces the line's description, so items
    clients switch lines to should have editable descriptions;
  - that `SalesOrder.salesTypeId` and `currency` are the list and currency a switch
    prices on, so `priceQuote(stockId, customerId, salesTypeId: <the order's>)` is what
    a switch will charge.

  Write it in the README's register: short declarative sentences and code in backticks.

- [ ] **Step 4: Commit**

```bash
git add tests/Http/PriceQuoteEndpointTest.php README.md
git commit -m "priceQuote over HTTP with the Panel's document; README: pricing and line switches

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Wave 4 (controller)

- [ ] `T` with `sh tools/ci.sh` as the command (full gates, sgw_sales' suite included): all pass.
- [ ] Push `feature/items-prices`, open the PR: title "Items and prices, Release A: priceQuote, line item switch, SalesOrder.currency". The body summarises spec §3, links the spec, and ends with the Claude Code attribution line.
- [ ] merge-when-green (squash).
- [ ] Ask the user before tagging `v1.3.0` and before any live check. Then report the PR, the version and the live `SA_SALESORDER` / `priceQuote(HGEN1)` result to the pricing session.
