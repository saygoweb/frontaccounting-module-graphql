# Items and prices, Release B — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use cjp:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task, wave by wave per the Execution Schedule. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the Panel write FrontAccounting's items, price lists and prices:
`stockItemCreate/Update`, `salesTypeCreate/Update` with `salesTypeList.isBase`, and
`priceList/priceCount/priceSet/priceDelete`. Pin `customerUpdate(salesTypeId)`.

**Architecture:** The same shape as customers. Each write goes through a service in
`src/Fa/Service/` that calls FA's own functions the way FA's page does. The service
runs inside `ServiceCall::each` (one transaction per batch), and the rows are then
read back through the Type.
- `StockItem` and `SalesType` become writable generated entities, with no delete. Their
  Types route create and update to the services.
- `Price` is a new read-only generated entity over `prices`.
- `priceSet` and `priceDelete` are hand-written root mutations, keyed by
  (stockId, salesTypeId, currency).

**Tech Stack:** PHP 7.4–8.3, webonyx/graphql-php through saygoweb/anorm-graphql
(`bin/generate`), FrontAccounting 2.4 loaded in-process, PHPUnit 9.6 in the
FrontAccounting CI image.

**Spec:** `docs/superpowers/specs/2026-10-25-items-prices-design.md`. For this plan
read §4 (Release B), the Release B part of §7 and the Release B access note in §6.
§3 is Release A and is already shipped. §5 is Release C and is not part of this plan.

## Global Constraints

- **PHP versions:** the code runs on upstream FrontAccounting `master` and on the
  `cambell-prince` fork, under PHP 7.4 and 8.3. So no PHP 8-only syntax: no `match`,
  no constructor promotion, no union types in signatures, no named arguments, no
  nullsafe operator.
- **Writes go through FA:** every write calls FA's own function: `add_item`,
  `update_item`, `update_record_status`, `add_sales_type`, `update_sales_type`,
  `add_item_price`, `update_item_price`, `delete_item_price`. Never write the table
  with Anorm or with raw SQL.
- **Messages:** where FA's page has a message for a refusal, use that message.
- **Errors:** a bad input field is `BadInput($message, $field)`. An unknown id or key
  being updated or deleted is `NotFound`. Both live in `src/Error/`.
- **Access areas:**
  - items: `SA_ITEM`;
  - price lists: `SA_SALESTYPES`;
  - prices: `SA_SALESPRICE`;
  - `stockItemList` and `salesTypeList` stay on `SA_SALESORDER`.
- **Batches and text:** a batch is one `ServiceCall::each`, which is atomic and names
  the failing item's index. Input strings arrive already cleaned by
  `FaText::cleanInput` (html-escaped, as FA's POST is).
- **Lint and style:**
  - PSR-12 (`composer cs:check`) and PHPStan (`composer analyze`).
  - Every new file starts with the namespace and a class docblock, in the style of
    its neighbours.
  - In `ApiSchema`, keep each fields array in alphabetical order.
- **Commits:** every commit message ends with
  `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## How to run tests

Everything that touches FA runs in the FrontAccounting CI image. Run these from the
task's worktree (`$W`). The main one is
`/home/cambell/src/sgw/frontaccounting/.claude/worktrees/graphql-items-prices-b`.

```bash
T() { /home/cambell/src/sgw/frontaccounting/docker/ci/plugin-test.sh --dataset demo \
  --setup 'composer install --no-interaction --no-progress' \
  --with sgw_sales=https://github.com/saygoweb/frontaccounting-module-sgw_sales.git@master \
  . -- sh -c "export FA_DB_PREFIX=0_ FA_GRAPHQL_URL=\"\${FA_URL%/}/modules/graphql/\"; sh tools/init.sh >/dev/null && vendor/bin/phpunit $*"; }
T --filter StockItemServiceTest           # one class
T --testsuite unit                        # unit suite only
Q() { /home/cambell/src/sgw/frontaccounting/docker/ci/plugin-test.sh --dataset demo --no-activate \
  --setup 'composer install --no-interaction --no-progress' . -- composer run quality; }
Q                                          # lint, phpcs, phpstan
```

- **Two filters:** a filter that names two classes must be quoted twice, for example
  `T --filter "'StockItemServiceTest|ApiSchemaTest'"`.
- **Run quality only through `Q`:** phpstan reads FA from `../../`, and that path is
  the FA tree only inside the container.
- **`bin/generate`:** it runs on the host (`php` 8.3 is installed, and `vendor/` is
  already in the worktree).
- **Load:** check `uptime` before each docker run. While the 1-minute load is 10 or
  more, wait.

## Facts about FA you will need

These are quoted from the FA tree at `/home/cambell/src/sgw/frontaccounting`.

- **`add_item(...)` and `update_item(...)`**
  (inventory/includes/db/items_db.inc:14, :58):
  - Their arguments are `$stock_id, $description, $long_description, $category_id,
    $tax_type_id, $units, $mb_flag, $sales_account, $inventory_account, $cogs_account,
    $adjustment_account, $wip_account, $dimension_id, $dimension2_id, $no_sale,
    $editable, $no_purchase, $depreciation_method='D', $depreciation_rate=100,
    $depreciation_factor=1, $depreciation_start=null, $fa_class_id=null`.
  - `add_item` also inserts `loc_stock` rows and the `item_codes` row.
  - `update_item` rewrites every column. Pass the existing row's value for anything
    not being changed, including `depreciation_method`, `depreciation_rate`,
    `depreciation_factor` and `fa_class_id`. Pass `depreciation_start` as null so it
    is left alone.
  - `update_item` also calls `update_item_code(-1, ...)`.
- **The item page** (inventory/manage/items.php):
  - `:218-244`: checks, in this order and with these messages:
    - empty description: 'The item name must be entered.';
    - empty code: 'The item code cannot be empty';
    - a code containing a space, `'`, `+`, `"`, `&` or a tab: 'The item code cannot
      contain any of the following characters -  & + OR a space OR quotes' (two
      spaces after the dash, as FA has it);
    - for a new item, `db_num_rows(get_item_kit($code))` greater than 0: 'This item
      code is already assigned to stock item or sale kit.'
  - `:276-279`: `update_record_status($code, $inactive, 'stock_master', 'stock_id')`
    and `update_record_status($code, $inactive, 'item_codes', 'item_code')`.
  - `:397-411`: the new-item defaults come from `get_item_category($id)`, in columns
    `dflt_tax_type`, `dflt_units`, `dflt_mb_flag`, `dflt_inventory_act`,
    `dflt_cogs_act`, `dflt_sales_act`, `dflt_adjustment_act`, `dflt_wip_act`,
    `dflt_dim1`, `dflt_dim2`, `dflt_no_sale` and `dflt_no_purchase`.
- **Price lists** (sales/includes/db/sales_types_db.inc, sales/manage/sales_types.php):
  - `add_sales_type($name, $tax_included, $factor)` and
    `update_sales_type($id, $name, $tax_included, $factor)`.
  - Page messages: 'The sales type description cannot be empty.' and 'Calculation
    factor must be valid positive number.' (`check_num('factor', 0)`, so a factor
    ≥ 0).
  - `sales_types.sales_type` is UNIQUE.
  - The base list is `get_company_pref('base_sales')`.
- **Prices** (inventory/includes/db/items_prices_db.inc, inventory/prices.php):
  - `add_item_price($stock_id, $sales_type_id, $curr_abrev, $price)`,
    `update_item_price($price_id, $sales_type_id, $curr_abrev, $price)`,
    `delete_item_price($price_id)` and
    `get_stock_price_type_currency($stock_id, $type, $currency)`, which returns the
    row or false.
  - Page message: 'The price entered must be numeric.' (`check_num('price', 0)`).
  - `prices` is UNIQUE on (`stock_id`, `sales_type_id`, `curr_abrev`).
- **Includes:** `inventory/includes/inventory_db.inc` includes items, codes, category
  and prices. `sales/includes/sales_db.inc` includes sales types.
  `inventory/includes/db/items_units_db.inc` has `get_item_unit($unit)`.
  `taxes/db/item_tax_types_db.inc` has `get_item_tax_type($id)`.
- **The demo company:**
  - Category 4 'Services': tax type 1, units 'hr', mb_flag 'D', sales account 4010,
    COGS 5010, inventory 1510, adjustment 5040, WIP 1530.
  - Category 1 'Components': 'B'. No demo category has mb_flag 'F'.
  - Lists: 1 Retail (tax included, factor 1, the base list) and 2 Wholesale (factor
    0.7).
  - Prices on Retail, in USD: 101 at 300, 102 at 250, 103 at 50.
  - The home currency is USD. Customer 1 is USD, on Retail.
  - Unit 'each' exists. Tax type 1 'Regular' exists.

## Review Focus

1. **`stockItemUpdate` changing one field must not reset the others.** `update_item`
   rewrites every column, so a missing value blanks the row's accounts or tax type.
   Task 3 asserts that every other `stock_master` column is unchanged after a
   description-only update.
2. **`priceSet` on a key that already exists updates the row; it never adds a
   second.** The same holds when the code is sent in another case (`hdom-nz`) or with
   a trailing space: it must match the stored row and store `stock_master`'s
   spelling. Task 4 tests both.
3. **A batch with a bad item writes nothing.** A four-item `stockItemCreate` whose
   third code is a duplicate must leave none of the four. Task 3 tests it.
4. **Making an item inactive takes it off the order pickers**, which read
   `item_codes`. So `inactive` must reach `item_codes` too. Task 3 asserts both
   tables.
5. **The base list:** making the base list inactive is allowed (FA's page allows it),
   but `isBase` must still answer true for it. Task 2 asserts `isBase` for list 1 and
   false for a new list.

## Execution Schedule

| Wave | Tasks | Model | Notes |
|------|-------|-------|-------|
| 1 | Task 1 | sonnet | The generated surface: models, generator flags, inputs, Price list, includes, test sweep. Every later task builds on it. |
| 2 | Task 2, Task 3, Task 4 | sonnet, opus, sonnet | Parallel, each in its own temp worktree from the wave base. The files are disjoint: Task 2 has SalesType*, Task 3 StockItem*, and Task 4 Price* plus ApiSchema and ApiSchemaTest. Each run is its own docker container. Start each only while the load is below 10. |
| CP1 | — | — | plan base..end of wave 2: code-review medium plus a spec check (§4.1–§4.3, §7 Release B). Not concurrent with wave 3, which edits README and seed files that the review reads. |
| 3 | Task 5 | sonnet | Seed grants, the Panel HTTP flow, the customerUpdate pin, README. |
| CP2 | — | — | plan base..end of wave 3: code-review medium plus a spec check (the full §4, §6 Release B, §7). |
| 4 | Controller | — | Full gates (`sh tools/ci.sh`), push, PR, CI, then merge when green. |

---

### Task 1: The generated surface

**Files:**
- Modify: `bin/generate` (the READONLY and WITHOUT_DELETE lists)
- Modify: `src/Model/StockItemModel.php`, `src/Model/SalesTypeModel.php` (`@required`
  and docblocks)
- Create: `src/Model/PriceModel.php`
- Generated (run `bin/generate`): `src/Type/StockItem/Base/*`,
  `src/Type/SalesType/Base/*`, `src/Type/Price/Base/*`, `src/Type/Price/PriceType.php`,
  `src/Type/StockItem/StockItemCreateInput.php`, `StockItemUpdateInput.php`,
  `src/Type/SalesType/SalesTypeCreateInput.php`, `SalesTypeUpdateInput.php`,
  `tests/Generated/PriceTypeTest.php`, and the `src/ApiSchema.php` entries
- Modify: `src/Type/StockItem/StockItemCreateInput.php`, `StockItemUpdateInput.php`
  (trim the generated fields)
- Modify: `src/Type/StockItem/StockItemType.php`, `src/Type/SalesType/SalesTypeType.php`,
  `src/Type/Price/PriceType.php` (`areas()`)
- Modify: `src/ApiSchema.php` (the hand-written `priceCount`)
- Modify: `src/Fa/Service/FaIncludes.php` (adds `items()`)
- Modify: `tests/Support/FaTestRows.php` (adds `sweepItems()`)
- Modify: `tests/Unit/ApiSchemaTest.php`, `tests/Generated/StockItemTypeTest.php`,
  `tests/Generated/SalesTypeTypeTest.php`

**Interfaces:**
- Produces:
  - **Mutations:** `stockItemCreate(input: [StockItemCreateInput!]!): [StockItemType!]!`
    and `stockItemUpdate(input: [StockItemUpdateInput!]!)`;
    `salesTypeCreate(input: [SalesTypeCreateInput!]!): [SalesTypeType!]!` and
    `salesTypeUpdate(input: [SalesTypeUpdateInput!]!)`. Until wave 2 they all answer
    FORBIDDEN ("no write path"), from FaModelType.
  - **Queries:** `priceList(query: MangoInput): [PriceType!]!` and
    `priceCount(query: MangoInput): Int!`.
  - **`PriceType`** fields: `id: ID!`, `stockId: String`, `salesTypeId: ID`,
    `currency: String`, `price: Float`.
  - **Input fields:**
    - `StockItemCreateInput { stockId: String!, description: String!, categoryId: ID!,
      longDescription: String, inactive: Boolean, taxTypeId: ID, units: String,
      editable: Boolean }`;
    - `StockItemUpdateInput { id: ID!, description: String, longDescription: String,
      categoryId: ID, inactive: Boolean, taxTypeId: ID, units: String, editable: Boolean }`;
    - `SalesTypeCreateInput { name: String!, taxIncluded: Boolean, factor: Float,
      inactive: Boolean }`;
    - `SalesTypeUpdateInput { id: ID!, name, taxIncluded, factor, inactive }`.
  - **`areas()`:**
    - StockItemType `['list' => 'SA_SALESORDER', 'create' => 'SA_ITEM', 'edit' => 'SA_ITEM']`;
    - SalesTypeType `['list' => 'SA_SALESORDER', 'create' => 'SA_SALESTYPES', 'edit' => 'SA_SALESTYPES']`;
    - PriceType `['list' => 'SA_SALESPRICE', 'create' => 'SA_SALESPRICE', 'edit' => 'SA_SALESPRICE', 'delete' => 'SA_SALESPRICE']`.
  - **`FaIncludes::items(): void`** includes `sales/includes/sales_db.inc`,
    `inventory/includes/inventory_db.inc`, `inventory/includes/db/items_units_db.inc`
    and `taxes/db/item_tax_types_db.inc`.
  - **`FaTestRows::sweepItems(\PDO $pdo, string $prefix, string $tb = '0_'): void`**
    deletes the following rows, in this order: `prices` whose `stock_id` LIKE
    `$prefix%`; `prices` of `sales_types` whose `sales_type` LIKE `$prefix%`; `loc_stock`,
    `item_codes` (on `stock_id`) and `stock_master` rows LIKE `$prefix%`; and those
    `sales_types` rows.

- [ ] **Step 1: Models.**
  - In `StockItemModel`, mark `description` and `categoryId` `@required`, in the same
    docblock style as `CustomerModel`.
  - Replace the class docblock's "Read-only through the API" sentence with: "Written
    only through StockItemService (StockItemType routes the generated create and
    update there; no delete)."
  - In `SalesTypeModel`, mark `name` `@required`, and replace "Read-only through the
    API (bin/generate lists it in READONLY)" with the same kind of sentence, naming
    SalesTypeService.
  - Create `PriceModel` over `CompanyContext::prefix() . 'prices'`, in
    `SalesTypeModel`'s style:

```php
    /** @var int */
    public $id;

    /** @var string Item code (stock_id) */
    public $stockId = '';

    /** @var int Price list (sales_type_id) */
    public $salesTypeId = 0;

    /** @var string Currency code (curr_abrev) */
    public $currency = '';

    /** @var float */
    public $price = 0.0;
```

  Its mapping is `'id' => 'id', 'stockId' => 'stock_id', 'salesTypeId' => 'sales_type_id',
  'currency' => 'curr_abrev', 'price' => 'price'`. It needs no transformers. Its
  docblock says "A price: FrontAccounting's prices, one row per item, list and
  currency. Read-only as an entity; written by priceSet and priceDelete through
  PriceService."

- [ ] **Step 2: Generator flags.**
  - In `bin/generate`, remove `SalesType` and `StockItem` from READONLY and add
    `Price` to it.
  - Set `WITHOUT_DELETE="SalesType,StockItem"`.
  - Extend the comment above WITHOUT_DELETE: "FA refuses to delete an item with
    transactions (inactive retires one), and the price-list page's delete checks are
    out of Release B's scope (Items and prices spec §4.1, §4.2)."
  - Run `bin/generate --dry-run`, then `bin/generate`. Check `git status`: the Base
    files, the four Input classes, PriceType, PriceTypeTest and ApiSchema entries
    should be new or changed. **No existing hand-edited file may be overwritten**:
    the generator prints "kept" for those.

- [ ] **Step 3: Trim the inputs.** The generated inputs carry every model field, so
  override `fields()` in `StockItemCreateInput` and `StockItemUpdateInput` to keep
  only the spec's (§4.1).
  - The create input's code is `stockId: String!`. If the generator emitted an `id`
    field on the create input, drop it. Field order follows the Interfaces list.
  - Each field gets a one-line `setDescription`. Use these texts:
    - stockId: "Item code, as FA's item page takes it (no spaces, quotes, + or &; at
      most 20 characters)."
    - taxTypeId: "Defaults to the category's tax type."
    - units: "Defaults to the category's units."
    - editable: "Whether an order line may change the description. Defaults to true."
    - categoryId (on the update input): "Changing it does not re-default the
      accounts or tax type."

  Build the fields with `FieldBuilder::create(...)->setDescription(...)->build()`, in
  `CustomerCreateInput`'s style. For example:

```php
    protected function fields(): array
    {
        $keep = ['description', 'categoryId', 'longDescription', 'inactive', 'taxTypeId', 'units', 'editable'];
        $fields = array_values(array_filter(parent::fields(), function (array $field) use ($keep): bool {
            return in_array($field['name'], $keep, true);
        }));

        return array_merge([
            FieldBuilder::create('stockId', Type::nonNull(Type::string()))
                ->setDescription("Item code, as FA's item page takes it (no spaces, quotes, + or &; at most 20 characters).")
                ->build(),
        ], $fields);
    }
```

  This is an outline. Check what `parent::fields()` returns (a list of
  `['name' => ...]` arrays from FieldBuilder) before relying on `$field['name']`.
  `taxTypeId`, `units` and `editable` may need their descriptions added by mapping
  over the kept fields. The SalesType inputs need no trimming; leave them as
  generated.

- [ ] **Step 4: `areas()`.** Set the three `areas()` exactly as in Interfaces.
  - Update the docblocks: the list area stays the order-taking area, for the reason
    already given there, and the writes take FA's setup area.
  - PriceType's docblock: "Read through priceList; written by the hand-written
    priceSet and priceDelete (ApiSchema), never by a generated mutation."

- [ ] **Step 5: `priceCount`.** In `ApiSchema`, add
  `$this->countField('priceCount', PriceType::class)` immediately before the generated
  `priceList`. Keep alphabetical order: `priceCount`, `priceList`, `priceQuote`.

- [ ] **Step 6: `FaIncludes::items()` and `FaTestRows::sweepItems()`.** Write both as
  Interfaces says. FaIncludes is in the style of `customers()`. sweepItems uses the
  file's existing `column()`, `marks()` and `run()` helpers.

- [ ] **Step 7: Unit tests.**
  - In `ApiSchemaTest::testTheRootFieldsAreExactlyTheSpecsAndNothingElse`, add
    `'price'` to `$lists`. Add `salesTypeCreate`, `salesTypeUpdate`, `stockItemCreate`
    and `stockItemUpdate` to the expected mutations, in alphabetical position.
  - `testSalesTypesAreListedReadOnlyThroughMango` no longer holds. Rename it
    `testSalesTypesAreListedThroughMangoAndWrittenByCreateAndUpdate`, keep its list
    and type assertions, and replace the read-only ones with these:
    - `salesTypeCreate(input: [SalesTypeCreateInput!]!)` is present;
    - `salesTypeUpdate(` is present;
    - `salesTypeDelete` is absent;
    - `salesTypeUpsert` is absent.
  - Add `testStockItemInputsAreTheSpecsFields`. It asserts the exact field names, in
    order, of `StockItemCreateInput` and `StockItemUpdateInput` as Interfaces lists
    them, and that `stockId`, `description` and `categoryId` are non-null on create.
    Read them through `$schema->getType('StockItemCreateInput')->getFields()`.
  - In `tests/Generated/StockItemTypeTest.php` and `SalesTypeTypeTest.php`, set
    `inputClass()` to the new Create input class, if the generated test base needs it
    for a writable entity. Read `Anorm\GraphQL\Testing\ModelTypeTestCase` to see what
    it checks.

- [ ] **Step 8: Run.** `T --testsuite unit`, then `T --filter "'StockItemTypeTest|SalesTypeTypeTest|PriceTypeTest|ApiSchemaTest'"`,
  then `Q`. All must pass.

- [ ] **Step 9: Commit.** "Release B surface: writable StockItem and SalesType, Price
  list, priceCount".

### Task 2: Price lists

**Files:**
- Create: `src/Fa/Service/SalesTypeService.php`
- Modify: `src/Type/SalesType/SalesTypeType.php`
- Test: `tests/Integration/Pricing/SalesTypeServiceTest.php`

**Interfaces:**
- Consumes: Task 1's inputs and `areas()`, `FaIncludes::items()` and
  `FaTestRows::sweepItems()`.
- Produces: `SalesTypeService::create(array $input): int`,
  `SalesTypeService::update(array $input): void` (with an int `id`), and
  `SalesTypeType.isBase: Boolean!`.

- [ ] **Step 1: Write the failing tests.** Use the setup from `CustomerServiceTest`:
  `FaTestCase`, FaSession entered as apitest, a `FaTestRows::prefix()`, and
  `sweepItems` in tearDown. Call the service through
  `ServiceCall::each([$input], fn ...)`, as the Type will. Cases:
  1. `create(['name' => "{$p} Reseller"])` returns an id. Its row has `factor` 1,
     `tax_included` 0 and `inactive` 0.
  2. `create(['name' => "{$p} R", 'factor' => 0.85, 'taxIncluded' => true, 'inactive' => true])`
     stores exactly those values.
  3. An empty name throws BadInput with field `name` and the message 'The sales type
     description cannot be empty.'
  4. A factor of -1 throws BadInput `factor`: 'Calculation factor must be valid
     positive number.'
  5. Using the name 'Retail' (an existing list's name) throws BadInput `name`: "A
     price list named 'Retail' already exists."
  6. `update(['id' => $id, 'factor' => 0.5])` keeps the name and `taxIncluded`.
  7. `update(['id' => $id, 'inactive' => false])` after case 2 reactivates the list.
  8. Updating an unknown id (999999) throws NotFound "Sales type id '999999' not found".
  9. Renaming a list to its own current name is allowed: the uniqueness check skips
     the list itself.
  10. `isBase`, through the schema: `salesTypeList { id isBase }` gives true for id 1
      only, and false for a list made in this test. Run it through the container's
      ApiSchema with GraphQL::executeQuery, the way the other integration tests run a
      document. Look in `tests/Integration/SalesOrder/` for the helper they use.
  11. `isBase` stays true for the base list after
      `update(['id' => 1, 'inactive' => true])`. Restore the list in a `finally`.
  12. Forbidden: a session without SA_SALESTYPES gets FORBIDDEN from
      `salesTypeCreate`. Use the `apiorders` user's role, and check first that it
      lacks SA_SALESTYPES (tests/data/seed.sql). If it does not lack it, use the
      FaModelType unit-test pattern instead.

- [ ] **Step 2: Run them; they fail.** `T --filter SalesTypeServiceTest`.

- [ ] **Step 3: Implement `SalesTypeService`**, in `CustomerService`'s style. The
  docblock cites sales/manage/sales_types.php. Sketch:

```php
    /** @param array<string, mixed> $input */
    public function create(array $input): int
    {
        FaIncludes::items();
        $list = $this->validated($input, ['name' => '', 'taxIncluded' => false, 'factor' => 1.0], null);
        add_sales_type($list['name'], $list['taxIncluded'] ? 1 : 0, self::sqlNumber((float) $list['factor']));
        $id = (int) db_insert_id();
        if (!empty($input['inactive'])) {
            update_record_status($id, 1, 'sales_types', 'id');
        }

        return $id;
    }

    /** @param array<string, mixed> $input with an int 'id' */
    public function update(array $input): void
    {
        FaIncludes::items();
        $id = (int) $input['id'];
        $row = get_sales_type($id);
        if (!$row) {
            throw new NotFound("Sales type id '$id' not found");
        }
        $list = $this->validated($input, [
            'name' => $row['sales_type'], 'taxIncluded' => (bool) $row['tax_included'], 'factor' => $row['factor'],
        ], $id);
        update_sales_type($id, $list['name'], $list['taxIncluded'] ? 1 : 0, self::sqlNumber((float) $list['factor']));
        if (array_key_exists('inactive', $input) && $input['inactive'] !== null) {
            update_record_status($id, $input['inactive'] ? 1 : 0, 'sales_types', 'id');
        }
    }
```

  - `validated()`: an explicit null for `name`, `taxIncluded` or `factor` is
    BadInput "<field> cannot be null.", as in CustomerService. Then the page's two
    checks, in its order (name, then factor: `is_numeric` and ≥ 0).
  - Then uniqueness: `SELECT id FROM sales_types WHERE sales_type = db_escape(name)`
    finds a row whose id is not `$id`. The name is already escaped, as the database
    stores it, so the comparison is like for like. Its message is "A price list
    named '<name>' already exists.", with the name decoded by `FaText::decode`.
  - `sqlNumber` is `sprintf('%.6F', ...)`, as CustomerService has it.

- [ ] **Step 4: Route the Type and add `isBase`.**
  - In `SalesTypeType`, add `resolveCreate` and `resolveUpdate` exactly as
    `CustomerType`'s, with SalesTypeService. Use `self::intId` for the update id.
  - Add the field:

```php
    protected function fields(): array
    {
        return array_merge(parent::fields(), [
            FieldBuilder::create('isBase', Type::nonNull(Type::boolean()))
                ->setDescription("The company's base price list (base_sales): other lists fall back to its prices times their factor.")
                ->setResolver(function (array $row): bool {
                    return (int) $row['id'] === (int) get_company_pref('base_sales');
                })
                ->build(),
        ]);
    }
```

  - Update the class docblock: written through SalesTypeService, in the same words as
    CustomerType's.

- [ ] **Step 5: Run.** `T --filter "'SalesTypeServiceTest|SalesTypeTypeTest|ApiSchemaTest'"`
  and `Q`. They must pass. `isBase` adds a line to the SDL that
  `testSalesTypesAreListedThroughMango…`'s type regex reads, so update that regex in
  this task. ApiSchemaTest belongs to Task 4 in this wave, so make the smallest edit
  you can: append `\s+isBase: Boolean!` to `$typePattern`.

- [ ] **Step 6: Commit.** "salesTypeCreate/Update through FA, salesTypeList.isBase".

### Task 3: Items

**Files:**
- Create: `src/Fa/Service/StockItemService.php`
- Modify: `src/Type/StockItem/StockItemType.php`
- Test: `tests/Integration/Pricing/StockItemServiceTest.php`

**Interfaces:**
- Consumes: Task 1's inputs, `areas()`, `FaIncludes::items()`,
  `FaTestRows::sweepItems()`, and `StockItems::canonicalId()` (Release A).
- Produces: `StockItemService::create(array $input): string` (the stored
  stock_id), `StockItemService::update(array $input): void`, and the
  `stockItemCreate` and `stockItemUpdate` resolvers.

- [ ] **Step 1: Write the failing tests.** The setup is as in Task 2. Codes are
  `"{$p}-NZ"` and so on: the prefix is 12 characters, so they stay under 20. Read
  rows back with `FaTestRows::connect()`. Cases:
  1. `create(['stockId' => "{$p}-NZ", 'description' => 'Domain .nz', 'categoryId' => 4])`
     returns `"{$p}-NZ"`, and `stock_master` has:
     - category 4, tax type 1, units 'hr', mb_flag 'D';
     - sales account 4010, COGS 5010, inventory 1510, adjustment 5040, WIP 1530;
     - editable 1, no_sale 0, inactive 0.
     `item_codes` has one row (`item_code = stock_id`, quantity 1, is_foreign 0), and
     `loc_stock` has one row per location.
  2. Overrides: `taxTypeId` 1, `units` 'each' and `editable` false are stored.
  3. `longDescription` is stored.
  4. Each BAD_INPUT on `stockId`, with its message:
     - '' gives 'The item code cannot be empty';
     - 'A B' (a space) gives the characters message;
     - `"A'B"`, `'A+B'` and `'A&B'` give the characters message too (a data provider
       is fine). Note that `&` arrives as `&amp;` after cleanInput, which still
       contains `&`;
     - 21 characters give "The item code cannot be longer than 20 characters.";
     - '101' (an existing item) and '501' (a kit) give 'This item code is already
       assigned to stock item or sale kit.'
  5. An empty description is BAD_INPUT `description`: 'The item name must be entered.'
  6. Bad references:
     - category 999 is BAD_INPUT `categoryId`: "There is no item category '999'.";
     - taxTypeId 999 is BAD_INPUT `taxTypeId`;
     - units 'zz' is BAD_INPUT `units`.
     Each uses ReferenceCheck. The tables are `stock_category.category_id`,
     `item_tax_types.id` and `item_units.abbr`.
  7. A fixed-asset category is BAD_INPUT `categoryId`: "Category '<id>' is for fixed
     assets." Insert a temporary category with `dflt_mb_flag` 'F' and delete it in
     tearDown.
  8. `inactive: true` on create sets `inactive` 1 on both `stock_master` and
     `item_codes`.
  9. Atomic batch: run `ServiceCall::each` over four inputs whose third is `'101'`.
     It throws BadInput with index 2, and none of the four codes exists afterwards.
  10. Update keeps the rest. Snapshot the whole `stock_master` row, then run
      `update(['id' => $code, 'description' => 'New'])`. Only `description` changed,
      and `item_codes.description` is 'New'.
  11. A category change keeps the accounts and the tax type: create the item in
      category 4, update it to category 2, and only `category_id` differs (and
      `item_codes.category_id`).
  12. `inactive` true, then false, on update: both tables follow.
  13. Updating an unknown id is NotFound "Stock item id 'NOPE' not found". A kit code
      ('501') is NotFound too.
  14. Through the schema, as apitest: `stockItemCreate(input: [...]) { id description editable }`
      returns the rows. `stockItemUpdate` returns the updated row.
  15. FORBIDDEN without SA_ITEM, as in Task 2's case 12.

- [ ] **Step 2: Run them; they fail.** `T --filter StockItemServiceTest`.

- [ ] **Step 3: Implement `StockItemService`.**

```php
    /** The page's forbidden characters (items.php:230-232). */
    private const BAD_CHARS = [' ', "'", '+', '"', '&', "\t"];

    private const REFS = [
        'categoryId' => ['stock_category', 'category_id', 'item category', true],
        'taxTypeId' => ['item_tax_types', 'id', 'tax type', true],
        'units' => ['item_units', 'abbr', 'unit', false],
    ];

    /** @param array<string, mixed> $input */
    public function create(array $input): string
    {
        FaIncludes::items();
        $code = (string) ($input['stockId'] ?? '');
        $description = (string) ($input['description'] ?? '');
        // items.php:218-244, its order and messages.
        if (strlen($description) === 0) {
            throw new BadInput('The item name must be entered.', 'description');
        }
        if (strlen($code) === 0) {
            throw new BadInput('The item code cannot be empty', 'stockId');
        }
        foreach (self::BAD_CHARS as $char) {
            if (strpos($code, $char) !== false) {
                throw new BadInput(
                    'The item code cannot contain any of the following characters -  & + OR a space OR quotes',
                    'stockId'
                );
            }
        }
        if (strlen($code) > 20) {
            throw new BadInput('The item code cannot be longer than 20 characters.', 'stockId');
        }
        if (db_num_rows(get_item_kit($code)) > 0) {
            throw new BadInput('This item code is already assigned to stock item or sale kit.', 'stockId');
        }
        $category = $this->category($input['categoryId'] ?? null);
        // items.php:397-411: a new item's defaults are its category's; the overrides win.
        $item = [
            'categoryId' => (int) $category['category_id'],
            'taxTypeId' => $input['taxTypeId'] ?? $category['dflt_tax_type'],
            'units' => $input['units'] ?? $category['dflt_units'],
        ];
        ReferenceCheck::requireAll($item, self::REFS);
        add_item(
            $code, $description, (string) ($input['longDescription'] ?? ''), $item['categoryId'],
            (int) $item['taxTypeId'], $item['units'], $category['dflt_mb_flag'],
            $category['dflt_sales_act'], $category['dflt_inventory_act'], $category['dflt_cogs_act'],
            $category['dflt_adjustment_act'], $category['dflt_wip_act'],
            $category['dflt_dim1'], $category['dflt_dim2'],
            $category['dflt_no_sale'], array_key_exists('editable', $input) && $input['editable'] !== null
                ? ($input['editable'] ? 1 : 0) : 1,
            $category['dflt_no_purchase']
        );
        if (!empty($input['inactive'])) {
            self::setInactive($code, true);
        }

        return $code;
    }
```

  The code above is a sketch. Here is what it must do; refine it to fit:
  - **The category:** `category($id)` uses `IntKey::parse($id, 'categoryId')`, then
    `get_item_category()`. A missing row is BadInput "There is no item category '<id>'."
    A row with `dflt_mb_flag === 'F'` is BadInput "Category '<id>' is for fixed
    assets."
  - **The long-line format:** the `add_item` call must pass phpcs's line length.
    Format it one argument per line.
  - **`update`:** load the row with `get_item($id)`. A missing row is NotFound
    "Stock item id '<id>' not found". `get_item` returns no row for a kit or foreign
    code, because those have no stock_master row.
    - Merge the given fields over the row. An explicit null for `description`,
      `categoryId`, `taxTypeId`, `units` or `editable` is BadInput "<field> cannot
      be null.".
    - Check the description, and run `ReferenceCheck` on categoryId (and the
      fixed-asset check), taxTypeId and units.
    - Call `update_item` with every other argument from the row:
      `$row['mb_flag']`, the five accounts, `dimension_id`, `dimension2_id`,
      `no_sale`, `no_purchase`, `depreciation_method`, `depreciation_rate`,
      `depreciation_factor`, `null` for `depreciation_start`, and
      `$row['fa_class_id']`.
    - Use `$row['stock_id']` as the code: it is stock_master's spelling.
  - **`setInactive($code, bool)`:** `update_record_status` on both `stock_master` and
    `item_codes`, as items.php:276-279 does.
  - **Escaping:** FA's `db_escape` does the escaping; pass plain values.

- [ ] **Step 4: Route the Type.** Add `resolveCreate` and `resolveUpdate` in
  CustomerType's shape, with these differences:
  - create collects the returned string codes;
  - update takes `(string) $input['id']`, and calls `StockItems::canonicalId()` to
    read back by stored spelling. A null is NotFound, but the service has already
    refused it.
  - `rowsById` works with string ids.
  - Update the class docblock.

- [ ] **Step 5: Run.** `T --filter "'StockItemServiceTest|StockItemTypeTest'"` and `Q`.
  They must pass.

- [ ] **Step 6: Commit.** "stockItemCreate/Update through FA's add_item and update_item".

### Task 4: Prices

**Files:**
- Create: `src/Fa/Service/PriceService.php`
- Create: `src/Type/Price/PriceSetInput.php`, `src/Type/Price/PriceKeyInput.php`
- Modify: `src/Type/Price/PriceType.php` (adds `resolveSet` and `resolveRemove`)
- Modify: `src/ApiSchema.php` (`priceDelete`, `priceSet`)
- Modify: `tests/Unit/ApiSchemaTest.php` (the expected mutations, and the SDL lines)
- Test: `tests/Integration/Pricing/PriceServiceTest.php`

**Interfaces:**
- Consumes: Task 1's PriceType, `areas()`, `FaIncludes::items()` and
  `FaTestRows::sweepItems()`; `StockItems::canonicalId()`; and
  `PriceQuoteService::quote()` (Release A), for the read-after-write test.
- Produces:
  - `PriceService::set(array $input): int` (the price id);
  - `PriceService::remove(array $input): int` (the id of the price it deleted);
  - `priceSet(input: [PriceSetInput!]!): [PriceType!]!`;
  - `priceDelete(input: [PriceKeyInput!]!): [PriceType!]!`.

- [ ] **Step 1: Write the failing tests.** Use the setup from Task 2. Add prices only
  to items this test creates (insert a stock_master row with the prefix through
  `add_item` in setUp, with category 4) and to item '102'. Delete the '102' rows in
  tearDown. Cases:
  1. `set(['stockId' => $code, 'salesTypeId' => '1', 'currency' => 'USD', 'price' => 12.5])`
     inserts one row. Setting the same key again with 15 leaves one row, with price
     15, and returns the same id.
  2. The key is matched by case and padding: `stockId` given lower-cased, or with a
     trailing space, updates the same row. The stored `stock_id` is stock_master's
     spelling.
  3. BAD_INPUT, field by field:
     - an unknown item (`stockId` 'NOPE') gives "There is no item 'NOPE'.";
     - a kit ('501') is BAD_INPUT `stockId` with the same message;
     - an unknown list (999) gives `salesTypeId` "There is no sales type '999'.";
     - `salesTypeId` 'x' is BAD_INPUT through IntKey;
     - currency 'ZZZ' gives `currency` "There is no currency 'ZZZ'.";
     - price -1 gives `price` 'The price entered must be numeric.' (FA's message for
       `check_num('price', 0)`).
  4. Read after write: after `set` on list 2 at 99, `PriceQuoteService::quote($code, null, 2)['price']`
     is 99.0.
  5. `remove` with the same key returns the deleted id, and the row is gone. Removing
     a key with no row is NotFound "There is no price for '<code>' on list 2 in USD."
  6. Atomic batch: `priceDelete` of [an existing key, a missing key], through the
     schema, is NOT_FOUND with index 1, and the existing row is still there.
  7. Through the schema:
     - `priceSet(input: [...]) { id stockId salesTypeId currency price }` returns the
       rows in input order;
     - `priceDelete(input: [...])` returns the removed rows as they were;
     - `priceList(query: {selector: "{\"stockId\":\"<code>\"}"}) { price }` and
       `priceCount` with the same query see them.
  8. FORBIDDEN without SA_SALESPRICE, for `priceSet` and for `priceList`.

- [ ] **Step 2: Run them; they fail.** `T --filter PriceServiceTest`.

- [ ] **Step 3: Implement `PriceService`.**
  - `key(array $input): array` resolves the key:
    - `StockItems::canonicalId((string) $input['stockId'])`, where null is BadInput
      `stockId` "There is no item '<code>'.";
    - `IntKey::parse($input['salesTypeId'], 'salesTypeId')`, then the list must exist
      (`get_sales_type`), else BadInput;
    - the currency is looked up as
      `SELECT curr_abrev FROM currencies WHERE curr_abrev = db_escape(...)`; no row is
      BadInput `currency`. Use the row's spelling.
  - `set`: run `key()`. The price must be numeric and ≥ 0. Then call
    `get_stock_price_type_currency($stock, $list, $currency)`: with a row, call
    `update_item_price($row['id'], $list, $currency, $price)` and return
    `(int) $row['id']`; without one, call `add_item_price(...)` and return
    `(int) db_insert_id()`.
  - `remove`: run `key()`, find the row (none is NotFound), call
    `delete_item_price($row['id'])`, and return the id.
  - The class docblock cites inventory/prices.php.

- [ ] **Step 4: The inputs and resolvers.**
  - The inputs are hand-written `InputObjectType`s, in `BranchDefaultsInput`'s style:
    - `PriceSetInput { stockId: String!, salesTypeId: ID!, currency: String!, price: Float! }`;
    - `PriceKeyInput { stockId: String!, salesTypeId: ID!, currency: String! }`.
  - On `PriceType`:

```php
    public function resolveSet($root, $args, Container $context): array
    {
        $this->authorize(self::VERB_CREATE, null, $context);
        $prices = $context->get(PriceService::class);
        $ids = ServiceCall::each($args['input'], function (array $input) use ($prices): int {
            return $prices->set($input);
        });

        return $this->rowsById($context, $ids, self::VERB_EDIT);
    }

    public function resolveRemove($root, $args, Container $context): array
    {
        $this->authorize(self::VERB_DELETE, null, $context);
        $prices = $context->get(PriceService::class);
        $rows = [];
        ServiceCall::each($args['input'], function (array $input) use ($prices, $context, &$rows): void {
            $id = $prices->find($input);   // the key's row id, or NotFound
            $rows[] = $this->rowsById($context, [$id], self::VERB_DELETE)[0];
            $prices->remove($input);
        });

        return $rows;
    }
```

  - **Read before delete:** `resolveRemove` must read each row before deleting it,
    and inside the transaction. `rowsById` reads on the container's PDO, outside FA's
    transaction, so a row deleted earlier in the same batch is still visible there.
    That is fine: each key is distinct. A repeated key in one batch is refused by
    `remove()`'s NotFound on its second time, so the batch rolls back.
  - **`find()`:** split `remove` into a public `find(array $input): int` and
    `remove()`, so that find runs first.
  - **The `priceSet` read-back:** `rowsById` for priceSet reads after commit.
  - **ApiSchema:** add `priceDelete` and `priceSet` as hand-written mutation entries,
    in alphabetical position (after `login`, before `salesOrderCreate`), in
    `invoiceEmail`'s array style. The type is
    `Type::nonNull(Type::listOf(Type::nonNull($this->type(PriceType::class))))`, the
    argument is `input` of the matching non-null list, and the resolver is
    `fn ($root, $args) => $this->type(PriceType::class)->resolveSet($root, $args, $this->context)`.
  - **Descriptions:**
    - priceSet: "Add or change prices, one per item, list and currency, as FA's sales
      pricing page does. Needs SA_SALESPRICE."
    - priceDelete: "Remove prices by item, list and currency; returns the rows
      removed. Needs SA_SALESPRICE."
  - **ApiSchemaTest:**
    - add `priceDelete` and `priceSet` to the expected mutations;
    - assert the SDL contains `priceSet(input: [PriceSetInput!]!): [PriceType!]!` and
      `priceDelete(input: [PriceKeyInput!]!): [PriceType!]!`.

- [ ] **Step 5: Run.** `T --filter "'PriceServiceTest|PriceTypeTest|ApiSchemaTest|PriceQuoteServiceTest'"`
  and `Q`. They must pass.

- [ ] **Step 6: Commit.** "priceList, priceSet and priceDelete through FA's item price functions".

### Task 5: The Panel's flow, access, docs

**Files:**
- Modify: `tests/data/seed.sql` (the GraphQL Panel role)
- Create: `tests/Http/PricingAdminFlowTest.php`
- Modify: `tests/Integration/Service/CustomerServiceTest.php` (the salesTypeId pin)
- Modify: `README.md`

**Interfaces:**
- Consumes: every mutation from Tasks 1–4, and the machine-token helper
  `MachineTokenFlowTest` uses (`tests/Http/`).

- [ ] **Step 1: Seed.** After the "GraphQL Panel" insert in `tests/data/seed.sql`,
  append the grants in the style of the existing appended areas.
  - The new sections are SS_SALES_C 2816 (11<<8) and SS_ITEMS_C 7936 (31<<8).
  - The new areas are SA_SALESPRICE 2818 (SS_SALES_C|2) and SA_ITEM 7937
    (SS_ITEMS_C|1). Each is appended only when missing.
  - The comment reads: "Release B (items and prices spec §6): the panel writes items
    (SA_ITEM) and prices (SA_SALESPRICE); not price lists (SA_SALESTYPES). On live,
    granting these is the owner's call in FA's role editor."
  - Verify the numbers against `includes/access_levels.inc`.

- [ ] **Step 2: The HTTP flow test.** It uses the sgwpanel machine token, exactly as
  `MachineTokenFlowTest` mints one. The GraphQL documents are the Panel's own shapes:
  1. `me { areas }` includes SA_ITEM and SA_SALESPRICE.
  2. `stockItemCreate(input: [...])` creates four items with prefixed codes, `-NZ`,
     `-COM`, `-ORG` and `-NET`, each in category 4 with `editable: true`. It returns
     four rows with `editable: true`.
  3. `priceSet` sets the owner's real figures (in USD, the demo's home currency;
     live is NZD), with a test-made list standing in for "Reseller G" (factor 1,
     tax excluded, created through `SalesTypeService` in setUp):
     - list 1 (Retail): -NZ, -COM, -ORG and -NET at 50 each;
     - list 2 (Wholesale): each at 45;
     - the reseller list: -NZ at 35, and -COM, -ORG and -NET at 30 each.
     That is 12 rows in one call. `priceList` for the -NZ code returns three rows.
  4. `priceQuote(stockId: <-NZ>, salesTypeId: "2")` returns 45, list 2's own price
     and not 50 × 0.7. With the reseller list's id it returns 35.
  5. `customerUpdate(input: [{id: <a customer this test creates>, salesTypeId: <the reseller list>}])`,
     then `priceQuote(stockId: <-COM>, customerId: <id>)`, gives 30.
     Create the customer through `customerCreate` as the existing HTTP tests do, or
     through `CustomerService` if an HTTP test already does that.
  6. `salesTypeCreate` with the panel token is FORBIDDEN, because the panel has no
     SA_SALESTYPES.
  7. Clean up in tearDown with `FaTestRows::sweepItems` and `sweep`. HTTP tests reach
     the database the same way the other tests in `tests/Http/` do; check how
     `PriceQuoteEndpointTest` cleans up.

- [ ] **Step 3: Pin `customerUpdate(salesTypeId)`** in `CustomerServiceTest`: update a
  prefixed customer to list 2, and its `sales_type` is 2. One test.

- [ ] **Step 4: README.**
  - The coverage table gains an "Items and price lists" row: stockItemCreate/Update,
    salesTypeCreate/Update, priceList/priceCount/priceSet/priceDelete, with their
    areas.
  - In the Pricing section, add three paragraphs:
    1. Item defaults: a new item takes its category's defaults, `editable` defaults
       to true, and there is no item delete (retire an item with `inactive`).
    2. The upsert key: `priceSet` upserts on (stockId, salesTypeId, currency).
    3. The tax-included note, word for word from spec §4.3: "Set explicit prices on
       any list whose `taxIncluded` differs from the base list's. FA's fallback (base
       × factor) does not convert between tax-included and tax-excluded prices."
  - Under rollout or access, add: "the panel's role needs SA_ITEM and SA_SALESPRICE."
  - The roadmap's inventory-maintenance gap now names only what is left: item delete,
    kits and purchasing.

- [ ] **Step 5: Run.** `T --filter "'PricingAdminFlowTest|CustomerServiceTest|MachineTokenFlowTest'"`
  and `Q`. They must pass.

- [ ] **Step 6: Commit.** "Release B: the panel's pricing flow, its access, README".
