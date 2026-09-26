# Release 2 (Panel) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Through the GraphQL API, the `saygoweb.com-my` panel can read FrontAccounting's order lookups; create, update and delete customers, branches and contacts; create, update and delete (FrontAccounting's cancel) sales orders with their lines; and give an order a recurring schedule when `sgw_sales` is active.

**Architecture:** Every entity is an Anorm model in `src/Model`, and `anorm-graphql` 0.2 generates its schema: lookups `--readonly`, writable entities with `--mutations create-update`, order lines `--readonly --input-only`. A writable entity's once-only Type overrides `resolveCreate` / `resolveUpdate` / `resolveDelete` to call a service in `src/Fa/Service`. That service calls FrontAccounting's own functions (`add_customer`, `add_branch`, `add_crm_person`, `Cart`, `delete_sales_order`, …) inside one `FaTransaction` per mutation call, the recurring row included, all on FrontAccounting's mysqli connection. `FaModelType` refuses every generated write that a Type has not routed. FrontAccounting's errors become `FA_REJECTED`; its warnings travel in the response's top-level `extensions.warnings`.

**Tech Stack:** PHP 7.4 floor (CI also 8.3), Slim 4 + slim/psr7, webonyx/graphql-php ^15.32.3, php-di ^6, saygoweb/anorm ^3.2.1, saygoweb/anorm-graphql ^0.2 (from its GitHub `vcs` repository), lcobucci/jwt ^4.0, PHPUnit 9.6, phpcs PSR-12, PHPStan level 5, Docker (`docker/fa-graphql`), FrontAccounting 2.4 upstream `master` (default) and the `cambell-prince/frontaccounting` fork `master-cp`.

**Spec:** `docs/superpowers/specs/2026-09-25-release-2-panel-design.md` (Release 2). Read it first. It builds on `docs/superpowers/specs/2026-09-21-foundation-design.md` (the Foundation spec); where the two differ, Release 2's wins. Background: `docs/spec-inputs.md`.

## Global Constraints

- PHP `^7.4 || ^8.0`: no enums, `readonly`, constructor promotion, `match`, named arguments or union types. Typed properties and arrow functions are fine.
- `webonyx/graphql-php ^15.32.3`, no `simpod/graphql-utils`. `saygoweb/anorm ^3.2.1`: never lower it. `saygoweb/anorm-graphql ^0.2` from the `vcs` repository `https://github.com/saygoweb/anorm-graphql`. `composer.lock` never points at a `path` source or a `dev-` version.
- **Generation defines the schema.** Entity, field and input names come from the models and `anorm-graphql`. Where this plan's hand-written test or README text disagrees with the generated schema, the generated schema wins: align the text, and record it. A capability generation lacks goes into `anorm-graphql` as a `0.x` release, never a workaround here.
- **Writes go only through FrontAccounting.** No generated write reaches a FrontAccounting table directly. Every writable Type routes create, update and delete to its `src/Fa/Service` class. `FaModelType` throws `Forbidden` for any write a Type has not routed (spec §2.2).
- **One side per mutation.** Every write in these mutations goes through FrontAccounting's mysqli connection, the `sales_recurring` row included. Nothing goes through the container's `\PDO`. Reads may use either (spec §2.3).
- **One `FaTransaction` per mutation call.** A batch (`input: [...]`) commits whole or not at all. `cancel_transaction()` runs on every throwable (spec §3.1).
- **One company per request** (Foundation spec §3.6). Writes need a token, and anonymous callers never reach a service.
- **Nothing reads request input from superglobals.** `Bootstrap` empties them. Values reach FrontAccounting as arguments; functions that read `$_POST` (`add_to_order`, the pages) are ported, not called.
- **Dates are explicit.** Every document date is set from the input; `new_doc_date()` is never relied on. `Date` is ISO `YYYY-MM-DD` in the API and converted to FrontAccounting's user date format before any FrontAccounting call (`DateConversion`).
- **Percentages are 0–100 in the API** and fractions in FrontAccounting. Numeric values FrontAccounting puts into SQL unquoted are cast.
- Every HTTP response body is JSON. `BAD_INPUT` carries `extensions.field` and, in a batch, `extensions.index`. `FA_REJECTED` carries `extensions.messages`.
- Runs on upstream FrontAccounting `master` and on the fork `master-cp`. Every suite runs on both, × PHP 7.4 and 8.3.
- Everything except generation runs in the container: `docker/fa-graphql test|lint|analyze|composer|exec`. `bin/generate` runs on the host (PHP 8.3, no Composer, no `pdo_mysql`). Suites: `--testsuite unit|integration|http`. Don't use `docker/fa-graphql ci` except where a step says to: it rebuilds the stack.
- PSR-12 for `src/`, `tests/`, `index.php`, `app.php`, `container.php`. `hooks.php` and the copied FrontAccounting files are excluded. Never hand-edit generated files (`src/Type/*/Base/*`, `ApiSchema.php` entries marked `// anorm-graphql`).
- Namespace `FA\GraphQL\` → `src/`, `FA\GraphQL\Tests\` → `tests/`. Every model's key property is `id`.
- Commit after every task. Messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Work on `feature/release-2`. Tasks 1–2 work in `../anorm-graphql` on its branch `feature/0.2`. Never push without the user's explicit OK.

## Review policy (from the user)

**Do not review after every task.** Reviews happen only at the four **Checkpoints**, each placed where a mistake gets expensive:

- **A**, after Task 2 and before `anorm-graphql` `v0.2.0` is pushed: a tag another repository depends on can't be taken back, and every later task generates against it.
- **B**, after Task 6: the first FrontAccounting writes (customers, branches, contacts). It proves the write plumbing (`FaTransaction`, messages, warnings, dates, fail-closed writes) before orders build on it.
- **C**, after Task 9: the transactional core. Orders, lines, versions, delete-versus-close and the recurring row share one transaction.
- **D**, after Task 10: the whole spec, the whole branch, and all four FrontAccounting × PHP combinations.

Each Checkpoint is: an independent reviewer (`/code-review medium`, or a reviewer subagent given a review package and the checklist) reviews the diff since the previous Checkpoint. Confirmed findings are fixed and re-checked. Then the listed spec sections are walked, and each requirement is confirmed or its deviation written into the spec, marked *(revised)*. With subagent-driven-development, skip its per-task review stages and use these Checkpoints instead.

## Review Focus

Inputs and conditions the spec implies but doesn't spell out, most likely to bite first. Each is pinned by a test in the task named.

1. **A batch where a later item is refused leaves an earlier item committed.** `customerCreate(input: [ok, bad])` must leave no customer `ok`, and a write later in the same request must still issue `BEGIN`: FrontAccounting's `check_db_error` rolls back without resetting `$transaction_level`. Pinned by `FaTransactionTest` (Task 3) and `CustomerServiceTest::testABatchIsAllOrNothing` (Task 5).
2. **FrontAccounting's user date format is not ISO.** `apitest`'s preferences decide how `date2sql()` reads a date: `dd/mm/yyyy` or `mm/dd/yyyy` and `.`/`-` separators. An ISO date passed straight through becomes the wrong day, or a silent zero date. Pinned by `DateConversionTest` over every FrontAccounting date format (Task 3) and an order-date round trip with a non-ISO user format (Task 7).
3. **A stale order version accepted.** `update_sales_order()` updates `WHERE version = …` but never checks affected rows, then rewrites the lines anyway. Two updates carrying the same version must not both succeed. Pinned by `SalesOrderServiceTest::testAStaleVersionIsRefused` (Task 8) and `PanelFlowTest` (Task 10).
4. **A recurring row left behind on delete.** `sgw_sales` has no delete hook, so `delete_sales_order()` orphans `sales_recurring`, and the orphan reattaches to a later order that reuses the number. Pinned by `RecurringScheduleTest::testDeletingTheOrderDeletesItsSchedule` and `…ClosingTheOrderEndsItsSchedule` (Task 9) and `PanelFlowTest` (Task 10).
5. **A generated writable Type written directly because its override is missing.** Without the override, `ModelType::resolveCreate` would write `debtors_master` through Anorm, bypassing references, audit and hooks. Pinned by `FaModelTypeTest::testEveryWriteIsRefusedUntilRouted` (Task 3) and, for every writable entity, a test that its Type overrides all three resolvers (Tasks 5, 6, 7).

## File map

```
../anorm-graphql (Tasks 1–2)    --mutations create-update, --input-only, Date scalar; tests, docs, CHANGELOG; tag v0.2.0
composer.json / composer.lock   saygoweb/anorm-graphql ^0.2 (Task 3)
bin/generate                    --mutations create-update; READONLY and INPUT_ONLY lists (Tasks 3–7)
src/Fa/FaTransaction.php        begin / commit / cancel_transaction on throw (Task 3)
src/Fa/FaMessages.php           keeps levels: errors(), warnings(), drainByLevel() (Task 3)
src/Fa/Warnings.php             per-request warnings for extensions.warnings (Task 3)
src/Fa/DateConversion.php       ISO <-> FrontAccounting user date format (Task 3)
src/Fa/FaSession.php            + isActive(string $package) (Task 3)
src/Fa/Service/ServiceCall.php  FaTransaction + messages -> FaRejected / Warnings (Task 3)
src/Fa/Service/CustomerService.php      (Task 5)
src/Fa/Service/BranchService.php        (Task 6)
src/Fa/Service/ContactService.php       (Task 6)
src/Fa/Service/SalesOrderService.php    create (Task 7); update, delete (Task 8)
src/Fa/Service/RecurringSchedule.php    (Task 9)
src/Error/BadInput.php          + field, index (Task 3)
src/Http/GraphQLAction.php      + extensions.warnings (Task 3)
src/Type/FaModelType.php        + fail-closed resolveCreate/Update/Delete/Upsert (Task 3)
src/Model/                      PaymentTerms, TaxGroup, SalesArea, Salesman, Location, Shipper, CreditStatus,
                                Currency, StockItem (Task 4); Customer (Task 5); Branch, Contact (Task 6);
                                SalesOrder, SalesOrderLine (Task 7) — each <Entity>Model.php
src/Type/<Entity>/              generated Base/* and once-only Type / Input(s) per entity (Tasks 4–7);
                                once-only overrides: CustomerType (branches, contacts, writes),
                                BranchType, ContactType (links), SalesOrderType (lines, recurring, writes),
                                CustomerCreateInput (branch, contact), Contact*Input (links),
                                SalesOrder*Input (lines, recurring, version)
src/ApiSchema.php               generated entries added by bin/generate (Tasks 4–7)
src/Type/SalesType/SalesTypeType.php    areas -> SA_SALESORDER (Task 4)
tests/Unit/Fa/                  FaTransactionTest, FaMessagesTest, WarningsTest, DateConversionTest (Task 3)
tests/Unit/Type/FaModelTypeTest.php     (Task 3)
tests/Integration/Service/      CustomerServiceTest, BranchServiceTest, ContactServiceTest,
                                SalesOrderServiceTest, RecurringScheduleTest (Tasks 5–9)
tests/Generated/                generated tests per entity (Tasks 4–7)
tests/Http/                     LookupsTest, PanelFlowTest (Task 10)
tests/data/seed.sql             SA_SALESORDER on the GraphQL API role (Task 4); GraphQL Orders role + apiorders (Task 10)
docker/fa-graphql               fiscal years up to the current year after db load (Task 4)
README.md, docker/README.md     the new API surface, the panel flow, seed users (Task 10)
```

---

### Task 1: anorm-graphql — `--mutations upsert|create-update`

**This task is done in the other repository, `/home/cambell/src/sgw/anorm-graphql`**, not in this module. Every command below runs from `/home/cambell/src/sgw/anorm-graphql` with that repository's own runner, `docker/anorm-graphql` (`up`, `test [phpunit args]`, `ci`, `php …`). Its history is linear (no merge commits: the branch lands on `main` by fast-forward) and its messages are Conventional-Commit style (`feat:`, `docs:`, `fix:`, `test:`); follow both. Tasks 1 and 2 share the branch `feature/0.2`.

Spec: Release 2 spec §6.1. Default output must stay **byte-identical**: every existing golden file and every existing test passes unchanged.

**Files (in `anorm-graphql`):**
- Modify: `tools/src/TypeInfo.php`, `tools/src/TypeInfoBuilder.php`, `tools/src/TypeMakerOptions.php`, `tools/src/TypeMaker.php`, `tools/src/Writer/InputBaseWriter.php`, `tools/src/Writer/InputWriter.php`, `tools/src/Writer/TypeWriter.php`, `tools/src/Writer/TestWriter.php`, `tools/src/Schema/SchemaEditor.php`, `bin/anorm-graphql.php`, `src/ModelType.php`, `src/Testing/ModelTypeTestCase.php`, `test/TestEnvironment.php`
- Create: `test/Fixtures/CalendarModel/EventModel.php`, golden files `test/Fixtures/golden/{WidgetCreateInputBase,WidgetCreateInput,WidgetUpdateInputBase,WidgetUpdateInput,WidgetTypeCreateUpdate,WidgetTypeTestCreateUpdate,EventCreateInputBase}.txt`, `test/integration/CreateUpdateEndToEndTest.php`
- Test: `test/tools/TypeInfoBuilderTest.php`, `test/tools/WritersTest.php`, `test/tools/TypeMakerTest.php`, `test/tools/CliTest.php`, `test/integration/ModelTypeTest.php`, `test/integration/CreateUpdateEndToEndTest.php`

**Interfaces:**
- Consumes: nothing from the module.
- Produces (Tasks 3–9 of this plan and the module's `bin/generate` rely on exactly this):
  - CLI option `--mutations <upsert|create-update>`, long form only, default `upsert`. Any other value: exit 2, one line `Error: --mutations '<value>' must be 'upsert' or 'create-update'`, nothing written.
  - `TypeMakerOptions::$mutations` (string, default `'upsert'`); `TypeInfo::$mutations` (`'upsert'` or `'create-update'`) and `TypeInfo::$required` (string[]: non-key properties whose docblock has `@required`).
  - With `create-update`, per writable (non-`--readonly`) entity, instead of `Base/<E>InputBase.php` + `<E>Input.php`: `Base/<E>CreateInputBase.php`, `Base/<E>UpdateInputBase.php` (regenerated, GraphQL names `<E>CreateInput` / `<E>UpdateInput`) and `<E>CreateInput.php`, `<E>UpdateInput.php` (once-only, `fields()` hook). `<E>CreateInput` has no key field, and a `@required` property is non-null. `<E>UpdateInput` has the key `ID!`; everything else nullable.
  - `ApiSchema.php` entries `<e>Create(input: [<E>CreateInput!]!): [<E>Type!]!` → `resolveCreate`, `<e>Update(input: [<E>UpdateInput!]!): [<E>Type!]!` → `resolveUpdate`, `<e>Delete` as before; no `<e>Upsert`. A marked `<e>Upsert` entry left from an earlier run is reported `orphaned`, never deleted.
  - Runtime `Anorm\GraphQL\ModelType::resolveCreate($root, $args, Container $context): array` and `::resolveUpdate($root, $args, Container $context): array`, both over `$args['input']` (a list), all-or-nothing like `resolveUpsert`. A create whose input carries a non-empty key is a `UserError` (`<Type> create does not take '<key>'; to change a row, update it`). An update without a key is a `UserError` (`<Type> update needs '<key>'`); an update of a key that does not exist is the existing `UserError` (`<Type> id '…' not found`). An update sets only the fields present in the input. `authorize()` sees `create` / `edit`, `beforeWrite()` sees `$isUpdate` false / true, as for upsert.
  - `Anorm\GraphQL\Testing\ModelTypeTestCase`: `protected function updateInputClass(): ?string` (default `null` = upsert mode), `protected function requiredFields(): array` (default `[]`), `protected function create(array $inputs): array`, `protected function update(array $inputs): array`. When `updateInputClass()` is not null, `testInputMirrorsTheType` checks both inputs and `testLifecycle` creates with `<e>Create` and updates with `<e>Update`, and checks that fields the update does not name are left as they were.
  - Generated `<E>TypeTest` under `create-update`: `inputClass()` returns `<E>CreateInput::class`, and it also declares `updateInputClass()` (returning `<E>UpdateInput::class`) and `requiredFields()`.

- [ ] **Step 1: Branch and check the suite is green**

```bash
cd /home/cambell/src/sgw/anorm-graphql
git switch main && git pull --ff-only
git log --oneline -1          # expect 8c928ef fix: Checkpoint C review (v0.1.0)
git switch -c feature/0.2
docker/anorm-graphql up
docker/anorm-graphql test
docker/anorm-graphql test --testsuite integration
```

Expected: both runs pass before any change. If not, stop and report; do not build on a red suite.

- [ ] **Step 2: A fixture model with a required column, and its table**

`test/Fixtures/CalendarModel/EventModel.php` (its own folder, so the `Fixtures/Model` runs, their goldens and README examples are untouched):

```php
<?php

namespace Anorm\GraphQL\Test\Fixtures\CalendarModel;

use Anorm\DataMapper;
use Anorm\Model;

/** A model with a column that a create must supply: `@required`. */
class EventModel extends Model
{
    public function __construct(\PDO $pdo)
    {
        parent::__construct($pdo, DataMapper::create($pdo, 'events', DataMapper::autoMap($this)));
    }

    /** @var int */
    public $id;

    /**
     * @var string
     * @required
     */
    public $title;

    /** @var string */
    public $notes;
}
```

`test/TestEnvironment.php`, in `createTables()`: add `$pdo->exec('DROP TABLE IF EXISTS `events`');` beside the other drops, and after the `widgets` table:

```php
        $pdo->exec(
            "CREATE TABLE `events` (
                `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `title` VARCHAR(255) NOT NULL,
                `notes` VARCHAR(255) NULL
            ) ENGINE=InnoDB"
        );
```

- [ ] **Step 3: Write the failing tests — tools**

`test/tools/TypeInfoBuilderTest.php` — add, with `use Anorm\GraphQL\Test\Fixtures\CalendarModel\EventModel;`:

```php
    public function testARequiredPropertyIsNamedAndTheKeyNeverIs(): void
    {
        $info = (new TypeInfoBuilder())->build(new EventModel(new NullPdo()));
        $this->assertSame(['title'], $info->required);
        $this->assertSame('upsert', $info->mutations, 'the default');
        $this->assertSame([], (new TypeInfoBuilder())->build(new WidgetModel(new NullPdo()))->required);
    }
```

`test/tools/WritersTest.php` — add, with `use Anorm\GraphQL\Test\Fixtures\CalendarModel\EventModel;`:

```php
    private function createUpdate(TypeInfo $info): TypeInfo
    {
        $info->mutations = 'create-update';
        return $info;
    }

    public function testCreateInputBaseHasNoKey(): void
    {
        $code = (new InputBaseWriter())->render($this->createUpdate($this->info()), 'App\GraphQL\Type', 'Create');
        $this->assertGolden('WidgetCreateInputBase', $code);
        $this->assertStringNotContainsString("create('id'", $code);
    }

    public function testUpdateInputBaseRequiresTheKeyAndNothingElse(): void
    {
        $code = (new InputBaseWriter())->render($this->createUpdate($this->info()), 'App\GraphQL\Type', 'Update');
        $this->assertGolden('WidgetUpdateInputBase', $code);
        $this->assertStringContainsString("FieldBuilder::create('id', Type::nonNull(Type::id()))->build(),", $code);
        $this->assertSame(1, substr_count($code, 'Type::nonNull('));
    }

    public function testCreateAndUpdateInputs(): void
    {
        $info = $this->createUpdate($this->info());
        $this->assertGolden('WidgetCreateInput', (new InputWriter())->render($info, 'App\GraphQL\Type', 'Create'));
        $this->assertGolden('WidgetUpdateInput', (new InputWriter())->render($info, 'App\GraphQL\Type', 'Update'));
    }

    public function testARequiredPropertyIsNonNullOnCreateOnly(): void
    {
        $info = $this->createUpdate((new TypeInfoBuilder())->build(new EventModel(new NullPdo())));
        $create = (new InputBaseWriter())->render($info, 'App\GraphQL\Type', 'Create');
        $this->assertGolden('EventCreateInputBase', $create);
        $this->assertStringContainsString("FieldBuilder::create('title', Type::nonNull(Type::string()))->build(),", $create);
        $update = (new InputBaseWriter())->render($info, 'App\GraphQL\Type', 'Update');
        $this->assertStringContainsString("FieldBuilder::create('title', Type::string())->build(),", $update);
    }

    public function testTheDefaultKindIsTheUpsertInput(): void
    {
        $info = $this->info();
        $this->assertSame(
            (new InputBaseWriter())->render($info, 'App\GraphQL\Type'),
            (new InputBaseWriter())->render($info, 'App\GraphQL\Type', '')
        );
    }

    public function testTypeUnderCreateUpdate(): void
    {
        $code = (new TypeWriter())->render($this->createUpdate($this->info()), 'App\GraphQL\Type');
        $this->assertGolden('WidgetTypeCreateUpdate', $code);
        $this->assertStringContainsString('resolveCreate / resolveUpdate', $code);
    }

    public function testTestUnderCreateUpdate(): void
    {
        $info = $this->createUpdate((new TypeInfoBuilder())->build(new EventModel(new NullPdo())));
        $code = (new TestWriter())->render($info, 'App\GraphQL\Type', 'Tests\GraphQL');
        $this->assertGolden('WidgetTypeTestCreateUpdate', $code);
        $this->assertStringContainsString('return EventCreateInput::class;', $code);
        $this->assertStringContainsString('return EventUpdateInput::class;', $code);
        $this->assertMatchesRegularExpression("/function requiredFields\(\): array\s+\{\s+return \[\s+'title',\s+\];/", $code);
    }
```

(The golden for the Event test is named `WidgetTypeTestCreateUpdate` only for symmetry with the other create-update goldens; call it `EventTypeTestCreateUpdate` if you prefer, and name it so in both places.)

`test/tools/TypeMakerTest.php` — add, with `use Anorm\GraphQL\Tools\TypeMakerOptions;` already imported:

```php
    private function createUpdate(): TypeMakerOptions
    {
        $o = $this->options();
        $o->mutations = 'create-update';
        return $o;
    }

    public function testCreateUpdateWritesTwoInputsAndTheirMutations(): void
    {
        $this->make($this->createUpdate());
        foreach (
            [
                'src/Type/Widget/Base/WidgetCreateInputBase.php',
                'src/Type/Widget/Base/WidgetUpdateInputBase.php',
                'src/Type/Widget/WidgetCreateInput.php',
                'src/Type/Widget/WidgetUpdateInput.php',
            ] as $file
        ) {
            $this->assertFileExists("$this->dir/$file");
        }
        $this->assertFileDoesNotExist("$this->dir/src/Type/Widget/WidgetInput.php");
        $schema = file_get_contents("$this->dir/src/ApiSchema.php");
        foreach (["'widgetCreate'", "'widgetUpdate'", "'widgetDelete'", "'resolveCreate'", "'resolveUpdate'", 'WidgetCreateInput::class', 'WidgetUpdateInput::class'] as $text) {
            $this->assertStringContainsString($text, $schema);
        }
        $this->assertStringNotContainsString("'widgetUpsert'", $schema);
        $this->assertStringNotContainsString("'ownerUpsert'", $schema);
    }

    public function testCreateUpdateLeavesReadOnlyEntitiesAlone(): void
    {
        $o = $this->createUpdate();
        $o->readOnly = ['Owner'];
        $this->make($o);
        $this->assertFileDoesNotExist("$this->dir/src/Type/Owner/OwnerCreateInput.php");
        $this->assertStringNotContainsString("'ownerCreate'", file_get_contents("$this->dir/src/ApiSchema.php"));
    }

    public function testSwitchingToCreateUpdateReportsTheOldInputAndEntryAndDeletesNothing(): void
    {
        $this->make($this->options());
        $report = implode("\n", $this->make($this->createUpdate())->report);
        foreach (["$this->dir/src/Type/Widget/WidgetInput.php", "$this->dir/src/Type/Widget/Base/WidgetInputBase.php"] as $path) {
            $this->assertStringContainsString("orphaned $path ('Widget' uses create-update mutations now; not deleted)", $report);
            $this->assertFileExists($path);
        }
        $this->assertStringContainsString("orphaned: 'widgetUpsert' is marked as generated but no model produces it", $report);
        $this->assertStringContainsString("'widgetUpsert'", file_get_contents("$this->dir/src/ApiSchema.php"));
    }

    public function testAnUnknownMutationsValueIsAnErrorAndWritesNothing(): void
    {
        $o = $this->options();
        $o->mutations = 'upsert,create';
        $maker = new TypeMaker($o);
        $this->assertSame(2, $maker->run());
        $this->assertSame(["Error: --mutations 'upsert,create' must be 'upsert' or 'create-update'"], $maker->report);
        $this->assertDirectoryDoesNotExist("$this->dir/src");
    }

    public function testASecondCreateUpdateRunChangesNothing(): void
    {
        $this->make($this->createUpdate());
        foreach ($this->make($this->createUpdate())->report as $line) {
            $this->assertMatchesRegularExpression('/^(current|kept|skipped)/', $line);
        }
    }

    public function testWithoutMutationsTheOutputIsUnchanged(): void
    {
        $this->make($this->options());
        $this->assertFileExists("$this->dir/src/Type/Widget/WidgetInput.php");
        $this->assertFileDoesNotExist("$this->dir/src/Type/Widget/WidgetCreateInput.php");
        $this->assertStringContainsString("'widgetUpsert'", file_get_contents("$this->dir/src/ApiSchema.php"));
    }
```

`test/tools/CliTest.php` — add:

```php
    public function testCreateUpdateEndToEnd(): void
    {
        $arguments = $this->make();
        $arguments[array_search('-t', $arguments, true) + 1] = 'CliCu\Type';
        $arguments[] = '--mutations';
        $arguments[] = 'create-update';
        [$exit, $output] = $this->cli($arguments);
        $this->assertSame(0, $exit, $output);

        foreach (['Create', 'Update'] as $kind) {
            require_once "$this->dir/Type/Widget/Base/Widget{$kind}InputBase.php";
            require_once "$this->dir/Type/Widget/Widget{$kind}Input.php";
        }
        $create = new \CliCu\Type\Widget\WidgetCreateInput();
        $update = new \CliCu\Type\Widget\WidgetUpdateInput();
        $this->assertSame('WidgetCreateInput', $create->name);
        $this->assertArrayNotHasKey('id', $create->getFields());
        $this->assertSame('ID!', (string) $update->getField('id')->getType());
    }

    public function testABadMutationsValueExitsTwoAndWritesNothing(): void
    {
        [$exit, $output] = $this->cli(array_merge($this->make(), ['--mutations', 'create']));
        $this->assertSame(2, $exit, $output);
        $this->assertStringContainsString("Error: --mutations 'create' must be 'upsert' or 'create-update'", $output);
        $this->assertSame(['.', '..'], scandir($this->dir), 'nothing may be written');
    }

    public function testHelpNamesTheMutationsOption(): void
    {
        [$exit, $output] = $this->cli(['--help']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('--mutations', $output);
        $this->assertStringContainsString('[default: upsert]', $output);
    }
```

- [ ] **Step 4: Write the failing tests — runtime and end to end**

`test/integration/ModelTypeTest.php` — add (the `RecordingWidgetType` fixture and helpers are already there):

```php
    /**
     * @param array<int, array<string, mixed>> $inputs
     * @return array<int, array<string, mixed>>
     */
    private function create(array $inputs): array
    {
        return $this->type->resolveCreate(null, ['input' => $inputs], $this->context);
    }

    /**
     * @param array<int, array<string, mixed>> $inputs
     * @return array<int, array<string, mixed>>
     */
    private function update(array $inputs): array
    {
        return $this->type->resolveUpdate(null, ['input' => $inputs], $this->context);
    }

    public function testCreateMakesRowsAndReturnsThem(): void
    {
        $rows = $this->create([['name' => 'a', 'quantity' => 1], ['name' => 'b']]);
        $this->assertCount(2, $rows);
        $this->assertNotEmpty($rows[0]['id']);
        $this->assertSame(2, $this->rowCount());
        $this->assertSame([['create', null], ['create', null]], $this->type->authorized);
        $this->assertSame([['a', false], ['b', false]], $this->type->written);
    }

    public function testCreateWithAKeyIsAClientSafeErrorAndWritesNothing(): void
    {
        $id = $this->create([['name' => 'a']])[0]['id'];
        try {
            $this->create([['name' => 'b'], ['id' => $id, 'name' => 'c']]);
            $this->fail('expected a refusal');
        } catch (UserError $e) {
            $this->assertSame("WidgetType create does not take 'id'; to change a row, update it", $e->getMessage());
        }
        $this->assertSame(1, $this->rowCount(), 'all or nothing');
    }

    public function testAnEmptyOrNullKeyOnCreateIsNoKey(): void
    {
        $this->assertCount(2, $this->create([['id' => '', 'name' => 'a'], ['id' => null, 'name' => 'b']]));
    }

    public function testUpdateChangesOnlyWhatItNames(): void
    {
        $id = $this->create([['name' => 'a', 'quantity' => 7]])[0]['id'];
        $rows = $this->update([['id' => $id, 'name' => 'a2']]);
        $this->assertSame('a2', $rows[0]['name']);
        $this->assertEquals(7, $rows[0]['quantity'], 'a field the update does not name is left as it was');
        $this->assertSame(1, $this->rowCount());
        $this->assertSame([['create', null], ['edit', (int) $id]], $this->type->authorized);
        $this->assertSame([['a', false], ['a2', true]], $this->type->written);
    }

    public function testUpdateWithoutAKeyIsAClientSafeError(): void
    {
        $this->expectException(UserError::class);
        $this->expectExceptionMessage("WidgetType update needs 'id'");
        $this->update([['name' => 'x']]);
    }

    public function testUpdateOfAnUnknownKeyIsAClientSafeError(): void
    {
        $this->expectException(UserError::class);
        $this->expectExceptionMessage("WidgetType id '999999' not found");
        $this->update([['id' => '999999', 'name' => 'x']]);
    }

    public function testOneFailingRowRollsBackTheWholeUpdate(): void
    {
        $ids = array_column($this->create([['name' => 'a'], ['name' => 'b']]), 'id');
        $this->type->failOnName = 'b2';
        try {
            $this->update([['id' => $ids[0], 'name' => 'a2'], ['id' => $ids[1], 'name' => 'b2']]);
            $this->fail('expected a failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('beforeWrite failed on b2', $e->getMessage());
        }
        $this->assertSame(['a', 'b'], $this->names([]));
    }

    public function testCreateAndUpdateStayWithinTheScope(): void
    {
        $type = new \Anorm\GraphQL\Test\Fixtures\Type\ScopedDocumentType();
        $this->expectException(UserError::class);
        $type->resolveCreate(null, ['input' => [['type' => 99, 'title' => 'x']]], $this->context);
    }
```

Before writing `testCreateAndUpdateStayWithinTheScope`, read `test/Fixtures/Type/ScopedDocumentType.php` and `testAnInputCannotMoveARowOutOfItsScope`: use the scope property and a value that test uses, so the expected `UserError` is the scope refusal (`'type' is fixed for …`). Assert that message with `expectExceptionMessage`.

`test/integration/CreateUpdateEndToEndTest.php` — generate with `create-update` from the calendar fixture, run the generated tests, and run GraphQL through the generated schema:

```php
<?php

namespace Anorm\GraphQL\Test\Integration;

use Anorm\GraphQL\Test\TempDir;
use Anorm\GraphQL\Test\TestEnvironment;
use Anorm\GraphQL\Tools\TypeMaker;
use Anorm\GraphQL\Tools\TypeMakerOptions;
use PHPUnit\Framework\TestCase;

/** `--mutations create-update`, generated and then used for real. */
class CreateUpdateEndToEndTest extends TestCase
{
    use TempDir;

    public static function setUpBeforeClass(): void
    {
        TestEnvironment::createTables();
    }

    protected function setUp(): void
    {
        $this->makeTempDir();
        $o = new TypeMakerOptions();
        $o->modelsDir = dirname(__DIR__) . '/Fixtures/CalendarModel';
        $o->modelNamespace = 'Anorm\GraphQL\Test\Fixtures\CalendarModel';
        $o->outputDir = "$this->dir/src/Type";
        $o->typeNamespace = 'Cu\Type';
        $o->testsDir = "$this->dir/tests";
        $o->testNamespace = 'Cu\Tests';
        $o->schemaPath = "$this->dir/src/ApiSchema.php";
        $o->schemaNamespace = 'Cu';
        $o->mutations = 'create-update';
        $maker = new TypeMaker($o);
        $this->assertSame(0, $maker->run(), implode("\n", $maker->report));

        file_put_contents("$this->dir/bootstrap.php", <<<'PHP'
<?php
$loader = require getenv('CU_AUTOLOAD');
$loader->addPsr4('Cu\\Tests\\', __DIR__ . '/tests/');
$loader->addPsr4('Cu\\', __DIR__ . '/src/');
PHP
        );
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    /** @return array{0: int, 1: string} */
    private function runPhp(string $command): array
    {
        $env = 'CU_AUTOLOAD=' . escapeshellarg(dirname(__DIR__, 2) . '/vendor/autoload.php');
        exec("$env $command 2>&1", $output, $exit);
        return [$exit, implode("\n", $output)];
    }

    public function testTheGeneratedTestsPassThroughCreateAndUpdate(): void
    {
        $phpunit = dirname(__DIR__, 2) . '/vendor/bin/phpunit';
        [$exit, $output] = $this->runPhp(
            'php ' . escapeshellarg($phpunit) . ' --no-configuration --verbose --bootstrap '
            . escapeshellarg("$this->dir/bootstrap.php") . ' ' . escapeshellarg("$this->dir/tests")
        );
        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('OK (', $output);
        $this->assertStringNotContainsString('Skipped', $output);
        $this->assertStringNotContainsString('Incomplete', $output);
    }

    public function testCreateAndUpdateThroughTheGeneratedSchema(): void
    {
        file_put_contents("$this->dir/query.php", <<<'PHP'
<?php
require __DIR__ . '/bootstrap.php';
$container = Anorm\GraphQL\Test\TestEnvironment::container();
$schema = new Cu\ApiSchema($container);
$schema->assertValid();
$run = function ($query, $variables = []) use ($schema, $container) {
    return GraphQL\GraphQL::executeQuery($schema, $query, null, $container, $variables)
        ->toArray(GraphQL\Error\DebugFlag::INCLUDE_DEBUG_MESSAGE);
};
$pdo = $container->get(PDO::class);
$pdo->beginTransaction();
$out = [];
$out['create'] = $run(
    'mutation ($input: [EventCreateInput!]!) { eventCreate(input: $input) { id title notes } }',
    ['input' => [['title' => 'launch', 'notes' => 'n']]]
);
$id = $out['create']['data']['eventCreate'][0]['id'];
$out['update'] = $run(
    'mutation ($input: [EventUpdateInput!]!) { eventUpdate(input: $input) { id title notes } }',
    ['input' => [['id' => $id, 'title' => 'launch 2']]]
);
$out['missingTitle'] = $run(
    'mutation ($input: [EventCreateInput!]!) { eventCreate(input: $input) { id } }',
    ['input' => [['notes' => 'no title']]]
);
$out['updateWithoutId'] = $run(
    'mutation ($input: [EventUpdateInput!]!) { eventUpdate(input: $input) { id } }',
    ['input' => [['title' => 'x']]]
);
$out['mutations'] = array_keys($schema->getMutationType()->getFields());
$pdo->rollBack();
echo json_encode($out);
PHP
        );
        [$exit, $output] = $this->runPhp('php ' . escapeshellarg("$this->dir/query.php"));
        $this->assertSame(0, $exit, $output);
        $out = json_decode($output, true);
        $this->assertIsArray($out, $output);

        $this->assertArrayNotHasKey('errors', $out['create'], $output);
        $this->assertArrayNotHasKey('errors', $out['update'], $output);
        $this->assertSame('launch 2', $out['update']['data']['eventUpdate'][0]['title']);
        $this->assertSame('n', $out['update']['data']['eventUpdate'][0]['notes'], 'update changes only what it names');
        $this->assertArrayHasKey('errors', $out['missingTitle'], 'title is required on create');
        $this->assertArrayHasKey('errors', $out['updateWithoutId'], 'an update names its row');
        sort($out['mutations']);
        $this->assertSame(['eventCreate', 'eventDelete', 'eventUpdate'], $out['mutations']);
    }
}
```

- [ ] **Step 5: Run them to see them fail**

```bash
docker/anorm-graphql test --testsuite tools
docker/anorm-graphql test --testsuite integration --filter 'ModelTypeTest|CreateUpdateEndToEndTest'
```

Expected: FAIL — `Undefined property: …TypeInfo::$required` / `$mutations`, missing golden files, `Call to undefined method …resolveCreate()`, `Unexpected argument '--mutations'`. Nothing that passed before fails for another reason.

- [ ] **Step 6: Implement — options and TypeInfo**

`tools/src/TypeInfo.php` — add after `$readOnly`:

```php
    /** @var string 'upsert', or 'create-update' for separate create and update mutations */
    public $mutations = 'upsert';
    /** @var string[] Non-key properties a create must supply: their docblock says `@required` */
    public $required = array();
```

`tools/src/TypeMakerOptions.php` — add after `$typeBase`:

```php
    /** @var string 'upsert', or 'create-update' for <entity>Create and <entity>Update */
    public $mutations = 'upsert';
```

`tools/src/TypeInfoBuilder.php` — in `build()`, right after `$info->fields[$property] = $this->graphQLType($property, $key, $type);`:

```php
            if ($property !== $key && $this->isRequired($class, $property)) {
                $info->required[] = $property;
            }
```

and add the method:

```php
    /**
     * Whether a create must supply this property: its docblock carries `@required`.
     * The model says so; the generator never asks the database.
     */
    private function isRequired($class, $property)
    {
        $doc = (new \ReflectionProperty($class, $property))->getDocComment();
        return \is_string($doc) && \preg_match('/@required\b/', $doc) === 1;
    }
```

`bin/anorm-graphql.php` — register the option after `type-base`:

```php
        $arguments->addOption('mutations', array('default' => $defaults->mutations, 'description' => 'upsert, or create-update for separate mutations'));
```

(The description must stay at or under 59 characters, or `--help` wraps it mid-word.) In `makerOptions()`, after `typeBase`:

```php
        $o->mutations = (string) $this->options['mutations'];
```

- [ ] **Step 7: Implement — the writers**

`tools/src/Writer/InputBaseWriter.php` — replace `render()`:

```php
    /**
     * @param string $kind '' for the upsert Input, 'Create' or 'Update'. '' keeps the output
     *   exactly as it always was.
     */
    public function render(TypeInfo $info, $typeNamespace, $kind = '')
    {
        $namespace = Php::entityNamespace($typeNamespace, $info->entity) . '\\Base';
        $name = $info->entity . $kind . 'Input';
        $fields = '';
        foreach ($info->fields as $field => $type) {
            $call = Php::TYPE_CALLS[$type];
            if ($kind === 'Create') {
                if ($field === $info->keyProperty) {
                    // A create makes the key.
                    continue;
                }
                if (\in_array($field, $info->required, true)) {
                    $call = 'Type::nonNull(' . $call . ')';
                }
            } elseif ($kind === 'Update' && $field === $info->keyProperty) {
                // An update names its row.
                $call = 'Type::nonNull(' . $call . ')';
            }
            // Upsert: the key is nullable, because an input without one is a create.
            $fields .= "            FieldBuilder::create('$field', $call)->build(),\n";
        }
        $header = Php::HEADER;
        return <<<PHP
<?php

$header — do not edit. Changes belong in {$name}.php.

namespace $namespace;

use Anorm\GraphQL\Builder\FieldBuilder;
use Anorm\GraphQL\Builder\ObjectBuilder;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\Type;

abstract class {$name}Base extends InputObjectType
{
    public function __construct()
    {
        parent::__construct(
            ObjectBuilder::create('{$name}')->setFields(\$this->fields())->build()
        );
    }

    protected function fields(): array
    {
        return [
$fields        ];
    }
}

PHP;
    }
```

`tools/src/Writer/InputWriter.php` — replace `render()`:

```php
    /** @param string $kind '' for the upsert Input, 'Create' or 'Update' */
    public function render(TypeInfo $info, $typeNamespace, $kind = '')
    {
        $namespace = Php::entityNamespace($typeNamespace, $info->entity);
        $name = $info->entity . $kind . 'Input';
        return <<<PHP
<?php

namespace $namespace;

use $namespace\\Base\\{$name}Base;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 *
 * Override fields() to add or remove input fields:
 * array_merge(parent::fields(), [...])
 */
class {$name} extends {$name}Base
{
}

PHP;
    }
```

`tools/src/Writer/TypeWriter.php` — in `render()`, before the heredoc:

```php
        $resolvers = $info->mutations === 'create-update'
            ? 'resolveList / resolveCreate / resolveUpdate / resolveDelete'
            : 'resolveList / resolveUpsert / resolveDelete';
```

and in the heredoc replace ` *  - resolveList / resolveUpsert / resolveDelete   replace a resolver outright` with ` *  - $resolvers   replace a resolver outright`. The upsert text is unchanged.

`tools/src/Writer/TestWriter.php` — in `render()`, replace the block that builds `$uses` and `$inputClass` with:

```php
        $uses = "use $entityNamespace\\{$info->entity}Type;\n";
        $inputClass = 'null';
        $createUpdate = '';
        if (!$info->readOnly && $info->mutations === 'create-update') {
            $uses = "use $entityNamespace\\{$info->entity}CreateInput;\n"
                . "use $entityNamespace\\{$info->entity}Type;\n"
                . "use $entityNamespace\\{$info->entity}UpdateInput;\n";
            $inputClass = "{$info->entity}CreateInput::class";
            $required = '';
            foreach ($info->required as $name) {
                $required .= "            " . Php::export($name) . ",\n";
            }
            $createUpdate = <<<PHP

    protected function updateInputClass(): ?string
    {
        return {$info->entity}UpdateInput::class;
    }

    protected function requiredFields(): array
    {
        return [
$required        ];
    }

PHP;
        } elseif (!$info->readOnly) {
            $uses = "use $entityNamespace\\{$info->entity}Input;\n" . $uses;
            $inputClass = "{$info->entity}Input::class";
        }
```

and in the heredoc put `$createUpdate` directly after the closing brace of `inputClass()`:

```php
    protected function inputClass(): ?string
    {
        return $inputClass;
    }
$createUpdate
    protected function entityName(): string
```

The line `$createUpdate` replaces the blank line that separated `inputClass()` from `entityName()`. With `$createUpdate === ''` that line renders as the same blank line, so the upsert output is unchanged (`testTest` against the existing golden proves it). Under create-update the `$createUpdate` heredoc starts with an empty line and ends with `    }` plus a newline, so the methods come out separated by single blank lines.

A required field that is also a foreign key (an `ID`) is left out of `sampleInput()` like every foreign key, so the create would fail: extend the foreign-key note so it says which required fields are missing:

```php
        $missing = \array_values(\array_intersect($info->required, $foreignKeys));
        if ($missing) {
            $foreignKeyNote .= "        // Required on create, so the lifecycle test fails until they are added: "
                . \implode(', ', $missing) . "\n";
        }
```

(placed after `$foreignKeyNote` is built).

- [ ] **Step 8: Implement — TypeMaker and the schema**

`tools/src/TypeMaker.php`:

1. In `run()`, after the `typeBaseProblem` check:

```php
        if (!\in_array($o->mutations, array('upsert', 'create-update'), true)) {
            $this->report[] = "Error: --mutations '{$o->mutations}' must be 'upsert' or 'create-update'";
            return 2;
        }
```

2. Where each `TypeInfo` is built, set its mode:

```php
            $info = $builder->build($model, \in_array($entity, $readOnly, true));
            if ($info !== null) {
                $info->mutations = $o->mutations;
                $infos[] = $info;
            }
```

3. In `writeEntity()`, replace the `if (!$info->readOnly) { … }` block:

```php
        if (!$info->readOnly) {
            foreach ($this->inputKinds($info) as $kind) {
                $generated["$dir/Base/{$info->entity}{$kind}InputBase.php"] = (new InputBaseWriter())->render($info, $o->typeNamespace, $kind);
                $once["$dir/{$info->entity}{$kind}Input.php"] = (new InputWriter())->render($info, $o->typeNamespace, $kind);
            }
        }
```

4. Add:

```php
    /** @return string[] '' for the upsert Input, or 'Create' and 'Update' */
    private function inputKinds(TypeInfo $info)
    {
        return $info->mutations === 'create-update' ? array('Create', 'Update') : array('');
    }
```

5. Replace `staleInputs()`:

```php
    /**
     * Input files on disk that this run does not produce for the entity: it became
     * read-only, or changed between upsert and create-update.
     *
     * @return string[]
     */
    private function staleInputs(TypeInfo $info)
    {
        $dir = $this->join($this->options->outputDir, $info->entity);
        $made = $info->readOnly ? array() : $this->inputKinds($info);
        $stale = array();
        foreach (array('', 'Create', 'Update') as $kind) {
            if (\in_array($kind, $made, true)) {
                continue;
            }
            foreach (array("$dir/Base/{$info->entity}{$kind}InputBase.php", "$dir/{$info->entity}{$kind}Input.php") as $path) {
                if (\file_exists($path)) {
                    $stale[] = $path;
                }
            }
        }
        return $stale;
    }
```

6. Where the stale inputs are reported in `run()`:

```php
        foreach ($infos as $info) {
            $why = $info->readOnly ? 'is read-only now' : "uses {$info->mutations} mutations now";
            foreach ($this->staleInputs($info) as $path) {
                $this->report[] = "orphaned $path ('{$info->entity}' $why; not deleted)";
            }
        }
```

`tools/src/Schema/SchemaEditor.php`:

1. Both `foreach (array('List', 'Delete', 'Upsert') as $suffix)` loops in `edit()` become `foreach (array('List', 'Create', 'Delete', 'Update', 'Upsert') as $suffix)`.

2. `fieldNames()`:

```php
    /**
     * @return array<string, array<string, string>> root key => kind ('List', 'Create', 'Delete', 'Update', 'Upsert') => field name
     */
    private function fieldNames(TypeInfo $info)
    {
        $prefix = $info->fieldPrefix();
        $names = array('query' => array('List' => $prefix . 'List'), 'mutation' => array());
        if (!$info->readOnly) {
            $kinds = $info->mutations === 'create-update' ? array('Create', 'Delete', 'Update') : array('Delete', 'Upsert');
            foreach ($kinds as $kind) {
                $names['mutation'][$kind] = $prefix . $kind;
            }
        }
        return $names;
    }
```

3. In `entryLines()`, the final `else` branch builds the input argument for any of `Upsert`, `Create`, `Update`:

```php
        } else {
            $t = $name(self::TYPE);
            $input = $name($base . ($kind === 'Upsert' ? '' : $kind) . 'Input');
            $argument = "->addArgument('input', {$t}::nonNull({$t}::listOf({$t}::nonNull(\$this->type({$input}::class)))))";
        }
```

and its docblock says `@param string $kind 'List', 'Create', 'Delete', 'Update' or 'Upsert'`.

- [ ] **Step 9: Implement — the runtime**

`src/ModelType.php` — replace `resolveUpsert()` with the three resolvers over one private `write()`. `resolveUpsert` behaves exactly as before:

```php
    public function resolveUpsert($root, $args, Container $context): array
    {
        return $this->write($args['input'], $context, 'upsert');
    }

    /** Create rows. An input may not carry the key: a create makes it. */
    public function resolveCreate($root, $args, Container $context): array
    {
        return $this->write($args['input'], $context, 'create');
    }

    /** Update rows by key. Only the fields an input names are changed. */
    public function resolveUpdate($root, $args, Container $context): array
    {
        return $this->write($args['input'], $context, 'update');
    }

    /**
     * @param array<int, array<string, mixed>> $inputs
     * @param string $mode 'upsert', 'create' or 'update'
     * @return array<int, array<string, mixed>>
     */
    private function write(array $inputs, Container $context, string $mode): array
    {
        $probe = $this->newModel($context);
        $this->assertStaticMode($probe);
        $key = $probe->mapper()->modelPrimaryKey;
        $scope = $this->scope();
        // Never taken from the input: the key of an existing row comes from the row,
        // and scope properties are fixed.
        $notFromInput = array_merge([$key], array_keys($scope));

        return $this->transactional($probe->getPdo(), function () use ($inputs, $context, $key, $scope, $notFromInput, $mode) {
            $rows = [];
            foreach ($inputs as $input) {
                $this->assertInputWithinScope($input, $scope);
                $hasKey = isset($input[$key]) && $input[$key] !== '';
                if ($mode === 'create' && $hasKey) {
                    throw new UserError($this->name . " create does not take '$key'; to change a row, update it");
                }
                if ($mode === 'update' && !$hasKey) {
                    throw new UserError($this->name . " update needs '$key'");
                }
                if ($hasKey) {
                    $model = $this->findOrFail($context, $input[$key]);
                    $this->authorize(self::VERB_EDIT, $model, $context);
                } else {
                    $model = $this->newModel($context);
                    $this->authorize(self::VERB_CREATE, null, $context);
                }
                // Only the keys present in the input are set: an update changes what it names.
                Mapper::toModel($model, $input, $notFromInput);
                foreach ($scope as $property => $value) {
                    $model->$property = $value;
                }
                $this->beforeWrite($model, $input, $hasKey, $context);
                $model->write();
                $rows[] = Mapper::toArray($model);
            }
            return $rows;
        });
    }
```

Update the class docblock: `The resolver logic every model-backed Type shares: list, upsert (or create and update) and delete.`

`src/Testing/ModelTypeTestCase.php`:

1. Add after `inputClass()`:

```php
    /**
     * @return string|null Class name of the update Input when the Type has separate
     *   create and update mutations (`--mutations create-update`); inputClass() is then
     *   the create Input. null for upsert.
     */
    protected function updateInputClass(): ?string
    {
        return null;
    }

    /** @return string[] Fields a create must supply: non-null on the create Input */
    protected function requiredFields(): array
    {
        return [];
    }
```

2. Replace `testInputMirrorsTheType()`:

```php
    public function testInputMirrorsTheType(): void
    {
        if ($this->inputClass() === null) {
            $this->addToAssertionCount(1);
            return;
        }
        if ($this->updateInputClass() !== null) {
            $this->assertInputFields((string) $this->inputClass(), true);
            $this->assertInputFields((string) $this->updateInputClass(), false);
            return;
        }
        $input = $this->container->get($this->inputClass());
        $actual = [];
        foreach ($input->getFields() as $name => $field) {
            $actual[$name] = (string) $field->getType();
        }
        foreach ($this->expectedFieldTypes() as $name => $expected) {
            $this->assertArrayHasKey($name, $actual, "{$input->name} should have a field '$name'");
            // Nothing is required on an input: a missing key means create.
            $this->assertSame(rtrim($expected, '!'), $actual[$name], "{$input->name}.$name");
        }
    }

    /** A create Input: no key, required fields non-null. An update Input: the key non-null, nothing else. */
    private function assertInputFields(string $class, bool $isCreate): void
    {
        $input = $this->container->get($class);
        $actual = [];
        foreach ($input->getFields() as $name => $field) {
            $actual[$name] = (string) $field->getType();
        }
        $key = $this->keyField();
        foreach ($this->expectedFieldTypes() as $name => $expected) {
            $bare = rtrim($expected, '!');
            if ($name === $key) {
                if ($isCreate) {
                    $this->assertArrayNotHasKey($name, $actual, "{$input->name} should not take the key: a create makes it");
                } else {
                    $this->assertSame($bare . '!', $actual[$name] ?? null, "{$input->name}.$name: an update names its row");
                }
                continue;
            }
            $this->assertArrayHasKey($name, $actual, "{$input->name} should have a field '$name'");
            $required = $isCreate && in_array($name, $this->requiredFields(), true);
            $this->assertSame($required ? $bare . '!' : $bare, $actual[$name], "{$input->name}.$name");
        }
    }
```

3. In `testLifecycle()`, the two writes follow the Type's mutations, and an update must leave unnamed fields alone:

```php
        $separate = $this->updateInputClass() !== null;
        $created = $separate
            ? $this->create([$this->sampleInput(), $this->sampleInput()])
            : $this->upsert([$this->sampleInput(), $this->sampleInput()]);
```

(replacing the `$created = $this->upsert(...)` line; its assertion messages stay), and in the update block:

```php
        if ($this->sampleUpdate()) {
            $change = [$key => $id] + $this->sampleUpdate();
            $updated = $separate ? $this->update([$change]) : $this->upsert([$change]);
            $this->assertCount(1, $updated);
            $this->assertEquals($id, $updated[0][$key], 'an update with a key updates in place');
            foreach ($this->sampleUpdate() as $name => $value) {
                $this->assertEquals($value, $updated[0][$name], "updated $name");
            }
            if ($separate) {
                foreach ($this->sampleInput() as $name => $value) {
                    if (!array_key_exists($name, $this->sampleUpdate())) {
                        $this->assertEquals($value, $updated[0][$name], "$name is not in the update, so it stays as it was");
                    }
                }
            }
            $this->assertCount($before + 2, $this->listAll(), 'an update should not add a row');
        }
```

The upsert path keeps its existing message text `'an upsert with a key updates in place'` — keep that string for the upsert branch if any test matches on it (`grep -rn "updates in place" test`); otherwise the shared wording above is fine.

4. Add the helpers beside `upsert()`:

```php
    /**
     * @param array<int, array<string, mixed>> $inputs
     * @return array<int, array<string, mixed>>
     */
    protected function create(array $inputs): array
    {
        return $this->mutate('Create', (string) $this->inputClass(), $inputs);
    }

    /**
     * @param array<int, array<string, mixed>> $inputs Each with the key
     * @return array<int, array<string, mixed>>
     */
    protected function update(array $inputs): array
    {
        return $this->mutate('Update', (string) $this->updateInputClass(), $inputs);
    }

    /**
     * @param array<int, array<string, mixed>> $inputs
     * @return array<int, array<string, mixed>>
     */
    private function mutate(string $kind, string $inputClass, array $inputs): array
    {
        $field = $this->entityName() . $kind;
        // The Input's own GraphQL name, rather than a guess from the prefix.
        $inputType = $this->container->get($inputClass)->name;
        return $this->execute(
            "mutation (\$input: [{$inputType}!]!) { {$field}(input: \$input) { {$this->selection()} } }",
            ['input' => $inputs]
        )[$field];
    }
```

- [ ] **Step 10: Write the new golden files and review them**

```bash
UPDATE_GOLDEN=1 docker/anorm-graphql test --testsuite tools --filter WritersTest
git status --short test/Fixtures/golden
git diff --stat test/Fixtures/golden
```

Expected: only the seven new golden files are added; **no existing golden file changes** (`git diff --stat` on tracked goldens is empty). Read each new one. `WidgetCreateInputBase.txt` must be exactly:

```php
<?php

// GENERATED by anorm-graphql — do not edit. Changes belong in WidgetCreateInput.php.

namespace App\GraphQL\Type\Widget\Base;

use Anorm\GraphQL\Builder\FieldBuilder;
use Anorm\GraphQL\Builder\ObjectBuilder;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\Type;

abstract class WidgetCreateInputBase extends InputObjectType
{
    public function __construct()
    {
        parent::__construct(
            ObjectBuilder::create('WidgetCreateInput')->setFields($this->fields())->build()
        );
    }

    protected function fields(): array
    {
        return [
            FieldBuilder::create('name', Type::string())->build(),
            FieldBuilder::create('quantity', Type::int())->build(),
            FieldBuilder::create('price', Type::float())->build(),
            FieldBuilder::create('active', Type::boolean())->build(),
            FieldBuilder::create('ownerId', Type::id())->build(),
            FieldBuilder::create('notes', Type::string())->build(),
        ];
    }
}
```

`WidgetUpdateInputBase.txt` is the same shape with `WidgetUpdateInput` and a first field `FieldBuilder::create('id', Type::nonNull(Type::id()))->build(),`. `EventCreateInputBase.txt` has `title` as `Type::nonNull(Type::string())` and `notes` as `Type::string()`, no `id`. `WidgetTypeCreateUpdate.txt` differs from `WidgetType.txt` only in the `resolveList / resolveCreate / resolveUpdate / resolveDelete` line. Then run without `UPDATE_GOLDEN`.

- [ ] **Step 11: Run to see them pass**

```bash
docker/anorm-graphql test --testsuite tools
docker/anorm-graphql test --testsuite runtime
docker/anorm-graphql test --testsuite integration
```

Expected: PASS, every suite, with the existing tests unchanged. `git diff main -- test/Fixtures/golden/WidgetInputBase.txt test/Fixtures/golden/WidgetTypeBase.txt test/Fixtures/golden/WidgetType.txt test/Fixtures/golden/WidgetTypeTest.txt test/Fixtures/golden/WidgetInput.txt test/Fixtures/golden/TestCase.txt` is empty.

- [ ] **Step 12: Gates and commit**

```bash
docker/anorm-graphql ci
git add tools src bin test
git commit -m "feat: --mutations create-update generates separate create and update mutations

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

Expected: `ci` green (tests with clover, phpcs, phpstan). Docs and CHANGELOG come in Task 2, with the other 0.2 changes.

---

### Task 2: anorm-graphql — `--input-only` and the `Date` scalar

**Also in `/home/cambell/src/sgw/anorm-graphql`, on `feature/0.2`, continuing Task 1.** Same runner, same commit style. Spec: Release 2 spec §6.2, §6.3; then the 0.2 docs, CHANGELOG and version.

**Files (in `anorm-graphql`):**
- Modify: `tools/src/TypeInfo.php`, `tools/src/TypeInfoBuilder.php`, `tools/src/TypeMakerOptions.php`, `tools/src/TypeMaker.php`, `tools/src/Schema/SchemaEditor.php`, `tools/src/Writer/Php.php`, `tools/src/Writer/TestWriter.php`, `bin/anorm-graphql.php`, `test/Fixtures/CalendarModel/EventModel.php`, `test/TestEnvironment.php`, `README.md`, `docs/customising.md`, `CHANGELOG.md`
- Create: `src/Type/DateType.php`, `test/runtime/DateTypeTest.php`, golden `test/Fixtures/golden/EventTypeBase.txt`
- Test: `test/tools/TypeInfoBuilderTest.php`, `test/tools/WritersTest.php`, `test/tools/TypeMakerTest.php`, `test/tools/CliTest.php`, `test/runtime/DateTypeTest.php`, `test/integration/CreateUpdateEndToEndTest.php`

**Interfaces:**
- Consumes: Task 1 (`TypeInfo::$mutations`, `$required`, `inputKinds()`, `staleInputs()`, the create/update writers).
- Produces (the module relies on exactly this):
  - CLI option `--input-only <Name,...>`, long form only, default empty. Names are models or entities, as `--readonly` takes; an unknown name is exit 2 with `Error: --input-only names '<name>', which is not a model in <dir>`.
  - An entity named by `--input-only` gets its Input file(s) only — `<E>Input` under upsert, `<E>CreateInput` and `<E>UpdateInput` under create-update — and **no** `ApiSchema` entries. Named by `--input-only` **and** `--readonly`, it gets the read-only Type, its test and its `<e>List` entry as well, and still no mutations. `TypeInfo::$inputOnly` (bool); `TypeMakerOptions::$inputOnly` (string[]).
  - `Anorm\GraphQL\Type\DateType`: a `ScalarType` named `Date`. **One instance per process: `DateType::instance()`**; never through a container (a schema may hold only one type named `Date`, so `$this->type(DateType::class)` would build a second one and the schema would refuse it). `serialize`: a `\DateTimeInterface` → `Y-m-d`; a string `YYYY-MM-DD` (optionally followed by a time) → `YYYY-MM-DD`; MySQL's zero date `0000-00-00…` → `null`; anything else → `SerializationError`. `parseValue` / `parseLiteral`: exactly `YYYY-MM-DD` and a real calendar date → `\DateTimeImmutable` at 00:00 UTC; anything else → a client-safe `GraphQL\Error\Error` (`Date must be a date as YYYY-MM-DD: …`).
  - A model property declared `\DateTimeInterface`, `\DateTime`, `\DateTimeImmutable` (or any class implementing `\DateTimeInterface`, by typed property or `@var`) is a `Date` field. Generated code writes `\Anorm\GraphQL\Type\DateType::instance()` fully qualified, so no import changes in files without dates. A model with no date-declared property generates exactly as before.
  - `ANORM_GRAPHQL_VERSION` is `'0.2.0'`.

- [ ] **Step 1: A date on the fixture model**

`test/Fixtures/CalendarModel/EventModel.php` — add the property and its transformer:

```php
use Anorm\Transform\SqlDateTimeTransform;
```

```php
    public function __construct(\PDO $pdo)
    {
        parent::__construct($pdo, DataMapper::create($pdo, 'events', DataMapper::autoMap($this)));
        // A DATE column: Anorm hands the model a \DateTime and writes it back as Y-m-d.
        $this->mapper()->transformers['due_on'] = new SqlDateTimeTransform('Y-m-d');
    }
```

```php
    /** @var \DateTime */
    public $dueOn;
```

Before relying on `$this->mapper()->transformers['due_on']`, confirm the transformer key is the column name: `grep -n "transformers\[" vendor/saygoweb/anorm/src/DataMapper.php` (they are indexed by the field, i.e. the column). `test/TestEnvironment.php` — the `events` table gains `` `due_on` DATE NULL ``.

- [ ] **Step 2: Write the failing tests**

`test/runtime/DateTypeTest.php`:

```php
<?php

namespace Anorm\GraphQL\Test\Runtime;

use Anorm\GraphQL\Type\DateType;
use GraphQL\Error\Error;
use GraphQL\Error\SerializationError;
use GraphQL\Language\AST\IntValueNode;
use GraphQL\Language\AST\StringValueNode;
use PHPUnit\Framework\TestCase;

class DateTypeTest extends TestCase
{
    public function testOneInstanceNamedDate(): void
    {
        $this->assertSame(DateType::instance(), DateType::instance());
        $this->assertSame('Date', DateType::instance()->name);
        DateType::instance()->assertValid();
    }

    public function testSerialisesDatesAndDateStrings(): void
    {
        $type = DateType::instance();
        $this->assertSame('2026-03-04', $type->serialize(new \DateTime('2026-03-04 13:14:15')));
        $this->assertSame('2026-03-04', $type->serialize(new \DateTimeImmutable('2026-03-04')));
        $this->assertSame('2026-03-04', $type->serialize('2026-03-04'));
        $this->assertSame('2026-03-04', $type->serialize('2026-03-04 00:00:00'));
        $this->assertNull($type->serialize('0000-00-00'), "MySQL's zero date is no date");
        $this->assertNull($type->serialize('0000-00-00 00:00:00'));
    }

    /**
     * @dataProvider unserialisable
     * @param mixed $value
     */
    public function testRefusesToSerialiseWhatIsNotADate($value): void
    {
        $this->expectException(SerializationError::class);
        DateType::instance()->serialize($value);
    }

    /** @return array<string, array<int, mixed>> */
    public function unserialisable(): array
    {
        return [
            'text' => ['tomorrow'],
            'impossible date' => ['2026-02-30'],
            'integer' => [20260304],
            'array' => [['2026-03-04']],
        ];
    }

    public function testParsesAnIsoDateToMidnightUtc(): void
    {
        $date = DateType::instance()->parseValue('2026-03-04');
        $this->assertInstanceOf(\DateTimeImmutable::class, $date);
        $this->assertSame('2026-03-04 00:00:00 UTC', $date->format('Y-m-d H:i:s T'));
        $literal = DateType::instance()->parseLiteral(new StringValueNode(['value' => '2024-02-29']));
        $this->assertSame('2024-02-29', $literal->format('Y-m-d'));
    }

    /**
     * @dataProvider unparseable
     * @param mixed $value
     */
    public function testRefusesAnythingButAnIsoCalendarDate($value): void
    {
        $this->expectException(Error::class);
        $this->expectExceptionMessage('Date must be a date as YYYY-MM-DD');
        DateType::instance()->parseValue($value);
    }

    /** @return array<string, array<int, mixed>> */
    public function unparseable(): array
    {
        return [
            'not a leap year' => ['2026-02-29'],
            'no zero padding' => ['2026-3-4'],
            'with a time' => ['2026-03-04 10:00:00'],
            'user format' => ['04/03/2026'],
            'trailing newline' => ["2026-03-04\n"],
            'zero date' => ['0000-00-00'],
            'integer' => [20260304],
            'null' => [null],
        ];
    }

    public function testALiteralThatIsNotAStringIsRefused(): void
    {
        $this->expectException(Error::class);
        DateType::instance()->parseLiteral(new IntValueNode(['value' => '20260304']));
    }
}
```

`test/tools/TypeInfoBuilderTest.php` — add, with `use Anorm\GraphQL\Test\Fixtures\CalendarModel\EventModel;` (from Task 1):

```php
    public function testADateTimePropertyIsADate(): void
    {
        $info = (new TypeInfoBuilder())->build(new EventModel(new NullPdo()));
        $this->assertSame(['id' => 'ID', 'title' => 'String', 'notes' => 'String', 'dueOn' => 'Date'], $info->fields);
    }

    /**
     * @dataProvider dateDeclarations
     */
    public function testEveryDateTimeInterfaceDeclarationIsADate(string $declaration, string $expected): void
    {
        $class = 'DateProbe' . md5($declaration);
        eval("namespace Anorm\\GraphQL\\Test\\Tools; class $class extends \\Anorm\\Model {
            public function __construct(\\PDO \$pdo) { parent::__construct(\$pdo, \\Anorm\\DataMapper::create(\$pdo, 'probes', \\Anorm\\DataMapper::autoMap(\$this))); }
            /** @var int */ public \$id;
            /** @var $declaration */ public \$when;
        }");
        $fqcn = __NAMESPACE__ . '\\' . $class;
        $info = (new TypeInfoBuilder())->build(new $fqcn(new NullPdo()));
        $this->assertSame($expected, $info->fields['when'], $declaration);
    }

    /** @return array<string, array<int, string>> */
    public function dateDeclarations(): array
    {
        return [
            'DateTime' => ['\DateTime', 'Date'],
            'DateTimeImmutable' => ['\DateTimeImmutable', 'Date'],
            'DateTimeInterface' => ['\DateTimeInterface', 'Date'],
            'nullable' => ['\DateTime|null', 'Date'],
            'another class' => ['\ArrayObject', 'String'],
            'string' => ['string', 'String'],
        ];
    }
```

(If `PropertyType` caches per class and the probe names collide, the `md5` in the class name keeps them apart. If `eval` is disallowed by the project's phpstan rules, move the probes into `test/Fixtures/CalendarModel/DateProbes.php` as six small classes instead; the assertions stay.)

`test/tools/WritersTest.php` — add:

```php
    public function testADateFieldUsesTheSharedDateType(): void
    {
        $info = (new TypeInfoBuilder())->build(new EventModel(new NullPdo()));
        $code = (new TypeBaseWriter())->render($info, 'App\GraphQL\Type');
        $this->assertGolden('EventTypeBase', $code);
        $this->assertStringContainsString("FieldBuilder::create('dueOn', \\Anorm\\GraphQL\\Type\\DateType::instance())->build(),", $code);
        $this->assertStringNotContainsString('use Anorm\GraphQL\Type\DateType;', $code);
    }

    public function testADateGetsADateSample(): void
    {
        $info = (new TypeInfoBuilder())->build(new EventModel(new NullPdo()));
        $code = (new TestWriter())->render($info, 'App\GraphQL\Type', 'Tests\GraphQL');
        $this->assertStringContainsString("'dueOn' => 'Date',", $code);
        $this->assertStringContainsString("'dueOn' => '2026-01-01',", $code);
    }
```

The Task 1 golden `WidgetTypeTestCreateUpdate.txt` (rendered from `EventModel`) now also carries `dueOn`: regenerate it in Step 7 and review that the only change is the `dueOn` lines.

`test/tools/TypeMakerTest.php` — add:

```php
    public function testInputOnlyWritesTheInputAndNothingElse(): void
    {
        $o = $this->options();
        $o->inputOnly = ['Owner'];
        $this->make($o);
        $this->assertFileExists("$this->dir/src/Type/Owner/OwnerInput.php");
        $this->assertFileExists("$this->dir/src/Type/Owner/Base/OwnerInputBase.php");
        $this->assertFileDoesNotExist("$this->dir/src/Type/Owner/OwnerType.php");
        $this->assertFileDoesNotExist("$this->dir/tests/OwnerTypeTest.php");
        $schema = file_get_contents("$this->dir/src/ApiSchema.php");
        $this->assertStringNotContainsString("'owner", $schema, 'no entry of any kind');
        $this->assertStringContainsString("'widgetUpsert'", $schema);
    }

    public function testInputOnlyAndReadOnlyGiveATypeAListAndInputs(): void
    {
        $o = $this->options();
        $o->mutations = 'create-update';
        $o->readOnly = ['Owner'];
        $o->inputOnly = ['Owner'];
        $report = implode("\n", $this->make($o)->report);
        $this->assertFileExists("$this->dir/src/Type/Owner/OwnerType.php");
        $this->assertFileExists("$this->dir/src/Type/Owner/OwnerCreateInput.php");
        $this->assertFileExists("$this->dir/src/Type/Owner/OwnerUpdateInput.php");
        $schema = file_get_contents("$this->dir/src/ApiSchema.php");
        $this->assertStringContainsString("'ownerList'", $schema);
        $this->assertStringNotContainsString("'ownerCreate'", $schema);
        $this->assertStringNotContainsString("'ownerUpdate'", $schema);
        $this->assertStringNotContainsString('orphaned', $report, 'its inputs are produced, so not stale');
    }

    public function testAnUnknownInputOnlyNameIsAnErrorAndWritesNothing(): void
    {
        $o = $this->options();
        $o->inputOnly = ['Nope'];
        $maker = new TypeMaker($o);
        $this->assertSame(2, $maker->run());
        $this->assertStringContainsString("--input-only names 'Nope'", $maker->report[0]);
        $this->assertDirectoryDoesNotExist("$this->dir/src");
    }
```

`test/tools/CliTest.php` — change `testVersionAndHelp`'s first line to `$this->assertSame([0, '0.2.0'], $this->cli(['--version']));` and add `'--mutations', '--input-only'` to its option list; add:

```php
    public function testInputOnlyThroughTheCommandLine(): void
    {
        [$exit, $output] = $this->cli(array_merge($this->make(), ['--input-only', 'Owner']));
        $this->assertSame(0, $exit, $output);
        $this->assertFileExists("$this->dir/Type/Owner/OwnerInput.php");
        $this->assertFileDoesNotExist("$this->dir/Type/Owner/OwnerType.php");
    }
```

`test/integration/CreateUpdateEndToEndTest.php` — the generated tests now cover `dueOn` (a create with `'2026-01-01'` compared on the way back). Add to the query script, before `$pdo->rollBack();`:

```php
$out['dated'] = $run(
    'mutation ($input: [EventCreateInput!]!) { eventCreate(input: $input) { id dueOn } }',
    ['input' => [['title' => 'dated', 'dueOn' => '2026-03-04']]]
);
$out['badDate'] = $run(
    'mutation ($input: [EventCreateInput!]!) { eventCreate(input: $input) { id } }',
    ['input' => [['title' => 'bad', 'dueOn' => '2026-02-30']]]
);
$out['stored'] = $pdo->query("SELECT due_on FROM events WHERE title = 'dated'")->fetchColumn();
```

and assert:

```php
        $this->assertArrayNotHasKey('errors', $out['dated'], $output);
        $this->assertSame('2026-03-04', $out['dated']['data']['eventCreate'][0]['dueOn']);
        $this->assertSame('2026-03-04', $out['stored'], 'written to the DATE column as the same day');
        $this->assertStringContainsString('Date must be a date as YYYY-MM-DD', json_encode($out['badDate']));
```

- [ ] **Step 3: Run them to see them fail**

```bash
docker/anorm-graphql test --testsuite runtime --filter DateTypeTest
docker/anorm-graphql test --testsuite tools
docker/anorm-graphql test --testsuite integration --filter CreateUpdateEndToEndTest
```

Expected: FAIL — `Class "Anorm\GraphQL\Type\DateType" not found`, `dueOn` typed `String`, `Undefined property …$inputOnly`, `Unexpected argument '--input-only'`, version `0.1.0`.

- [ ] **Step 4: Implement — `DateType`**

`src/Type/DateType.php`:

```php
<?php

namespace Anorm\GraphQL\Type;

use GraphQL\Error\Error;
use GraphQL\Error\SerializationError;
use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\StringValueNode;
use GraphQL\Language\Printer;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Utils\Utils;

/**
 * A calendar date as ISO 8601 `YYYY-MM-DD`: out of a model's \DateTimeInterface
 * property (or a date string straight from the database), in as a
 * \DateTimeImmutable at midnight UTC.
 *
 * Use instance(), never a container: a schema may hold only one type named `Date`,
 * and every generated Type and Input refers to this one.
 */
class DateType extends ScalarType
{
    /** @var DateType|null */
    private static $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self([
                'name' => 'Date',
                'description' => 'A calendar date, as ISO 8601 `YYYY-MM-DD`.',
            ]);
        }
        return self::$instance;
    }

    /**
     * @param mixed $value
     * @return string|null
     * @throws SerializationError
     */
    public function serialize($value)
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_string($value)) {
            // MySQL's zero date is how older schemas say "no date".
            if (strncmp($value, '0000-00-00', 10) === 0) {
                return null;
            }
            $day = substr($value, 0, 10);
            $rest = (string) substr($value, 10);
            if (self::fromString($day) !== null && ($rest === '' || $rest[0] === ' ' || $rest[0] === 'T')) {
                return $day;
            }
        }
        throw new SerializationError('Date cannot represent value: ' . Utils::printSafe($value));
    }

    /**
     * @param mixed $value
     * @throws Error
     */
    public function parseValue($value): \DateTimeImmutable
    {
        $date = is_string($value) ? self::fromString($value) : null;
        if ($date === null) {
            throw new Error('Date must be a date as YYYY-MM-DD: ' . Utils::printSafeJson($value));
        }
        return $date;
    }

    /**
     * @param array<string, mixed>|null $variables
     * @throws Error
     */
    public function parseLiteral(Node $valueNode, ?array $variables = null): \DateTimeImmutable
    {
        if ($valueNode instanceof StringValueNode) {
            $date = self::fromString($valueNode->value);
            if ($date !== null) {
                return $date;
            }
        }
        throw new Error('Date must be a date as YYYY-MM-DD: ' . Printer::doPrint($valueNode), $valueNode);
    }

    /** A real calendar day written exactly as YYYY-MM-DD, or null. */
    private static function fromString(string $value): ?\DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}\z/', $value) !== 1 || $value === '0000-00-00') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        // createFromFormat rolls 2026-02-30 over to March; a date that does not print back is not one.
        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }
}
```

Check against the installed webonyx before running: `sed -n 36,70p vendor/webonyx/graphql-php/src/Type/Definition/ScalarType.php` — the constructor takes `['name' => …, 'description' => …]`, and `LeafType::serialize/parseValue/parseLiteral` have no declared return types, so the narrower return types above are allowed. If PHPStan (level in `phpstan.neon`) objects to a return type, drop it to a docblock.

- [ ] **Step 5: Implement — generation**

`tools/src/Writer/Php.php` — add to `TYPE_CALLS`:

```php
        'Date' => '\\Anorm\\GraphQL\\Type\\DateType::instance()',
```

`tools/src/TypeInfo.php` — `$fields` docblock gains `'Date'`; add:

```php
    /** @var bool true to emit the Input(s) only: no mutations, and no Type unless also read-only */
    public $inputOnly = false;
```

`tools/src/TypeInfoBuilder.php` — `build()` passes the model class to `graphQLType()`: `$this->graphQLType($property, $key, $type, $class)`; replace `graphQLType()` and add `isDate()`:

```php
    /**
     * In precedence order: the key, then an `Id` suffix, then the declared type.
     */
    private function graphQLType($property, $key, $declared, $modelClass)
    {
        if ($property === $key || \substr($property, -2) === 'Id') {
            return 'ID';
        }
        switch ($declared) {
            case 'int':
                return 'Int';
            case 'float':
                return 'Float';
            case 'bool':
                return 'Boolean';
            default:
                return $this->isDate($declared, $modelClass) ? 'Date' : 'String';
        }
    }

    /**
     * Whether a declared type is a date: \DateTimeInterface or a class implementing it.
     * A docblock usually gives the name as written, so the model's own namespace is tried too.
     *
     * @param string|null $type
     * @param string $modelClass
     */
    private function isDate($type, $modelClass)
    {
        if ($type === null || \in_array($type, array('string', 'array'), true)) {
            return false;
        }
        $namespace = \substr($modelClass, 0, (int) \strrpos($modelClass, '\\'));
        foreach (array(\ltrim($type, '\\'), $namespace . '\\' . $type) as $candidate) {
            if ((\class_exists($candidate) || \interface_exists($candidate)) && \is_a($candidate, \DateTimeInterface::class, true)) {
                return true;
            }
        }
        return false;
    }
```

`tools/src/Writer/TestWriter.php` — `sampleValue()` gains a case before `default`:

```php
            case 'Date':
                return \sprintf('2026-01-%02d', $n);
```

and the entity's test is only written when a Type is (Step 6 handles that in `TypeMaker`).

- [ ] **Step 6: Implement — `--input-only`**

`tools/src/TypeMakerOptions.php`:

```php
    /** @var string[] Entity or model class short names: emit their Input(s) only */
    public $inputOnly = array();
```

`bin/anorm-graphql.php` — after `readonly`:

```php
        $arguments->addOption('input-only', array('default' => '', 'description' => 'Comma-separated models to emit as Input only'));
```

and in `makerOptions()`: `$o->inputOnly = $this->names($this->options['input-only']);`. Set `define('ANORM_GRAPHQL_VERSION', '0.2.0');`.

`tools/src/TypeMaker.php`:

1. The name check loop covers the new option: `foreach (array('only' => $o->only, 'readonly' => $o->readOnly, 'input-only' => $o->inputOnly) as $option => $names)`; then `$inputOnly = \array_map(array($this, 'entityOf'), $o->inputOnly);` beside `$readOnly`, and when a `TypeInfo` is built, `$info->inputOnly = \in_array($info->entity, $inputOnly, true);`.

2. `writeEntity()` — a Type (and its test) unless the entity is input-only and not read-only; Inputs unless it is read-only and not input-only:

```php
        $withType = !$info->inputOnly || $info->readOnly;
        $withInputs = !$info->readOnly || $info->inputOnly;
        $generated = array();
        $once = array();
        if ($withType) {
            $generated["$dir/Base/{$info->entity}TypeBase.php"] = (new TypeBaseWriter())->render($info, $o->typeNamespace, $o->typeBase);
            $once["$dir/{$info->entity}Type.php"] = (new TypeWriter())->render($info, $o->typeNamespace);
        }
        if ($withInputs) {
            foreach ($this->inputKinds($info) as $kind) {
                $generated["$dir/Base/{$info->entity}{$kind}InputBase.php"] = (new InputBaseWriter())->render($info, $o->typeNamespace, $kind);
                $once["$dir/{$info->entity}{$kind}Input.php"] = (new InputWriter())->render($info, $o->typeNamespace, $kind);
            }
        }
        if ($withType && $o->testsDir !== null) {
            $once[$this->join($o->testsDir, "{$info->entity}TypeTest.php")]
                = (new TestWriter())->render($info, $o->typeNamespace, $o->testNamespace);
        }
```

The order of keys (TypeBase, then InputBase; Type, then Input, then test) is today's order, so the report of a default run is unchanged.

3. `staleInputs()`: `$made = ($info->readOnly && !$info->inputOnly) ? array() : $this->inputKinds($info);`.

4. The read-only Type of an input-only entity is written with `inputClass() === null` (it is read-only), which is right: its Inputs are nested in someone else's, not mutations of its own.

`tools/src/Schema/SchemaEditor.php` — `fieldNames()`: an input-only entity that is not read-only has no entries; mutations only for an entity that is neither read-only nor input-only:

```php
        $prefix = $info->fieldPrefix();
        if ($info->inputOnly && !$info->readOnly) {
            return array('query' => array(), 'mutation' => array());
        }
        $names = array('query' => array('List' => $prefix . 'List'), 'mutation' => array());
        if (!$info->readOnly && !$info->inputOnly) {
            // … Task 1's kinds loop, unchanged
        }
        return $names;
```

- [ ] **Step 7: Goldens, then run everything**

```bash
UPDATE_GOLDEN=1 docker/anorm-graphql test --testsuite tools --filter WritersTest
git status --short test/Fixtures/golden && git diff test/Fixtures/golden
docker/anorm-graphql test --testsuite runtime
docker/anorm-graphql test --testsuite tools
docker/anorm-graphql test --testsuite integration
```

Expected: `EventTypeBase.txt` is new; the Event-based create-update test golden gains only `dueOn` lines; **no golden of the `Fixtures/Model` entities changes**. All suites PASS. `git diff main -- test/Fixtures/golden/Widget*.txt test/Fixtures/golden/TestCase.txt` shows no change to a file that existed on `main`.

- [ ] **Step 8: Docs, CHANGELOG**

`README.md`:
- Replace the Options block with the real output (`docker/anorm-graphql php bin/anorm-graphql.php --help`), pasted verbatim — it now lists `--mutations` and `--input-only`.
- After the `--type-base` paragraph under Options, add:

```markdown
`--mutations create-update` gives each writable entity `<entity>Create` and
`<entity>Update` in place of `<entity>Upsert`, with two Inputs: `<Entity>CreateInput`
(no key; a property whose docblock says `@required` is non-null) and
`<Entity>UpdateInput` (the key is `ID!`; everything else optional, and a field left
out is left as it was). Choose it when creating and changing a row differ enough —
required fields, rules that only apply to an existing row — that one Input would
hide it. Switching an existing project reports the old `<Entity>Input` files and
`<entity>Upsert` entries as orphaned; nothing is deleted.

`--input-only <names>` writes only the Input(s) for those entities, with no schema
entries: for rows that are only ever written as part of another entity's input, such
as an order's lines. Add `--readonly` for the same names to have their read-only Type
and `<entity>List` too.
```

- In "What you get", add a row group for create-update files (`Base/<E>CreateInputBase.php`, `Base/<E>UpdateInputBase.php` regenerated; `<E>CreateInput.php`, `<E>UpdateInput.php` once-only), and a short "Dates" paragraph: a property declared `\DateTimeInterface`/`\DateTime`/`\DateTimeImmutable` is a `Date` field (`YYYY-MM-DD`), shared through `DateType::instance()`; MySQL's `0000-00-00` reads as null; give the model a date transformer (`SqlDateTimeTransform('Y-m-d')`) for the column.

`docs/customising.md`:
- New section `## Separate create and update: --mutations create-update` — the two resolvers `resolveCreate` / `resolveUpdate` (override points, same `authorize` verbs and `beforeWrite` flag as upsert), the Inputs and their `fields()` hook, `@required`, with a compiled example of a once-only `WidgetCreateInput` adding a field:

```php
<?php

namespace Api\GraphQL\Type\Widget;

use Anorm\GraphQL\Builder\FieldBuilder;
use Api\GraphQL\Type\Widget\Base\WidgetCreateInputBase;
use GraphQL\Type\Definition\Type;

class WidgetCreateInput extends WidgetCreateInputBase
{
    protected function fields(): array
    {
        return array_merge(parent::fields(), [
            FieldBuilder::create('initialStock', Type::int())->build(),
        ]);
    }
}
```

  Compile it as the file's other examples are compiled (generate a real `WidgetCreateInput.php` with `docker/anorm-graphql make … --mutations create-update`, paste, `docker/anorm-graphql php -l`).
- New section `## Inputs of rows written inside another: --input-only`.
- New section `## Dates`, with the model side (declaration and transformer) and a note that a Type's computed date field must use `DateType::instance()`, never a new instance.
- In "Replacing `resolveUpsert` wholesale", one sentence: under `create-update`, replace `resolveCreate` and `resolveUpdate` the same way.

`CHANGELOG.md` — a new top section:

```markdown
## 0.2.0

For the first consumer's second release (`saygoweb/frontaccounting-module-graphql`,
Release 2). Default output is unchanged except for date properties (below).

- `--mutations create-update`: `<entity>Create` / `<entity>Update` with
  `<Entity>CreateInput` (no key; `@required` properties non-null) and
  `<Entity>UpdateInput` (key required; fields left out are left as they were), in
  place of `<entity>Upsert`. Runtime: `ModelType::resolveCreate()`,
  `ModelType::resolveUpdate()`. `ModelTypeTestCase` tests both.
- `--input-only <names>`: the Input(s) only, no schema entries; with `--readonly`,
  the read-only Type and its list as well.
- `Date` scalar (`Anorm\GraphQL\Type\DateType::instance()`), ISO `YYYY-MM-DD`. A
  property declared `\DateTimeInterface` (or a class implementing it) is now a
  `Date` field; in 0.1 it was a `String`.
```

- [ ] **Step 9: Gates on 7.4 and 8.3, and commit**

```bash
docker/anorm-graphql ci
PHP_VARIANT=8.3-cli docker/anorm-graphql up --build
docker/anorm-graphql test
docker/anorm-graphql test --testsuite integration
docker/anorm-graphql quality
docker/anorm-graphql up --build        # back to the 7.4 floor (PHP_VARIANT unset)
docker/anorm-graphql php bin/anorm-graphql.php --version    # 0.2.0
git add tools src bin test
git commit -m "feat: --input-only, and a Date scalar for date properties

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git add README.md docs/customising.md CHANGELOG.md
git commit -m "docs: create-update mutations, input-only entities, dates; changelog for 0.2.0

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

Expected: green on 7.4 (`ci`) and 8.3 (tests, integration, phpcs, phpstan). If `vendor/` resolves differently on 8.3, run `docker/anorm-graphql composer install` as the README's "Developing" section says, and restore it on 7.4 before committing (`git status` must not show `composer.lock` changed). Do **not** merge, tag or push: Checkpoint A comes first.

---

### Checkpoint A — after Task 2, before `anorm-graphql` `v0.2.0` is published

A tag this module will depend on is about to be pushed, and a pushed tag is not taken back. This checkpoint runs in `/home/cambell/src/sgw/anorm-graphql`, on `feature/0.2`.

- [ ] **Independent review.** A reviewer who did not write Tasks 1–2 reviews `main..feature/0.2` in `anorm-graphql` at `/code-review medium` depth: real bugs and high-confidence issues only. Particular attention:
  - `ModelType::write()`: `resolveUpsert` behaves exactly as in 0.1 (all of `test/integration/ModelTypeTest.php`'s upsert cases pass unchanged); a create never takes a key; an update never creates; scope and `authorize()` apply to all three; all-or-nothing holds for create and update.
  - Default output byte-identical: `git diff main feature/0.2 -- test/Fixtures/golden/` touches only new files; `TypeMakerTest::testWithoutMutationsTheOutputIsUnchanged` and `testASecondRunChangesNothing` pass.
  - `DateType`: one instance only (no container path can make a second `Date`); `0000-00-00` handling; impossible dates refused (not rolled over); errors client-safe.
  - `--input-only` and `--readonly` together, and each alone, produce what spec §6.2 says; no schema entries for an input-only entity; no stale-input report for its own inputs.
  - Mode switches report, never delete (`orphaned` lines for old Inputs and `<e>Upsert` entries).
  Fix confirmed findings on the branch; commit as `fix: Checkpoint A review` (Conventional-Commit style, `Co-Authored-By` line).
- [ ] **Spec walk** (this module's Release 2 spec §6), each confirmed or the deviation written into the spec:
  - §6.1 — `--mutations upsert|create-update`, default `upsert`; entry names and argument types (`[<E>CreateInput!]!`, `[<E>UpdateInput!]!`); Create input without the key and `@required` non-null; Update input with `ID!` key; `resolveCreate` / `resolveUpdate`; generated tests exercise both (`CreateUpdateEndToEndTest::testTheGeneratedTestsPassThroughCreateAndUpdate`).
  - §6.2 — `--input-only`, alone and with `--readonly`.
  - §6.3 — `Date` for `\DateTimeInterface` properties, ISO in and out, invalid input a client-safe error. **Record in the spec (marked *(revised)*) that the scalar is shared through `DateType::instance()`, not `$this->type(DateType::class)`**: a schema holds one `Date`, and the generated Types and Inputs build their fields without the container.
- [ ] **Docs agree with the tool:** the README Options block is identical to `docker/anorm-graphql php bin/anorm-graphql.php --help` (diff them); `--version` prints `0.2.0`; each new `docs/customising.md` example compiles (paste into a generated file, `docker/anorm-graphql php -l`); `CHANGELOG.md` has 0.2.0 with the three changes and the date note.
- [ ] **Green on both PHP versions:** `docker/anorm-graphql ci` on 7.4; `PHP_VARIANT=8.3-cli docker/anorm-graphql up --build`, then `test`, `test --testsuite integration`, `quality`; back to 7.4 afterwards.
- [ ] **Land and tag locally:**

```bash
cd /home/cambell/src/sgw/anorm-graphql
git switch main && git pull --ff-only
git merge --ff-only feature/0.2
git tag -a v0.2.0 -m "anorm-graphql 0.2.0 — create/update mutations, input-only entities, Date"
git log --oneline origin/main..main
git show --stat v0.2.0 | head -20
```

Expected: `main` fast-forwards (linear history), `v0.2.0` points at the last commit of the branch.
- [ ] **STOP — do not push.** `git push origin main v0.2.0` publishes the tag that this module's `composer.json` will resolve (`saygoweb/anorm-graphql ^0.2`, Task 3); a pushed tag is not taken back. Ask the user, showing `git log --oneline origin/main..main` and `git show --stat v0.2.0`, and push only on their explicit OK. Task 3 cannot `composer require saygoweb/anorm-graphql:^0.2` from the `vcs` repository until the push has happened.

---

### Task 3: Write plumbing — FaTransaction, message levels, warnings, dates, fail-closed writes

The machinery every FrontAccounting write in Tasks 5–9 runs through (spec §2.2, §3), and anorm-graphql 0.2 wired into the module. No entity is written yet; everything here is proven with probes.

**Files:**
- Modify: `composer.json`, `composer.lock` (`saygoweb/anorm-graphql` `^0.1` → `^0.2`), `bin/generate`, `src/Fa/FaMessages.php`, `src/Fa/FaSession.php`, `src/Error/BadInput.php`, `src/Error/FaRejected.php`, `src/Type/FaModelType.php`, `src/Http/GraphQLAction.php`
- Create: `src/Fa/FaTransaction.php`, `src/Fa/Warnings.php`, `src/Fa/DateConversion.php`, `src/Fa/Service/ServiceCall.php`
- Test: `tests/Unit/Fa/FaMessagesTest.php` (extend), `tests/Unit/Fa/WarningsTest.php`, `tests/Unit/Fa/DateConversionTest.php`, `tests/Unit/Error/BadInputTest.php`, `tests/Unit/Type/FaModelTypeTest.php` (extend), `tests/Unit/Http/GraphQLActionTest.php` (extend), `tests/Integration/WritePlumbingTest.php`

**Interfaces:**
- Consumes: anorm-graphql `v0.2.0` (Tasks 1–2, pushed at Checkpoint A): `Anorm\GraphQL\ModelType::resolveCreate/resolveUpdate($root, $args, Container $context): array`, CLI `--mutations create-update`, `--input-only`. FrontAccounting (upstream `master`): `begin_transaction()` / `commit_transaction()` / `cancel_transaction()` (`includes/db/sql_functions.inc:20`, `:30`, `:45`; `cancel_transaction()` issues `ROLLBACK` only when `$transaction_level` is non-zero and always sets it to 0, `:47-52`), `sql2date()` (`includes/date_functions.inc:348`) and `date2sql()` (`:378`), `display_error()` / `display_warning()` / `display_notification()` (`includes/ui/ui_msgs.inc:12-24`, each `fa_trigger_error($msg, E_USER_ERROR|E_USER_WARNING|E_USER_NOTICE)`), `install_hooks()` (`includes/hooks.inc:221-250`: `$Hooks[$package]` is set only for an extension that is `active` for the company and whose `hooks_<package>` class exists).
- Produces (exact; later tasks use these names):
  - `FA\GraphQL\Fa\FaTransaction::run(callable $work)` → `$work()`'s result. `begin_transaction()`, the work, `commit_transaction()`; on any `\Throwable`, `cancel_transaction()` (a failure inside it only resets `$transaction_level` to 0) and rethrow. Nests: an inner `run()` or FrontAccounting's own `begin_transaction()` only counts levels; an inner throw rolls back everything.
  - `FA\GraphQL\Fa\FaMessages`: `add(int $level, string $text)`, `drain(): string[]` (unchanged), `errors(): string[]`, `warnings(): string[]` (peek, do not clear), `drainByLevel(): array{errors: string[], warnings: string[], notices: string[]}`, `reset()`. `E_USER_ERROR` → errors, `E_USER_WARNING` → warnings, anything else → notices.
  - `FA\GraphQL\Fa\Warnings` (static, per request): `add(string $text)` (a repeat is kept once), `all(): string[]`, `reset()`.
  - `FA\GraphQL\Fa\Service\ServiceCall::run(callable $work)` → `$work()`'s result, inside one `FaTransaction`. Stale messages are discarded first. After the work: any collected error → `FaRejected(<first error>, <all errors>)` (rolled back); a `FaErrorException` for which FrontAccounting collected messages (its "duplicate" warning, say) → `FaRejected` with those messages, otherwise rethrown (`INTERNAL`). Warnings reach `Warnings::add()` only once the transaction has committed; notices are discarded.
  - `ServiceCall::each(array $inputs, callable $work): array` — one `FaTransaction` for the whole batch; `$work($input, int $index)` per item; a `BadInput` without an index is rethrown `withIndex($index)`, a `FaRejected` carries `index`; the results in order. **(Addition to the contract: batches need the index — spec §3.1, §5.)**
  - `FA\GraphQL\Fa\DateConversion`: `iso($date, ?string $field = null): string` (a `\DateTimeInterface` or a `Y-m-d` string → `Y-m-d`; anything else → `BadInput('Expected a date as YYYY-MM-DD.', $field)`), `toFa($date, ?string $field = null): string` (FrontAccounting's user format via `sql2date()`), `fromFa(string $faDate): string` (`date2sql()`, for values read from a `Cart`), `fromSql(?string $sqlDate): ?string` (`Y-m-d`; `null`, `''` and `0000-00-00` → `null`; a malformed value → `\UnexpectedValueException`). **(`iso` and `fromFa` are additions.)**
  - `FA\GraphQL\Error\BadInput::__construct(string $message, ?string $field = null, ?int $index = null)`, `field(): ?string`, `index(): ?int`, `withIndex(int $index): BadInput`; `extensions.field` / `extensions.index` when set.
  - `FA\GraphQL\Error\FaRejected::__construct(string $message, array $messages = [], ?int $index = null)`; `extensions.index` when set. **(`index` is an addition.)**
  - `FA\GraphQL\Fa\FaSession::isActive(string $package): bool` — an instance method (FaSession is the container's `SessionGate`): true only once a company is open and `$GLOBALS['Hooks'][$package]` is set.
  - `FA\GraphQL\Type\FaModelType`: `resolveCreate`, `resolveUpdate`, `resolveDelete`, `resolveUpsert` all throw `Forbidden('This entity is written through FrontAccounting; no write path is declared.')`. A writable Type overrides them, calls `$this->authorize(<verb>, null, $context)` first, then its service through `ServiceCall`.
  - `GraphQLAction` resets `Warnings` per request and puts a non-empty `Warnings::all()` at the response's top-level `extensions.warnings`.
  - `bin/generate`: `--mutations create-update` always; `INPUT_ONLY=""` passed as `--input-only` only when non-empty (Tasks 5–7 extend `READONLY` and `INPUT_ONLY`).

- [ ] **Step 1: Precondition — anorm-graphql v0.2.0 is published**

```bash
git ls-remote --tags https://github.com/saygoweb/anorm-graphql.git refs/tags/v0.2.0
```

Expected: one line ending `refs/tags/v0.2.0`. If it prints nothing, Checkpoint A has not pushed the tag: **stop and report BLOCKED** — do not switch to a `path` repository to get past this.

- [ ] **Step 2: Require 0.2 and regenerate with the new flags**

```bash
docker/fa-graphql composer require 'saygoweb/anorm-graphql:^0.2'
docker/fa-graphql composer show saygoweb/anorm-graphql | grep -E '^(versions|source)'
```

Expected: `versions : * v0.2.0` and a `source` line naming `https://github.com/saygoweb/anorm-graphql`.

`bin/generate` — replace the `READONLY=` line and the block that builds `ARGS` with:

```bash
# Entities generated without mutations, comma-separated.
READONLY="SalesType"
# Entities that get only their Input(s), to nest in another entity's input: no
# Type mutations and no ApiSchema entries of their own. Comma-separated.
INPUT_ONLY=""

if [ -n "${ANORM_GRAPHQL_CHECKOUT:-}" ]; then
    GENERATOR=(php -d "auto_prepend_file=$ROOT/vendor/autoload.php" "$ANORM_GRAPHQL_CHECKOUT/bin/anorm-graphql.php")
else
    GENERATOR=(php "$ROOT/vendor/bin/anorm-graphql.php")
fi

# create-update: FrontAccounting's entities are created and updated by different
# functions with different rules (Release 2 spec section 4.1), so a writable entity
# gets <entity>Create and <entity>Update, not one <entity>Upsert.
ARGS=(make
    -m src/Model -n 'FA\GraphQL\Model'
    -o src/Type -t 'FA\GraphQL\Type'
    --tests tests/Generated --test-ns 'FA\GraphQL\Tests\Generated'
    -s src/ApiSchema.php --schema-ns 'FA\GraphQL'
    --type-base 'FA\GraphQL\Type\FaModelType'
    --mutations create-update)
# --readonly or --input-only naming a model that does not exist is an error (exit 2),
# so each is passed only when there is something to name.
if [ -n "$READONLY" ]; then
    ARGS+=(--readonly "$READONLY")
fi
if [ -n "$INPUT_ONLY" ]; then
    ARGS+=(--input-only "$INPUT_ONLY")
fi
```

Then, on the host:

```bash
bin/generate
git status --short src tests/Generated
```

Expected: every line `current` or `kept` (SalesType is read-only, which `--mutations` does not touch), and `git status` shows nothing under `src/` or `tests/Generated/`. If anything is `written` or `updated`, stop: read the diff, and report it — 0.2's default output was meant to be byte-identical (Release 2 spec §6).

```bash
docker/fa-graphql test
```

Expected: PASS, the same count as before the upgrade (271 on upstream, one fork-only skip).

- [ ] **Step 3: Write the failing tests**

`tests/Unit/Fa/FaMessagesTest.php` — add:

```php
    public function testMessagesKeepTheirLevel(): void
    {
        FaMessages::add(E_USER_ERROR, 'Customer <b>not found</b>');
        FaMessages::add(E_USER_WARNING, 'Price below cost');
        FaMessages::add(E_USER_NOTICE, 'Order saved');
        FaMessages::add(E_USER_DEPRECATED, 'Something old');

        $this->assertSame(['Customer not found'], FaMessages::errors());
        $this->assertSame(['Price below cost'], FaMessages::warnings());
        // errors() and warnings() look; they do not take.
        $this->assertSame(
            [
                'errors' => ['Customer not found'],
                'warnings' => ['Price below cost'],
                'notices' => ['Order saved', 'Something old'],
            ],
            FaMessages::drainByLevel()
        );
        $this->assertSame([], FaMessages::drain());
    }

    public function testDrainStillReturnsEveryTextInOrder(): void
    {
        FaMessages::add(E_USER_ERROR, 'One');
        FaMessages::add(E_USER_NOTICE, 'Two');

        $this->assertSame(['One', 'Two'], FaMessages::drain());
        $this->assertSame(['errors' => [], 'warnings' => [], 'notices' => []], FaMessages::drainByLevel());
    }
```

`tests/Unit/Fa/WarningsTest.php`:

```php
<?php

namespace FA\GraphQL\Tests\Unit\Fa;

use FA\GraphQL\Fa\Warnings;
use PHPUnit\Framework\TestCase;

class WarningsTest extends TestCase
{
    protected function tearDown(): void
    {
        Warnings::reset();
    }

    public function testCollectsInOrderAndKeepsARepeatOnce(): void
    {
        Warnings::add('Price below cost');
        Warnings::add('No email contact');
        Warnings::add('Price below cost');

        $this->assertSame(['Price below cost', 'No email contact'], Warnings::all());
    }

    public function testResetEmptiesIt(): void
    {
        Warnings::add('Price below cost');
        Warnings::reset();

        $this->assertSame([], Warnings::all());
    }
}
```

`tests/Unit/Fa/DateConversionTest.php` (the parts that need no FrontAccounting; `toFa`/`fromFa` are in the integration test):

```php
<?php

namespace FA\GraphQL\Tests\Unit\Fa;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Fa\DateConversion;
use PHPUnit\Framework\TestCase;

class DateConversionTest extends TestCase
{
    public function testAnIsoDateStringIsKept(): void
    {
        $this->assertSame('2026-09-25', DateConversion::iso('2026-09-25'));
    }

    public function testADateObjectBecomesIso(): void
    {
        $this->assertSame('2026-02-28', DateConversion::iso(new \DateTimeImmutable('2026-02-28 13:45:00')));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public function notDates(): array
    {
        return [
            'impossible day' => ['2026-02-30'],
            'user format' => ['25/09/2026'],
            'datetime string' => ['2026-09-25 10:00:00'],
            'trailing newline' => ["2026-09-25\n"],
            'empty' => [''],
            'integer' => [20260925],
            'null' => [null],
        ];
    }

    /**
     * @dataProvider notDates
     * @param mixed $value
     */
    public function testAnythingElseIsBadInputNamingTheField($value): void
    {
        try {
            DateConversion::iso($value, 'orderDate');
            $this->fail('expected BadInput');
        } catch (BadInput $e) {
            $this->assertSame('Expected a date as YYYY-MM-DD.', $e->getMessage());
            $this->assertSame('orderDate', $e->field());
        }
    }

    public function testFromSql(): void
    {
        $this->assertNull(DateConversion::fromSql(null));
        $this->assertNull(DateConversion::fromSql(''));
        $this->assertNull(DateConversion::fromSql('0000-00-00'));
        $this->assertSame('2026-09-25', DateConversion::fromSql('2026-09-25'));
        $this->assertSame('2026-09-25', DateConversion::fromSql('2026-09-25 10:11:12'));
    }

    public function testAMalformedSqlDateIsAnInternalError(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        DateConversion::fromSql('garbage');
    }
}
```

`tests/Unit/Error/BadInputTest.php`:

```php
<?php

namespace FA\GraphQL\Tests\Unit\Error;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\FaRejected;
use PHPUnit\Framework\TestCase;

class BadInputTest extends TestCase
{
    public function testAPlainBadInputCarriesOnlyItsCode(): void
    {
        $this->assertSame(['code' => 'BAD_INPUT'], (new BadInput('Name is required.'))->getExtensions());
    }

    public function testFieldAndIndexReachTheClient(): void
    {
        $e = new BadInput('Name is required.', 'name', 2);

        $this->assertSame('name', $e->field());
        $this->assertSame(2, $e->index());
        $this->assertSame(['code' => 'BAD_INPUT', 'field' => 'name', 'index' => 2], $e->getExtensions());
    }

    public function testWithIndexKeepsMessageAndField(): void
    {
        $e = (new BadInput('Name is required.', 'name'))->withIndex(1);

        $this->assertSame('Name is required.', $e->getMessage());
        $this->assertSame(['code' => 'BAD_INPUT', 'field' => 'name', 'index' => 1], $e->getExtensions());
    }

    public function testFaRejectedCarriesItsIndexWhenGiven(): void
    {
        $this->assertSame(
            ['code' => 'FA_REJECTED', 'messages' => ['Credit limit exceeded'], 'index' => 0],
            (new FaRejected('Credit limit exceeded', ['Credit limit exceeded'], 0))->getExtensions()
        );
        $this->assertSame(
            ['code' => 'FA_REJECTED', 'messages' => []],
            (new FaRejected('Refused'))->getExtensions()
        );
    }
}
```

`tests/Unit/Type/FaModelTypeTest.php` — add (it reuses the file's `signIn()` and `type()` helpers):

```php
    /**
     * @return array<string, array{0: string}>
     */
    public function writeResolvers(): array
    {
        return [
            'create' => ['resolveCreate'],
            'update' => ['resolveUpdate'],
            'delete' => ['resolveDelete'],
            'upsert' => ['resolveUpsert'],
        ];
    }

    /**
     * Release 2 spec section 2.2: a generated Type whose write path was never
     * declared refuses, whatever the role holds, instead of writing the table.
     *
     * @dataProvider writeResolvers
     */
    public function testAWriteWithNoDeclaredPathIsForbidden(string $resolver): void
    {
        $this->signIn(['SA_GRAPHQL', 'SA_SALESORDER', 'SA_CUSTOMER']);
        $type = $this->type([
            ModelType::VERB_LIST => 'SA_SALESORDER',
            ModelType::VERB_CREATE => 'SA_SALESORDER',
            ModelType::VERB_EDIT => 'SA_SALESORDER',
            ModelType::VERB_DELETE => 'SA_SALESORDER',
        ]);

        $this->expectException(Forbidden::class);
        $this->expectExceptionMessage('This entity is written through FrontAccounting; no write path is declared.');
        $type->$resolver(null, ['input' => [['id' => '1']], 'id' => ['1']], new Container());
    }
```

`tests/Unit/Http/GraphQLActionTest.php` — in `setUp()`, add this field to the `Query` `fields` array (after `'boom'`), and `use FA\GraphQL\Fa\Warnings;` at the top:

```php
            'warn' => ['type' => Type::string(), 'resolve' => function () {
                Warnings::add('Price below cost');
                return 'ok';
            }],
```

and add a `tearDown()` and the tests:

```php
    protected function tearDown(): void
    {
        Warnings::reset();
    }

    public function testWarningsTravelInTopLevelExtensions(): void
    {
        $this->assertSame(
            ['data' => ['warn' => 'ok'], 'extensions' => ['warnings' => ['Price below cost']]],
            $this->execute('{"query": "{ warn }"}')
        );
    }

    public function testNoWarningsNoExtensionsAndNothingCarriesOverFromTheLastRequest(): void
    {
        Warnings::add('left over from an earlier request');

        $this->assertSame(['data' => ['apiVersion' => '0.1.0']], $this->execute('{"query": "{ apiVersion }"}'));
    }
```

`tests/Integration/WritePlumbingTest.php`:

```php
<?php

namespace FA\GraphQL\Tests\Integration;

use FA\GraphQL\Auth\Claims;
use FA\GraphQL\Config;
use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\FaRejected;
use FA\GraphQL\Fa\CompanyContext;
use FA\GraphQL\Fa\DateConversion;
use FA\GraphQL\Fa\FaErrorException;
use FA\GraphQL\Fa\FaMessages;
use FA\GraphQL\Fa\FaSession;
use FA\GraphQL\Fa\FaTransaction;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Fa\Warnings;

/**
 * The write plumbing against a real FrontAccounting: its transaction functions, its
 * message functions and its date format. Probe rows go into this module's own
 * graphql_refresh_token table (the one table the module owns) and are counted on a
 * second connection, which sees only what was committed.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class WritePlumbingTest extends FaTestCase
{
    private const CLIENT = 'write-plumbing-test';

    private function session(): FaSession
    {
        return new FaSession(Config::fromArray(['secret' => '0123456789abcdef0123456789abcdef']));
    }

    private function enter(): FaSession
    {
        $session = $this->session();
        $session->enter(new Claims(0, 'apitest', 'jti', new \DateTimeImmutable('+5 minutes')));
        FaMessages::reset();
        Warnings::reset();

        return $session;
    }

    protected function tearDown(): void
    {
        if (CompanyContext::isSet()) {
            $this->pdo()->prepare('DELETE FROM ' . CompanyContext::prefix() . 'graphql_refresh_token WHERE client = ?')
                ->execute([self::CLIENT]);
        }
        Warnings::reset();
    }

    private function insert(string $hash): void
    {
        db_query(
            'INSERT INTO ' . TB_PREF . 'graphql_refresh_token (user_id, token_hash, issued_at, expires_at, client)'
            . ' VALUES (1, ' . db_escape($hash) . ', NOW(), NOW(), ' . db_escape(self::CLIENT) . ')',
            'could not insert the probe row'
        );
    }

    private function committed(string $hash): int
    {
        $statement = $this->pdo()->prepare(
            'SELECT COUNT(*) FROM ' . CompanyContext::prefix() . 'graphql_refresh_token WHERE token_hash = ?'
        );
        $statement->execute([$hash]);

        return (int) $statement->fetchColumn();
    }

    private function hash(string $label): string
    {
        return hash('sha256', $label . uniqid('', true));
    }

    public function testRunCommitsTheWork(): void
    {
        $this->enter();
        $hash = $this->hash('commit');

        $this->assertSame(7, FaTransaction::run(function () use ($hash) {
            $this->insert($hash);
            return 7;
        }));
        $this->assertSame(1, $this->committed($hash));
        $this->assertSame(0, $GLOBALS['transaction_level']);
    }

    public function testAnyThrowableRollsBackEvenInsideANestedLevelAndResetsTheCounter(): void
    {
        $this->enter();
        $hash = $this->hash('rollback');

        try {
            FaTransaction::run(function () use ($hash) {
                $this->insert($hash);
                // As Cart::write() and add_crm_person() do inside our transaction.
                begin_transaction();
                throw new \RuntimeException('boom');
            });
            $this->fail('expected the exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $this->assertSame(0, $this->committed($hash));
        $this->assertSame(0, $GLOBALS['transaction_level']);
    }

    public function testAFailedQueryRollsBackAndTheNextWriteInTheSameRequestStillCommits(): void
    {
        $this->enter();
        $first = $this->hash('first');
        $second = $this->hash('second');

        try {
            FaTransaction::run(function () use ($first) {
                $this->insert($first);
                db_query('SELECT * FROM no_such_table_write_plumbing', 'the probe query failed');
            });
            $this->fail('expected FaErrorException');
        } catch (FaErrorException $e) {
            $this->addToAssertionCount(1);
        }
        FaTransaction::run(function () use ($second) {
            $this->insert($second);
        });

        $this->assertSame(0, $this->committed($first));
        $this->assertSame(1, $this->committed($second));
    }

    public function testAnErrorMessageBecomesFaRejectedAndNothingIsKept(): void
    {
        $this->enter();
        $hash = $this->hash('error');

        try {
            ServiceCall::run(function () use ($hash) {
                $this->insert($hash);
                display_error('Credit limit exceeded');
            });
            $this->fail('expected FaRejected');
        } catch (FaRejected $e) {
            $this->assertSame('Credit limit exceeded', $e->getMessage());
            $this->assertSame(['Credit limit exceeded'], $e->getExtensions()['messages']);
        }
        $this->assertSame(0, $this->committed($hash));
    }

    public function testWarningsAreKeptAndNoticesDropped(): void
    {
        $this->enter();
        $hash = $this->hash('warning');

        $result = ServiceCall::run(function () use ($hash) {
            $this->insert($hash);
            display_warning('Price below cost');
            display_notification('The order has been saved.');
            return 42;
        });

        $this->assertSame(42, $result);
        $this->assertSame(1, $this->committed($hash));
        $this->assertSame(['Price below cost'], Warnings::all());
    }

    public function testWarningsOfRolledBackWorkAreNotReported(): void
    {
        $this->enter();

        try {
            ServiceCall::run(function () {
                display_warning('Price below cost');
                display_error('Customer not found');
            });
            $this->fail('expected FaRejected');
        } catch (FaRejected $e) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame([], Warnings::all());
    }

    public function testStaleMessagesFromBeforeTheCallAreIgnored(): void
    {
        $this->enter();
        display_error('left over from an earlier call');

        $this->assertSame(1, ServiceCall::run(function () {
            return 1;
        }));
    }

    public function testADuplicateKeyIsFaRejectedWithFrontAccountingsMessage(): void
    {
        $this->enter();
        $hash = $this->hash('duplicate');

        try {
            ServiceCall::run(function () use ($hash) {
                $this->insert($hash);
                $this->insert($hash);
            });
            $this->fail('expected FaRejected');
        } catch (FaRejected $e) {
            $this->assertStringContainsString('duplicate', $e->getMessage());
        }
        $this->assertSame(0, $this->committed($hash));
    }

    public function testAnUnexplainedDatabaseErrorStaysInternal(): void
    {
        $this->enter();

        $this->expectException(FaErrorException::class);
        ServiceCall::run(function () {
            db_query('SELECT * FROM no_such_table_write_plumbing', 'the probe query failed');
        });
    }

    public function testEachNamesTheFailingItemAndRollsBackTheWholeBatch(): void
    {
        $this->enter();
        $hashes = [$this->hash('a'), $this->hash('b'), $this->hash('c')];

        try {
            ServiceCall::each($hashes, function (string $hash, int $index) {
                $this->insert($hash);
                if ($index === 1) {
                    throw new BadInput('Name is required.', 'name');
                }
            });
            $this->fail('expected BadInput');
        } catch (BadInput $e) {
            $this->assertSame(1, $e->index());
            $this->assertSame('name', $e->field());
        }
        foreach ($hashes as $hash) {
            $this->assertSame(0, $this->committed($hash));
        }
    }

    public function testEachTagsFrontAccountingsRefusalWithItsIndex(): void
    {
        $this->enter();

        try {
            ServiceCall::each(['a', 'b', 'c'], function (string $item, int $index) {
                if ($index === 2) {
                    display_error('The branch does not belong to the customer.');
                }
            });
            $this->fail('expected FaRejected');
        } catch (FaRejected $e) {
            $this->assertSame(2, $e->getExtensions()['index']);
        }
    }

    public function testEachReturnsEveryResultInOrder(): void
    {
        $this->enter();

        $this->assertSame(['A0', 'B1'], ServiceCall::each(['a', 'b'], function (string $item, int $index) {
            return strtoupper($item) . $index;
        }));
    }

    public function testDatesRoundTripThroughFrontAccountingsUserFormat(): void
    {
        $this->enter();

        $fa = DateConversion::toFa('2026-09-25');
        $this->assertSame('2026-09-25', date2sql($fa));
        $this->assertSame('2026-09-25', DateConversion::fromFa($fa));
        $this->assertSame('2026-02-28', DateConversion::fromFa(DateConversion::toFa(new \DateTimeImmutable('2026-02-28'))));
    }

    public function testIsActiveIsFalseUntilACompanyIsOpen(): void
    {
        $this->assertFalse($this->session()->isActive('graphql'));
    }

    public function testIsActiveNamesTheExtensionsActiveForTheCompany(): void
    {
        $session = $this->enter();

        $this->assertTrue($session->isActive('graphql'));
        $this->assertFalse($session->isActive('no_such_extension'));
        $active = array_column(array_filter($GLOBALS['installed_extensions'], function ($e) {
            return !empty($e['active']);
        }), 'package');
        $this->assertSame(in_array('sgw_sales', $active, true), $session->isActive('sgw_sales'));
    }
}
```

- [ ] **Step 4: Run them to see them fail**

```bash
docker/fa-graphql test --testsuite unit --filter 'FaMessagesTest|WarningsTest|DateConversionTest|BadInputTest|FaModelTypeTest|GraphQLActionTest'
docker/fa-graphql test --testsuite integration --filter WritePlumbingTest
```

Expected: FAIL — `Class "FA\GraphQL\Fa\Warnings" not found`, `Class "FA\GraphQL\Fa\DateConversion" not found`, `Call to undefined method FA\GraphQL\Fa\FaMessages::errors()`, `BadInput::field()` undefined, the write resolvers not throwing `Forbidden`, `extensions` missing from the `warn` response; the integration test on `FaTransaction` not found.

- [ ] **Step 5: Implement**

`src/Fa/FaMessages.php` (whole file):

```php
<?php

namespace FA\GraphQL\Fa;

/**
 * FrontAccounting reports validation failures by raising E_USER_ERROR/WARNING/NOTICE
 * through display_error()/display_warning()/display_notification() (fa_trigger_error()
 * routes all three here — see fa_errors_compat.php). They are collected, with their
 * level, so that a write FrontAccounting refused can tell the client why, and a
 * write it accepted with a warning can say so (Release 2 spec section 3.2).
 */
final class FaMessages
{
    /** @var array<int, array{0: int, 1: string}> level, plain text */
    private static array $messages = [];

    public static function add(int $level, string $text): void
    {
        self::$messages[] = [$level, trim(html_entity_decode(strip_tags($text), ENT_QUOTES))];
    }

    /**
     * Every text, whatever its level, in order; clears the buffer.
     *
     * @return string[]
     */
    public static function drain(): array
    {
        $texts = array_column(self::$messages, 1);
        self::$messages = [];

        return $texts;
    }

    /**
     * @return string[] the E_USER_ERROR texts collected so far; the buffer is kept
     */
    public static function errors(): array
    {
        return self::at('errors');
    }

    /**
     * @return string[] the E_USER_WARNING texts collected so far; the buffer is kept
     */
    public static function warnings(): array
    {
        return self::at('warnings');
    }

    /**
     * @return array{errors: string[], warnings: string[], notices: string[]}
     */
    public static function drainByLevel(): array
    {
        $byLevel = ['errors' => self::at('errors'), 'warnings' => self::at('warnings'), 'notices' => self::at('notices')];
        self::$messages = [];

        return $byLevel;
    }

    public static function reset(): void
    {
        self::$messages = [];
    }

    /**
     * @return string[]
     */
    private static function at(string $bucket): array
    {
        $texts = [];
        foreach (self::$messages as [$level, $text]) {
            if (self::bucket($level) === $bucket) {
                $texts[] = $text;
            }
        }

        return $texts;
    }

    private static function bucket(int $level): string
    {
        if ($level === E_USER_ERROR) {
            return 'errors';
        }
        if ($level === E_USER_WARNING) {
            return 'warnings';
        }

        return 'notices';
    }
}
```

`src/Fa/Warnings.php`:

```php
<?php

namespace FA\GraphQL\Fa;

/**
 * FrontAccounting's warnings about work that committed. Its pages abort on any
 * message; an API carries on and tells the client, in the response's top-level
 * extensions.warnings (Release 2 spec section 3.2) — the generated mutations return
 * [<Entity>Type!]!, which has no room for them. Per request: GraphQLAction resets it.
 */
final class Warnings
{
    /** @var string[] */
    private static array $warnings = [];

    public static function add(string $text): void
    {
        if (!in_array($text, self::$warnings, true)) {
            self::$warnings[] = $text;
        }
    }

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return self::$warnings;
    }

    public static function reset(): void
    {
        self::$warnings = [];
    }
}
```

`src/Fa/FaTransaction.php`:

```php
<?php

namespace FA\GraphQL\Fa;

/**
 * One FrontAccounting transaction around a unit of work (Release 2 spec section 3.1).
 *
 * FrontAccounting's transactions nest by counting (includes/db/sql_functions.inc):
 * begin_transaction() issues BEGIN only at level 0, commit_transaction() issues
 * COMMIT only when the count returns to 0. Its own functions (Cart::write(),
 * add_crm_person()) call them inside ours, so they only count. On any throwable this
 * calls cancel_transaction(), which rolls back and — unlike a bare ROLLBACK — sets the
 * level back to 0, so a later write in the same request still issues BEGIN.
 */
final class FaTransaction
{
    /**
     * @return mixed what $work returns
     */
    public static function run(callable $work)
    {
        begin_transaction();
        try {
            $result = $work();
        } catch (\Throwable $e) {
            self::cancel();
            throw $e;
        }
        commit_transaction();

        return $result;
    }

    /**
     * A failing ROLLBACK must not hide the error that caused it: the connection is
     * broken either way, and the original error is the one worth reporting.
     */
    private static function cancel(): void
    {
        try {
            cancel_transaction();
        } catch (\Throwable $ignored) {
            $GLOBALS['transaction_level'] = 0;
        }
    }
}
```

`src/Fa/Service/ServiceCall.php`:

```php
<?php

namespace FA\GraphQL\Fa\Service;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\FaRejected;
use FA\GraphQL\Fa\FaErrorException;
use FA\GraphQL\Fa\FaMessages;
use FA\GraphQL\Fa\FaTransaction;
use FA\GraphQL\Fa\Warnings;

/**
 * How every service method calls FrontAccounting (Release 2 spec sections 2.1, 3):
 * one transaction, FrontAccounting's error messages turned into FA_REJECTED (and the
 * work rolled back), its warnings reported once the work has committed.
 */
final class ServiceCall
{
    /**
     * @return mixed what $work returns
     */
    public static function run(callable $work)
    {
        $warnings = [];
        $result = FaTransaction::run(function () use ($work, &$warnings) {
            FaMessages::reset();
            try {
                $result = $work();
            } catch (FaErrorException $e) {
                throw self::rejected($e, null);
            }
            $warnings = self::check(null);

            return $result;
        });
        self::report($warnings);

        return $result;
    }

    /**
     * A batch — one generated mutation's input list — in one transaction: any refusal
     * rolls back every item, and the error names the item's index.
     *
     * @param array<int|string, mixed> $inputs
     * @return array<int, mixed> $work's results, in order
     */
    public static function each(array $inputs, callable $work): array
    {
        $warnings = [];
        $results = FaTransaction::run(function () use ($inputs, $work, &$warnings) {
            FaMessages::reset();
            $results = [];
            foreach (array_values($inputs) as $index => $input) {
                try {
                    $results[] = $work($input, $index);
                } catch (BadInput $e) {
                    throw $e->index() === null ? $e->withIndex($index) : $e;
                } catch (FaErrorException $e) {
                    throw self::rejected($e, $index);
                }
                $warnings = array_merge($warnings, self::check($index));
            }

            return $results;
        });
        self::report($warnings);

        return $results;
    }

    /**
     * @return string[] the warnings, once errors have been ruled out
     */
    private static function check(?int $index): array
    {
        $messages = FaMessages::drainByLevel();
        if ($messages['errors'] !== []) {
            throw new FaRejected($messages['errors'][0], $messages['errors'], $index);
        }

        return $messages['warnings'];
    }

    /**
     * A database error FrontAccounting explained (its duplicate-key warning, say) is
     * a refusal the client can act on; one it did not explain stays INTERNAL.
     */
    private static function rejected(FaErrorException $e, ?int $index): \Throwable
    {
        $messages = FaMessages::drainByLevel();
        $texts = array_merge($messages['errors'], $messages['warnings']);

        return $texts === [] ? $e : new FaRejected($texts[0], $texts, $index);
    }

    /**
     * @param string[] $warnings
     */
    private static function report(array $warnings): void
    {
        foreach ($warnings as $warning) {
            Warnings::add($warning);
        }
    }
}
```

`src/Fa/DateConversion.php`:

```php
<?php

namespace FA\GraphQL\Fa;

use FA\GraphQL\Error\BadInput;

/**
 * The API's dates are ISO YYYY-MM-DD (the Date scalar). FrontAccounting's functions
 * take dates in the signed-in user's format and call date2sql() on them themselves
 * (includes/date_functions.inc), so a service converts on the way in, and reads
 * back either from SQL (fromSql) or from a Cart (fromFa).
 */
final class DateConversion
{
    private const ISO = '/^(\d{4})-(\d{2})-(\d{2})$/';

    /**
     * @param mixed $date a \DateTimeInterface (the Date scalar's value) or a Y-m-d string
     */
    public static function iso($date, ?string $field = null): string
    {
        if ($date instanceof \DateTimeInterface) {
            return $date->format('Y-m-d');
        }
        // \z, not $: a trailing newline is not a date.
        if (is_string($date) && preg_match('/^(\d{4})-(\d{2})-(\d{2})\z/', $date, $m)
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $date;
        }
        throw new BadInput('Expected a date as YYYY-MM-DD.', $field);
    }

    /**
     * @param mixed $date
     */
    public static function toFa($date, ?string $field = null): string
    {
        return sql2date(self::iso($date, $field));
    }

    public static function fromFa(string $faDate): string
    {
        return date2sql($faDate);
    }

    public static function fromSql(?string $sqlDate): ?string
    {
        if ($sqlDate === null) {
            return null;
        }
        $date = substr(trim($sqlDate), 0, 10);
        if ($date === '' || $date === '0000-00-00') {
            return null;
        }
        if (!preg_match(self::ISO, $date)) {
            throw new \UnexpectedValueException('Not an SQL date: ' . $sqlDate);
        }

        return $date;
    }
}
```

`src/Error/BadInput.php` (whole file):

```php
<?php

namespace FA\GraphQL\Error;

/**
 * The request is malformed or violates a constraint. Names the input field when it
 * can, and — in a batch — the index of the item (Release 2 spec section 5).
 */
class BadInput extends ApiError
{
    private ?string $field;
    private ?int $index;

    public function __construct(string $message, ?string $field = null, ?int $index = null)
    {
        parent::__construct($message);
        $this->field = $field;
        $this->index = $index;
    }

    public function field(): ?string
    {
        return $this->field;
    }

    public function index(): ?int
    {
        return $this->index;
    }

    public function withIndex(int $index): BadInput
    {
        return new BadInput($this->getMessage(), $this->field, $index);
    }

    public function code(): string
    {
        return 'BAD_INPUT';
    }

    public function httpStatus(): int
    {
        return 400;
    }

    public function getExtensions(): ?array
    {
        $extensions = ['code' => $this->code()];
        if ($this->field !== null) {
            $extensions['field'] = $this->field;
        }
        if ($this->index !== null) {
            $extensions['index'] = $this->index;
        }

        return $extensions;
    }
}
```

`src/Error/FaRejected.php` — constructor and `getExtensions()` become:

```php
    /** @var string[] */
    private array $messages;

    private ?int $index;

    /**
     * @param string[] $messages
     * @param int|null $index the batch item FrontAccounting refused, when in a batch
     */
    public function __construct(string $message, array $messages = [], ?int $index = null)
    {
        parent::__construct($message);
        $this->messages = array_values($messages);
        $this->index = $index;
    }

    // code() and httpStatus() unchanged

    public function getExtensions(): ?array
    {
        $extensions = ['code' => $this->code(), 'messages' => $this->messages];
        if ($this->index !== null) {
            $extensions['index'] = $this->index;
        }

        return $extensions;
    }
```

`src/Fa/FaSession.php` — add after `userId()`:

```php
    /**
     * Whether an extension is active for the open company: install_hooks() puts it in
     * $Hooks only when it is active there and its hooks class exists
     * (includes/hooks.inc). False until a company is open (Release 2 spec section 3.3).
     */
    public function isActive(string $package): bool
    {
        return CompanyContext::isSet() && isset($GLOBALS['Hooks'][$package]);
    }
```

`src/Type/FaModelType.php` — add `use FA\GraphQL\Error\Forbidden;`, update the `areas()` docblock example to `['list' => 'SA_SALESORDER']`, and add to the class:

```php
    private const NO_WRITE_PATH = 'This entity is written through FrontAccounting; no write path is declared.';

    /*
     * Release 2 spec section 2.2: no generated write reaches a FrontAccounting table
     * directly. ModelType's own create/update/delete/upsert would write the model's
     * table with Anorm, bypassing FrontAccounting's references, audit trail, hooks and
     * pricing. A writable Type overrides these to authorize() and then call its
     * service through ServiceCall; one that does not refuses, the same way a Type that
     * forgot areas() cannot be loaded.
     */

    public function resolveCreate($root, $args, Container $context): array
    {
        throw new Forbidden(self::NO_WRITE_PATH);
    }

    public function resolveUpdate($root, $args, Container $context): array
    {
        throw new Forbidden(self::NO_WRITE_PATH);
    }

    public function resolveDelete($root, $args, Container $context): array
    {
        throw new Forbidden(self::NO_WRITE_PATH);
    }

    public function resolveUpsert($root, $args, Container $context): array
    {
        throw new Forbidden(self::NO_WRITE_PATH);
    }
```

(If PHPStan reports these signatures incompatible with `ModelType`'s, copy the parent's exact parameter list from `vendor/saygoweb/anorm-graphql/src/ModelType.php` — the override must match it.)

`src/Http/GraphQLAction.php` — add `use FA\GraphQL\Fa\Warnings;`; after the two `RequestRejected` checks and before building `$rules`, add:

```php
        // Per request: a warning belongs to the request whose work committed.
        Warnings::reset();
```

and replace the block from `$result->setErrorFormatter(...)` to the `write(...)` call with:

```php
        $result->setErrorFormatter($this->formatter);
        $output = $result->toArray();

        // FrontAccounting's warnings about work that committed (Release 2 spec
        // section 3.2): the generated mutations return [<Entity>Type!]!, so they
        // travel beside `data`, not in it.
        $warnings = Warnings::all();
        if ($warnings !== []) {
            $output['extensions']['warnings'] = $warnings;
        }

        // JSON_THROW_ON_ERROR: a resolver value that cannot be encoded (a raw
        // INF/NAN from a custom scalar, say) must not produce a 200 with an empty
        // body. It throws instead, so JsonErrorMiddleware renders a 500 INTERNAL
        // JSON body — every response body is JSON (spec section 6).
        $response->getBody()->write(
            (string) json_encode($output, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)
        );
```

- [ ] **Step 6: Run them to see them pass**

```bash
docker/fa-graphql test --testsuite unit --filter 'FaMessagesTest|WarningsTest|DateConversionTest|BadInputTest|FaModelTypeTest|GraphQLActionTest|ApiErrorTest|ErrorFormatterTest'
docker/fa-graphql test --testsuite integration --filter 'WritePlumbingTest|BootstrapTest'
docker/fa-graphql test
```

Expected: PASS. `BootstrapTest` still passes: `drain()` returns the same texts as before. If `testIsActiveNamesTheExtensionsActiveForTheCompany` fails on the sgw_sales line, compare `$GLOBALS['installed_extensions']` after `enter()` with `company/0/installed_extensions.php` in the container (`docker/fa-graphql exec cat ../../company/0/installed_extensions.php`) before changing anything.

- [ ] **Step 7: Gates and commit**

```bash
docker/fa-graphql lint && docker/fa-graphql analyze
bin/generate --dry-run
git add composer.json composer.lock bin/generate src/Fa src/Error src/Type/FaModelType.php src/Http/GraphQLAction.php tests/Unit tests/Integration/WritePlumbingTest.php
git commit -m "Write plumbing: one FrontAccounting transaction, its messages by level, warnings, dates

Requires anorm-graphql ^0.2 and generates with --mutations create-update.
FaModelType refuses every write a Type has not routed through FrontAccounting.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

`bin/generate --dry-run` must report nothing to change.

---

### Task 4: Lookups, generated read-only; SalesType to SA_SALESORDER; fiscal years in the stack

Nine reference tables an order needs, generated `--readonly` exactly as the SalesType pilot was (Release 2 spec §4.2), all listed with `SA_SALESORDER`; SalesType moves to the same area; and the stack adds fiscal years up to today after loading a dataset (spec §7).

**Files:**
- Create: `src/Model/PaymentTermsModel.php`, `TaxGroupModel.php`, `SalesAreaModel.php`, `SalesmanModel.php`, `LocationModel.php`, `ShipperModel.php`, `CreditStatusModel.php`, `CurrencyModel.php`, `StockItemModel.php`
- Generated (by `bin/generate`): `src/Type/<Entity>/Base/<Entity>TypeBase.php` and `src/Type/<Entity>/<Entity>Type.php` for the nine entities, `tests/Generated/<Entity>TypeTest.php`, entries in `src/ApiSchema.php`
- Modify: `bin/generate` (`READONLY`), the nine once-only `src/Type/<Entity>/<Entity>Type.php` (`areas()`), the nine once-only `tests/Generated/<Entity>TypeTest.php` (one dataset test each), `src/Type/SalesType/SalesTypeType.php`, `tests/Unit/ApiSchemaTest.php`, `tests/data/seed.sql`, `tests/Http/StackTest.php`, `docker/fa-graphql`, `docs/superpowers/specs/2026-09-21-foundation-design.md`
- Create tests: `tests/Unit/Type/LookupAreasTest.php` (replaces `tests/Unit/Type/SalesTypeAreasTest.php`, deleted), `tests/Http/LookupsTest.php`

**Interfaces:**
- Consumes: Task 3 (anorm-graphql 0.2, `bin/generate` with `--mutations create-update`, `FaModelType`), the Foundation's model conventions (Foundation spec §4.4; `SalesTypeModel` is the template), `Guard`.
- Produces:
  - Models in `FA\GraphQL\Model`, key property `id` mapped to the key column, booleans through `BooleanTransform`:

    | Model | Table | `id` column | Other properties (column) |
    |---|---|---|---|
    | `PaymentTermsModel` | `payment_terms` | `terms_indicator` | `name` (`terms`), `daysBeforeDue` int, `dayInFollowingMonth` int, `inactive` bool |
    | `TaxGroupModel` | `tax_groups` | `id` | `name`, `inactive` |
    | `SalesAreaModel` | `areas` | `area_code` | `name` (`description`), `inactive` |
    | `SalesmanModel` | `salesman` | `salesman_code` | `name` (`salesman_name`), `phone` (`salesman_phone`), `fax` (`salesman_fax`), `email` (`salesman_email`), `provision` float, `breakPoint` float (`break_pt`), `provision2` float, `inactive` |
    | `LocationModel` | `locations` | `loc_code` (string) | `name` (`location_name`), `deliveryAddress`, `phone`, `phone2`, `fax`, `email`, `contact`, `fixedAsset` bool, `inactive` |
    | `ShipperModel` | `shippers` | `shipper_id` | `name` (`shipper_name`), `phone`, `phone2`, `contact`, `address`, `inactive` |
    | `CreditStatusModel` | `credit_status` | `id` | `description` (`reason_description`), `disallowInvoices` bool (`dissallow_invoices`), `inactive` |
    | `CurrencyModel` | `currencies` | `curr_abrev` (string) | `name` (`currency`), `symbol` (`curr_symbol`), `country`, `hundredsName` (`hundreds_name`), `autoUpdate` bool, `inactive` |
    | `StockItemModel` | `stock_master` | `stock_id` (string) | `categoryId` (`category_id`), `taxTypeId` (`tax_type_id`), `description`, `longDescription`, `units`, `mbFlag` (`mb_flag`), `editable` bool, `noSale` bool (`no_sale`), `inactive` bool |

  - GraphQL: `paymentTermsList`, `taxGroupList`, `salesAreaList`, `salesmanList`, `locationList`, `shipperList`, `creditStatusList`, `currencyList`, `stockItemList`, each `(query: MangoInput): [<Entity>Type!]!`, no mutations. Every lookup Type's `areas()` — and `SalesTypeType::areas()` — is `['list' => 'SA_SALESORDER']`.
  - `bin/generate`: `READONLY="SalesType,PaymentTerms,TaxGroup,SalesArea,Salesman,Location,Shipper,CreditStatus,Currency,StockItem"` (Task 7 appends `SalesOrderLine`).
  - Seed: the `GraphQL API` role holds `SS_SALES` = `12 << 8` = **3072** and `SA_SALESORDER` = `SS_SALES | 3` = **3075** (`includes/access_levels.inc:32`, `:145` upstream). `SA_CUSTOMER` = 3074 and `SA_SALESTRANSVIEW` = 3073 are in the same section; the role copies System Administrator, which holds all three in the demo dataset.
  - Stack: after any `db load`, today is inside a fiscal year and `sys_prefs.f_year` names it.

**Before you start:** the generated `<Entity>Type.php` is an empty subclass until you add `areas()`; PHP refuses to instantiate it, so everything that builds `ApiSchema` fails between Step 5 and Step 6. Do those two steps back to back before running anything.

- [ ] **Step 1: Draft the models from the tables**

`anorm make` (Anorm's own generator, run in the container because it reads the database) drafts a model per table. The drafts are to check the columns against, never files to keep — it names them after the table (`0PaymentTermsModel`, a class name PHP refuses) and knows none of Foundation spec §4.4's conventions.

```bash
mkdir -p tmp/anorm
for t in payment_terms tax_groups areas salesman locations shippers credit_status currencies stock_master; do
  echo fa | docker/fa-graphql exec php vendor/bin/anorm.php make fa_graphql 0_$t \
    -u fa -p --host db -m tmp/anorm -n 'FA\GraphQL\Model'
done
ls tmp/anorm
```

If `-p` does not read the piped password, run the same command inside `docker/fa-graphql shell` and type `fa`. If a draft lands somewhere other than `tmp/anorm` (the Foundation's Task 11 met a path quirk in `-m`), `find tmp -name '*Model.php'`.

Compare each draft's properties with the table below (upstream `sql/en_US-demo.sql`); a column missing from a draft, or one the draft has that the table below lacks, means your FrontAccounting differs — stop and report it.

```sql
CREATE TABLE `0_payment_terms` (`terms_indicator` int(11) AUTO_INCREMENT, `terms` char(80), `days_before_due` smallint(6), `day_in_following_month` smallint(6), `inactive` tinyint(1), PRIMARY KEY (`terms_indicator`));
CREATE TABLE `0_tax_groups` (`id` int(11) AUTO_INCREMENT, `name` varchar(60), `inactive` tinyint(1), PRIMARY KEY (`id`));
CREATE TABLE `0_areas` (`area_code` int(11) AUTO_INCREMENT, `description` varchar(60), `inactive` tinyint(1), PRIMARY KEY (`area_code`));
CREATE TABLE `0_salesman` (`salesman_code` int(11) AUTO_INCREMENT, `salesman_name` char(60), `salesman_phone` char(30), `salesman_fax` char(30), `salesman_email` varchar(100), `provision` double, `break_pt` double, `provision2` double, `inactive` tinyint(1), PRIMARY KEY (`salesman_code`));
CREATE TABLE `0_locations` (`loc_code` varchar(5), `location_name` varchar(60), `delivery_address` tinytext, `phone` varchar(30), `phone2` varchar(30), `fax` varchar(30), `email` varchar(100), `contact` varchar(30), `fixed_asset` tinyint(1), `inactive` tinyint(1), PRIMARY KEY (`loc_code`));
CREATE TABLE `0_shippers` (`shipper_id` int(11) AUTO_INCREMENT, `shipper_name` varchar(60), `phone` varchar(30), `phone2` varchar(30), `contact` tinytext, `address` tinytext, `inactive` tinyint(1), PRIMARY KEY (`shipper_id`));
CREATE TABLE `0_credit_status` (`id` int(11) AUTO_INCREMENT, `reason_description` char(100), `dissallow_invoices` tinyint(1), `inactive` tinyint(1), PRIMARY KEY (`id`));
CREATE TABLE `0_currencies` (`currency` varchar(60), `curr_abrev` char(3), `curr_symbol` varchar(10), `country` varchar(100), `hundreds_name` varchar(15), `auto_update` tinyint(1), `inactive` tinyint(1), PRIMARY KEY (`curr_abrev`));
-- stock_master: stock_id varchar(20) PK, category_id, tax_type_id, description, long_description, units, mb_flag char(1),
-- five GL account columns, dimension_id, dimension2_id, four cost columns, inactive, no_sale, no_purchase, editable,
-- four depreciation columns, fa_class_id. The model maps only the columns listed in the Interfaces table: the
-- entity is read-only, and GL accounts, costs and depreciation are not what an order needs.
```

`rm -r tmp/anorm` once Step 3 is written.

- [ ] **Step 2: Write the failing tests**

`tests/Unit/Type/LookupAreasTest.php` (replaces `SalesTypeAreasTest.php`: `git rm tests/Unit/Type/SalesTypeAreasTest.php`):

```php
<?php

namespace FA\GraphQL\Tests\Unit\Type;

use DI\Container;
use FA\GraphQL\Error\Forbidden;
use FA\GraphQL\Type\CreditStatus\CreditStatusType;
use FA\GraphQL\Type\Currency\CurrencyType;
use FA\GraphQL\Type\Location\LocationType;
use FA\GraphQL\Type\PaymentTerms\PaymentTermsType;
use FA\GraphQL\Type\SalesArea\SalesAreaType;
use FA\GraphQL\Type\Salesman\SalesmanType;
use FA\GraphQL\Type\SalesType\SalesTypeType;
use FA\GraphQL\Type\Shipper\ShipperType;
use FA\GraphQL\Type\StockItem\StockItemType;
use FA\GraphQL\Type\TaxGroup\TaxGroupType;
use PHPUnit\Framework\TestCase;

/**
 * Release 2 spec section 4.2: a role that takes orders reads what an order needs.
 * Every lookup lists with SA_SALESORDER, not FrontAccounting's setup areas
 * (SA_PAYTERMS, SA_CURRENCY, ...), which grant editing in the web UI.
 */
class LookupAreasTest extends TestCase
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

    /**
     * @return array<string, array{0: class-string}>
     */
    public function lookups(): array
    {
        return [
            'SalesType' => [SalesTypeType::class],
            'PaymentTerms' => [PaymentTermsType::class],
            'TaxGroup' => [TaxGroupType::class],
            'SalesArea' => [SalesAreaType::class],
            'Salesman' => [SalesmanType::class],
            'Location' => [LocationType::class],
            'Shipper' => [ShipperType::class],
            'CreditStatus' => [CreditStatusType::class],
            'Currency' => [CurrencyType::class],
            'StockItem' => [StockItemType::class],
        ];
    }

    /**
     * @dataProvider lookups
     */
    public function testListingIsTheOnlyVerbAndItNeedsSalesOrders(string $class): void
    {
        $areas = new \ReflectionMethod($class, 'areas');
        $areas->setAccessible(true);

        $this->assertSame(['list' => 'SA_SALESORDER'], $areas->invoke(new $class()));
    }

    /**
     * @dataProvider lookups
     */
    public function testARoleWithoutSalesOrdersCannotList(string $class): void
    {
        // Setup areas are not enough, and SA_GRAPHQL only lets you into the API.
        $this->signIn(['SA_GRAPHQL', 'SA_SALESTYPES', 'SA_PAYTERMS', 'SA_CURRENCY']);

        $this->expectException(Forbidden::class);
        $this->expectExceptionMessage('SA_SALESORDER');
        (new $class())->resolveList(null, [], new Container());
    }
}
```

`tests/Unit/ApiSchemaTest.php` — replace `testTheRootFieldsAreExactlyTheSpecsAndNothingElse()` with:

```php
    /**
     * Foundation spec §3.1 plus Release 2's lookups (Release 2 spec §4.2): exactly
     * these root fields and nothing else. A new root field is a spec change, not a
     * drive-by. Order is testQueryAndMutationFieldsAreAlphabetical's business.
     */
    public function testTheRootFieldsAreExactlyTheSpecsAndNothingElse(): void
    {
        $schema = $this->schema();
        $query = array_keys($schema->getQueryType()->getFields());
        sort($query);
        $expected = [
            'apiVersion', 'creditStatusList', 'currencyList', 'locationList', 'me',
            'paymentTermsList', 'salesAreaList', 'salesTypeList', 'salesmanList',
            'shipperList', 'stockItemList', 'taxGroupList',
        ];
        sort($expected);

        $this->assertSame($expected, $query);
        $this->assertSame(
            ['login', 'tokenRefresh', 'tokenRevoke'],
            array_keys($schema->getMutationType()->getFields())
        );
        $this->assertNull($schema->getSubscriptionType());
    }
```

`tests/Http/LookupsTest.php`:

```php
<?php

namespace FA\GraphQL\Tests\Http;

use PHPUnit\Framework\TestCase;

/**
 * Every lookup over the real endpoint, as apitest. The ids are rows of FrontAccounting's
 * en_US-demo dataset, which the stack loads.
 */
class LookupsTest extends TestCase
{
    use GraphQLClient;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public function lookups(): array
    {
        return [
            'payment terms' => ['paymentTermsList', '4'],
            'tax groups' => ['taxGroupList', '1'],
            'sales areas' => ['salesAreaList', '1'],
            'salespeople' => ['salesmanList', '1'],
            'locations' => ['locationList', 'DEF'],
            'shippers' => ['shipperList', '1'],
            'credit statuses' => ['creditStatusList', '1'],
            'currencies' => ['currencyList', 'USD'],
            'stock items' => ['stockItemList', '101'],
            'sales types' => ['salesTypeList', '1'],
        ];
    }

    /**
     * @dataProvider lookups
     */
    public function testApitestListsIt(string $field, string $knownId): void
    {
        $response = $this->gql('{ ' . $field . ' { id } }', [], $this->login()['accessToken']);

        $this->assertSame(200, $response['status']);
        $this->assertArrayNotHasKey('errors', $response['body'], $response['raw']);
        $this->assertContains($knownId, array_column($response['body']['data'][$field], 'id'), $response['raw']);
    }

    public function testAMangoSelectorOnAStringKeyFilters(): void
    {
        $response = $this->gql(
            'query ($q: MangoInput) { currencyList(query: $q) { id symbol } }',
            ['q' => ['selector' => json_encode(['id' => 'USD'])]],
            $this->login()['accessToken']
        );

        $this->assertSame([['id' => 'USD', 'symbol' => '$']], $response['body']['data']['currencyList'], $response['raw']);
    }

    public function testNoTokenIsUnauthenticated(): void
    {
        $response = $this->gql('{ paymentTermsList { id } }');

        $this->assertSame('UNAUTHENTICATED', $response['body']['errors'][0]['extensions']['code'] ?? null);
    }
}
```

`tests/Http/StackTest.php` — replace the two lines about `SA_SALESTYPES` in `testSeedUsersExist()` with:

```php
        // SA_SALESORDER = SS_SALES | 3 = (12 << 8) | 3: every lookup lists with it
        // (Release 2 spec section 4.2).
        $this->assertContains('3075', explode(';', $rows['apitest']));
```

and add:

```php
    private function pdo(): \PDO
    {
        return new \PDO(
            'mysql:host=' . getenv('FA_DB_HOST') . ';dbname=' . getenv('FA_DB_NAME'),
            (string) getenv('FA_DB_USER'),
            (string) getenv('FA_DB_PASSWORD')
        );
    }

    /**
     * Release 2 spec section 7: the installer datasets end with fiscal 2022, and a
     * document dated today needs a fiscal year around today. db load adds them.
     */
    public function testTodayIsInsideTheCurrentFiscalYear(): void
    {
        $prefix = (string) getenv('FA_DB_PREFIX');
        $covering = $this->pdo()->query(
            "SELECT id FROM {$prefix}fiscal_year WHERE `begin` <= CURDATE() AND `end` >= CURDATE()"
        )->fetchColumn();
        $current = $this->pdo()->query("SELECT value FROM {$prefix}sys_prefs WHERE name = 'f_year'")->fetchColumn();

        $this->assertNotFalse($covering, 'no fiscal year covers today');
        $this->assertSame((string) $covering, (string) $current);
    }
```

- [ ] **Step 3: Run them to see them fail**

```bash
docker/fa-graphql test --testsuite unit --filter 'LookupAreasTest|ApiSchemaTest'
docker/fa-graphql test --testsuite http --filter 'LookupsTest|StackTest'
```

Expected: FAIL — `Class "FA\GraphQL\Type\PaymentTerms\PaymentTermsType" not found`; the root field set lacks the nine lists; `LookupsTest` gets `Cannot query field "paymentTermsList"`; `StackTest` finds no `3075` (unless role 2 already carried it — then that assertion passes, and the fiscal-year test still fails: `no fiscal year covers today`).

- [ ] **Step 4: The models**

Each follows `src/Model/SalesTypeModel.php`: public typed properties with PHP defaults for the `NOT NULL DEFAULT` columns, the constructor taking the PDO first (falling back to `Connection::current()`), the table prefixed by `CompanyContext::prefix()`, an explicit property → column map, `BooleanTransform` keyed by column for every `tinyint(1)` flag. Read-only: `bin/generate` lists them in `READONLY`.

`src/Model/PaymentTermsModel.php`:

```php
<?php

namespace FA\GraphQL\Model;

use Anorm\DataMapper;
use Anorm\Model;
use Anorm\Transform\BooleanTransform;
use FA\GraphQL\Db\Connection;
use FA\GraphQL\Fa\CompanyContext;

/**
 * Payment terms: FrontAccounting's payment_terms. Read-only through the API. Cash
 * sale when both day counts are 0; prepaid when daysBeforeDue is -1.
 */
class PaymentTermsModel extends Model
{
    /** @var int */
    public $id;

    /** @var string */
    public $name = '';

    /** @var int Days after the invoice date; -1 for prepaid terms */
    public $daysBeforeDue = 0;

    /** @var int Day of the following month the payment is due; 0 when not used */
    public $dayInFollowingMonth = 0;

    /** @var bool */
    public $inactive = false;

    public function __construct(?\PDO $pdo = null)
    {
        $pdo = $pdo ?: Connection::current();
        $mapper = DataMapper::create($pdo, CompanyContext::prefix() . 'payment_terms', [
            'id' => 'terms_indicator',
            'name' => 'terms',
            'daysBeforeDue' => 'days_before_due',
            'dayInFollowingMonth' => 'day_in_following_month',
            'inactive' => 'inactive',
        ]);
        $mapper->transformers = ['inactive' => new BooleanTransform()];
        parent::__construct($pdo, $mapper);
    }
}
```

`src/Model/TaxGroupModel.php`:

```php
<?php

namespace FA\GraphQL\Model;

use Anorm\DataMapper;
use Anorm\Model;
use Anorm\Transform\BooleanTransform;
use FA\GraphQL\Db\Connection;
use FA\GraphQL\Fa\CompanyContext;

/**
 * A tax group: FrontAccounting's tax_groups. Read-only through the API.
 */
class TaxGroupModel extends Model
{
    /** @var int */
    public $id;

    /** @var string */
    public $name = '';

    /** @var bool */
    public $inactive = false;

    public function __construct(?\PDO $pdo = null)
    {
        $pdo = $pdo ?: Connection::current();
        $mapper = DataMapper::create($pdo, CompanyContext::prefix() . 'tax_groups', [
            'id' => 'id',
            'name' => 'name',
            'inactive' => 'inactive',
        ]);
        $mapper->transformers = ['inactive' => new BooleanTransform()];
        parent::__construct($pdo, $mapper);
    }
}
```

`src/Model/SalesAreaModel.php`:

```php
<?php

namespace FA\GraphQL\Model;

use Anorm\DataMapper;
use Anorm\Model;
use Anorm\Transform\BooleanTransform;
use FA\GraphQL\Db\Connection;
use FA\GraphQL\Fa\CompanyContext;

/**
 * A sales area: FrontAccounting's areas. Read-only through the API.
 */
class SalesAreaModel extends Model
{
    /** @var int */
    public $id;

    /** @var string */
    public $name = '';

    /** @var bool */
    public $inactive = false;

    public function __construct(?\PDO $pdo = null)
    {
        $pdo = $pdo ?: Connection::current();
        $mapper = DataMapper::create($pdo, CompanyContext::prefix() . 'areas', [
            'id' => 'area_code',
            'name' => 'description',
            'inactive' => 'inactive',
        ]);
        $mapper->transformers = ['inactive' => new BooleanTransform()];
        parent::__construct($pdo, $mapper);
    }
}
```

`src/Model/SalesmanModel.php`:

```php
<?php

namespace FA\GraphQL\Model;

use Anorm\DataMapper;
use Anorm\Model;
use Anorm\Transform\BooleanTransform;
use FA\GraphQL\Db\Connection;
use FA\GraphQL\Fa\CompanyContext;

/**
 * A salesperson: FrontAccounting's salesman. Read-only through the API.
 */
class SalesmanModel extends Model
{
    /** @var int */
    public $id;

    /** @var string */
    public $name = '';

    /** @var string */
    public $phone = '';

    /** @var string */
    public $fax = '';

    /** @var string */
    public $email = '';

    /** @var float Commission percentage up to the break point */
    public $provision = 0.0;

    /** @var float Turnover at which provision2 applies */
    public $breakPoint = 0.0;

    /** @var float Commission percentage above the break point */
    public $provision2 = 0.0;

    /** @var bool */
    public $inactive = false;

    public function __construct(?\PDO $pdo = null)
    {
        $pdo = $pdo ?: Connection::current();
        $mapper = DataMapper::create($pdo, CompanyContext::prefix() . 'salesman', [
            'id' => 'salesman_code',
            'name' => 'salesman_name',
            'phone' => 'salesman_phone',
            'fax' => 'salesman_fax',
            'email' => 'salesman_email',
            'provision' => 'provision',
            'breakPoint' => 'break_pt',
            'provision2' => 'provision2',
            'inactive' => 'inactive',
        ]);
        $mapper->transformers = ['inactive' => new BooleanTransform()];
        parent::__construct($pdo, $mapper);
    }
}
```

`src/Model/LocationModel.php`:

```php
<?php

namespace FA\GraphQL\Model;

use Anorm\DataMapper;
use Anorm\Model;
use Anorm\Transform\BooleanTransform;
use FA\GraphQL\Db\Connection;
use FA\GraphQL\Fa\CompanyContext;

/**
 * A stock location: FrontAccounting's locations, keyed by its code ('DEF'). Read-only
 * through the API.
 */
class LocationModel extends Model
{
    /** @var string */
    public $id;

    /** @var string */
    public $name = '';

    /** @var string */
    public $deliveryAddress = '';

    /** @var string */
    public $phone = '';

    /** @var string */
    public $phone2 = '';

    /** @var string */
    public $fax = '';

    /** @var string */
    public $email = '';

    /** @var string */
    public $contact = '';

    /** @var bool A location for fixed assets, not for selling from */
    public $fixedAsset = false;

    /** @var bool */
    public $inactive = false;

    public function __construct(?\PDO $pdo = null)
    {
        $pdo = $pdo ?: Connection::current();
        $mapper = DataMapper::create($pdo, CompanyContext::prefix() . 'locations', [
            'id' => 'loc_code',
            'name' => 'location_name',
            'deliveryAddress' => 'delivery_address',
            'phone' => 'phone',
            'phone2' => 'phone2',
            'fax' => 'fax',
            'email' => 'email',
            'contact' => 'contact',
            'fixedAsset' => 'fixed_asset',
            'inactive' => 'inactive',
        ]);
        $mapper->transformers = [
            'fixed_asset' => new BooleanTransform(),
            'inactive' => new BooleanTransform(),
        ];
        parent::__construct($pdo, $mapper);
    }
}
```

`src/Model/ShipperModel.php`:

```php
<?php

namespace FA\GraphQL\Model;

use Anorm\DataMapper;
use Anorm\Model;
use Anorm\Transform\BooleanTransform;
use FA\GraphQL\Db\Connection;
use FA\GraphQL\Fa\CompanyContext;

/**
 * A shipping company: FrontAccounting's shippers. Read-only through the API.
 */
class ShipperModel extends Model
{
    /** @var int */
    public $id;

    /** @var string */
    public $name = '';

    /** @var string */
    public $phone = '';

    /** @var string */
    public $phone2 = '';

    /** @var string */
    public $contact = '';

    /** @var string */
    public $address = '';

    /** @var bool */
    public $inactive = false;

    public function __construct(?\PDO $pdo = null)
    {
        $pdo = $pdo ?: Connection::current();
        $mapper = DataMapper::create($pdo, CompanyContext::prefix() . 'shippers', [
            'id' => 'shipper_id',
            'name' => 'shipper_name',
            'phone' => 'phone',
            'phone2' => 'phone2',
            'contact' => 'contact',
            'address' => 'address',
            'inactive' => 'inactive',
        ]);
        $mapper->transformers = ['inactive' => new BooleanTransform()];
        parent::__construct($pdo, $mapper);
    }
}
```

`src/Model/CreditStatusModel.php`:

```php
<?php

namespace FA\GraphQL\Model;

use Anorm\DataMapper;
use Anorm\Model;
use Anorm\Transform\BooleanTransform;
use FA\GraphQL\Db\Connection;
use FA\GraphQL\Fa\CompanyContext;

/**
 * A credit status: FrontAccounting's credit_status. Read-only through the API. A
 * customer whose status disallows invoices cannot be put on an order
 * (get_customer_details_to_order).
 */
class CreditStatusModel extends Model
{
    /** @var int */
    public $id;

    /** @var string */
    public $description = '';

    /** @var bool The customer is on hold */
    public $disallowInvoices = false;

    /** @var bool */
    public $inactive = false;

    public function __construct(?\PDO $pdo = null)
    {
        $pdo = $pdo ?: Connection::current();
        $mapper = DataMapper::create($pdo, CompanyContext::prefix() . 'credit_status', [
            'id' => 'id',
            'description' => 'reason_description',
            // FrontAccounting's spelling, in the column only.
            'disallowInvoices' => 'dissallow_invoices',
            'inactive' => 'inactive',
        ]);
        $mapper->transformers = [
            'dissallow_invoices' => new BooleanTransform(),
            'inactive' => new BooleanTransform(),
        ];
        parent::__construct($pdo, $mapper);
    }
}
```

`src/Model/CurrencyModel.php`:

```php
<?php

namespace FA\GraphQL\Model;

use Anorm\DataMapper;
use Anorm\Model;
use Anorm\Transform\BooleanTransform;
use FA\GraphQL\Db\Connection;
use FA\GraphQL\Fa\CompanyContext;

/**
 * A currency: FrontAccounting's currencies, keyed by its code ('USD'). Read-only
 * through the API.
 */
class CurrencyModel extends Model
{
    /** @var string */
    public $id;

    /** @var string */
    public $name = '';

    /** @var string */
    public $symbol = '';

    /** @var string */
    public $country = '';

    /** @var string The name of the hundredth part ('Cents') */
    public $hundredsName = '';

    /** @var bool Exchange rates are updated automatically */
    public $autoUpdate = true;

    /** @var bool */
    public $inactive = false;

    public function __construct(?\PDO $pdo = null)
    {
        $pdo = $pdo ?: Connection::current();
        $mapper = DataMapper::create($pdo, CompanyContext::prefix() . 'currencies', [
            'id' => 'curr_abrev',
            'name' => 'currency',
            'symbol' => 'curr_symbol',
            'country' => 'country',
            'hundredsName' => 'hundreds_name',
            'autoUpdate' => 'auto_update',
            'inactive' => 'inactive',
        ]);
        $mapper->transformers = [
            'auto_update' => new BooleanTransform(),
            'inactive' => new BooleanTransform(),
        ];
        parent::__construct($pdo, $mapper);
    }
}
```

`src/Model/StockItemModel.php`:

```php
<?php

namespace FA\GraphQL\Model;

use Anorm\DataMapper;
use Anorm\Model;
use Anorm\Transform\BooleanTransform;
use FA\GraphQL\Db\Connection;
use FA\GraphQL\Fa\CompanyContext;

/**
 * A stock item: FrontAccounting's stock_master, keyed by its code ('101'). Read-only
 * through the API, and only the columns an order needs: GL accounts, costs and
 * depreciation are left out.
 *
 * Sellable, as FrontAccounting's order entry offers items: mbFlag is not 'F' (fixed
 * asset), and neither inactive nor noSale. A client filters with a Mango selector.
 */
class StockItemModel extends Model
{
    /** @var string */
    public $id;

    /** @var int */
    public $categoryId = 0;

    /** @var int */
    public $taxTypeId = 0;

    /** @var string */
    public $description = '';

    /** @var string */
    public $longDescription = '';

    /** @var string */
    public $units = 'each';

    /** @var string 'B' bought, 'M' manufactured, 'D' service, 'F' fixed asset */
    public $mbFlag = 'B';

    /** @var bool The description may be changed on an order line */
    public $editable = false;

    /** @var bool */
    public $noSale = false;

    /** @var bool */
    public $inactive = false;

    public function __construct(?\PDO $pdo = null)
    {
        $pdo = $pdo ?: Connection::current();
        $mapper = DataMapper::create($pdo, CompanyContext::prefix() . 'stock_master', [
            'id' => 'stock_id',
            'categoryId' => 'category_id',
            'taxTypeId' => 'tax_type_id',
            'description' => 'description',
            'longDescription' => 'long_description',
            'units' => 'units',
            'mbFlag' => 'mb_flag',
            'editable' => 'editable',
            'noSale' => 'no_sale',
            'inactive' => 'inactive',
        ]);
        $mapper->transformers = [
            'editable' => new BooleanTransform(),
            'no_sale' => new BooleanTransform(),
            'inactive' => new BooleanTransform(),
        ];
        parent::__construct($pdo, $mapper);
    }
}
```

`categoryId` and `taxTypeId` end in `Id`, so the generator types them `ID` (anorm-graphql's `TypeInfoBuilder::graphQLType`: the key, then an `Id` suffix, then the declared type).

- [ ] **Step 5: Generate**

`bin/generate` — the `READONLY` line becomes:

```bash
READONLY="SalesType,PaymentTerms,TaxGroup,SalesArea,Salesman,Location,Shipper,CreditStatus,Currency,StockItem"
```

On the host:

```bash
bin/generate
```

Expected, for each of the nine entities (`PaymentTerms` shown), and nothing `skipped`:

```
written  src/Type/PaymentTerms/Base/PaymentTermsTypeBase.php
written  src/Type/PaymentTerms/PaymentTermsType.php
written  tests/Generated/PaymentTermsTypeTest.php
```

then `current`/`kept` for SalesType's files and `tests/Generated/TestCase.php`, and `updated  src/ApiSchema.php`. A `skipped … could not be loaded` line means the model did not construct on the generator's `NullPdo`: fix it and run again. Each `<Entity>TypeBase` must begin `abstract class <Entity>TypeBase extends \FA\GraphQL\Type\FaModelType`.

- [ ] **Step 6: Areas** — immediately after Step 5.

In each of the nine once-only files — `src/Type/PaymentTerms/PaymentTermsType.php`, `src/Type/TaxGroup/TaxGroupType.php`, `src/Type/SalesArea/SalesAreaType.php`, `src/Type/Salesman/SalesmanType.php`, `src/Type/Location/LocationType.php`, `src/Type/Shipper/ShipperType.php`, `src/Type/CreditStatus/CreditStatusType.php`, `src/Type/Currency/CurrencyType.php`, `src/Type/StockItem/StockItemType.php` — add to the class body:

```php
    /**
     * Read-only: listing is the only verb, and it needs the area of the work the
     * lookup serves — taking orders — not FrontAccounting's setup area for this table,
     * which grants editing it in the web UI (Release 2 spec section 4.2).
     *
     * @return array<string, string>
     */
    protected function areas(): array
    {
        return ['list' => 'SA_SALESORDER'];
    }
```

`src/Type/SalesType/SalesTypeType.php` — replace its `areas()` with the same method (the docblock above replaces the old one).

- [ ] **Step 7: A dataset test in each generated test**

The generated `tests/Generated/<Entity>TypeTest.php` files are yours once written. Add to each the test below for its entity (the rows are FrontAccounting's `en_US-demo` dataset; ids come back as strings, being `ID`s):

`PaymentTermsTypeTest`:

```php
    public function testTheDatasetsPaymentTermsComeBackTyped(): void
    {
        // en_US-demo: (1, 'Due 15th Of the Following Month', 0, 17, 0), (4, 'Cash Only', 0, 0, 0).
        $this->useDatabase();
        $byId = array_column($this->listAll(), null, 'id');

        $this->assertSame('Due 15th Of the Following Month', $byId['1']['name']);
        $this->assertSame(17, $byId['1']['dayInFollowingMonth']);
        $this->assertSame(0, $byId['4']['daysBeforeDue']);
        $this->assertFalse($byId['4']['inactive']);
    }
```

`TaxGroupTypeTest`:

```php
    public function testTheDatasetsTaxGroupsComeBack(): void
    {
        // en_US-demo: (1, 'Tax', 0), (2, 'Tax Exempt', 0).
        $this->useDatabase();
        $byId = array_column($this->listAll(), null, 'id');

        $this->assertSame('Tax', $byId['1']['name']);
        $this->assertSame('Tax Exempt', $byId['2']['name']);
        $this->assertFalse($byId['2']['inactive']);
    }
```

`SalesAreaTypeTest`:

```php
    public function testTheDatasetsSalesAreaComesBack(): void
    {
        // en_US-demo: (1, 'Global', 0).
        $this->useDatabase();
        $byId = array_column($this->listAll(), null, 'id');

        $this->assertSame('Global', $byId['1']['name']);
        $this->assertFalse($byId['1']['inactive']);
    }
```

`SalesmanTypeTest`:

```php
    public function testTheDatasetsSalespersonComesBackTyped(): void
    {
        // en_US-demo: (1, 'Sales Person', '', '', '', 5, 1000, 4, 0).
        $this->useDatabase();
        $byId = array_column($this->listAll(), null, 'id');

        $this->assertSame('Sales Person', $byId['1']['name']);
        $this->assertSame(5.0, $byId['1']['provision']);
        $this->assertSame(1000.0, $byId['1']['breakPoint']);
        $this->assertSame(4.0, $byId['1']['provision2']);
    }
```

`LocationTypeTest`:

```php
    public function testTheDatasetsLocationComesBackByItsCode(): void
    {
        // en_US-demo: ('DEF', 'Default', 'N/A', '', '', '', '', '', 0, 0).
        $this->useDatabase();
        $byId = array_column($this->listAll(), null, 'id');

        $this->assertSame('Default', $byId['DEF']['name']);
        $this->assertSame('N/A', $byId['DEF']['deliveryAddress']);
        $this->assertFalse($byId['DEF']['fixedAsset']);
    }
```

`ShipperTypeTest`:

```php
    public function testTheDatasetsShipperComesBack(): void
    {
        // en_US-demo: (1, 'Default', '', '', '', '', 0).
        $this->useDatabase();
        $byId = array_column($this->listAll(), null, 'id');

        $this->assertSame('Default', $byId['1']['name']);
        $this->assertFalse($byId['1']['inactive']);
    }
```

`CreditStatusTypeTest`:

```php
    public function testTheDatasetsCreditStatusesComeBackTyped(): void
    {
        // en_US-demo: (1, 'Good History', 0, 0), (3, 'No more work until payment received', 1, 0).
        $this->useDatabase();
        $byId = array_column($this->listAll(), null, 'id');

        $this->assertSame('Good History', $byId['1']['description']);
        $this->assertFalse($byId['1']['disallowInvoices']);
        $this->assertTrue($byId['3']['disallowInvoices']);
    }
```

`CurrencyTypeTest`:

```php
    public function testTheDatasetsCurrenciesComeBackByCode(): void
    {
        // en_US-demo: ('US Dollars', 'USD', '$', 'United States', 'Cents', 1, 0).
        $this->useDatabase();
        $byId = array_column($this->listAll(), null, 'id');

        $this->assertSame('US Dollars', $byId['USD']['name']);
        $this->assertSame('$', $byId['USD']['symbol']);
        $this->assertSame('Cents', $byId['USD']['hundredsName']);
        $this->assertTrue($byId['USD']['autoUpdate']);
    }
```

`StockItemTypeTest`:

```php
    public function testTheDatasetsStockItemsComeBackByCode(): void
    {
        // en_US-demo: ('101', 1, 1, 'iPad Air 2 16GB', '', 'each', 'B', ...),
        //             ('201', 3, 1, 'AP Surf Set', '', 'each', 'M', ...).
        $this->useDatabase();
        $byId = array_column($this->listAll(), null, 'id');

        $this->assertSame('iPad Air 2 16GB', $byId['101']['description']);
        $this->assertSame('B', $byId['101']['mbFlag']);
        $this->assertSame('1', $byId['101']['categoryId']);
        $this->assertFalse($byId['101']['noSale']);
        $this->assertSame('M', $byId['201']['mbFlag']);
    }
```

Leave the generated `expectedFieldTypes()`, `sampleInput()` and `sampleUpdate()` as the generator wrote them. If a generated test calls `useDatabase()` differently from `SalesTypeTypeTest`, follow the generated file.

- [ ] **Step 8: Seed, fiscal years, and the Foundation spec's note**

`tests/data/seed.sql` — replace the whole `SA_SALESTYPES` block at the end (the comment and both `UPDATE`s) with:

```sql
-- SA_SALESORDER: every lookup lists with it (Release 2 spec section 4.2), and orders
-- are written with it. Section SS_SALES = 12 << 8 = 3072, area SS_SALES | 3 = 3075
-- (includes/access_levels.inc). Role 2 holds them in the demo dataset; appended only
-- when missing, so the role holds them whatever role 2 looks like.
UPDATE `0_security_roles`
SET `sections` = CONCAT(`sections`, ';3072')
WHERE `role` = 'GraphQL API' AND FIND_IN_SET('3072', REPLACE(`sections`, ';', ',')) = 0;

UPDATE `0_security_roles`
SET `areas` = CONCAT(`areas`, ';3075')
WHERE `role` = 'GraphQL API' AND FIND_IN_SET('3075', REPLACE(`areas`, ';', ',')) = 0;
```

`docker/fa-graphql` — add before `db_post_load()` (ported from `sgw_sales`' `docker/fa-sgw-sales`):

```bash
# FrontAccounting will not post a document dated outside a fiscal year, and a new
# Cart's default date falls back to the end of the current fiscal year when today is
# outside every one (sales/includes/cart_class.inc). The installer datasets end with
# fiscal 2022. Years are added after the last one, each a calendar year long, until
# today is inside one, and that one is made current. Ported from sgw_sales'
# docker/fa-sgw-sales; idempotent.
ensure_fiscal_year() {
    local fy="\`${DB_PREFIX}fiscal_year\`" covered added=0
    while :; do
        covered="$(db_mysql -N -B "$DB_NAME" -e \
            "SELECT COUNT(*) FROM $fy WHERE \`begin\` <= CURDATE() AND \`end\` >= CURDATE()" 2>/dev/null)" || return 0
        [ "${covered:-1}" = 0 ] || break
        # An empty table has no last year to follow, and twenty is a dataset
        # nobody should be extending a year at a time.
        [ "$added" -lt 20 ] || break
        db_mysql "$DB_NAME" -e \
            "INSERT INTO $fy (\`begin\`, \`end\`, closed)
             SELECT MAX(\`end\`) + INTERVAL 1 DAY, MAX(\`end\`) + INTERVAL 1 YEAR, 0 FROM $fy HAVING MAX(\`end\`) IS NOT NULL" || return 0
        added=$((added + 1))
    done
    [ "$added" -gt 0 ] || return 0
    db_mysql "$DB_NAME" -e \
        "UPDATE \`${DB_PREFIX}sys_prefs\` SET value =
            (SELECT id FROM $fy WHERE \`begin\` <= CURDATE() AND \`end\` >= CURDATE() LIMIT 1)
          WHERE name = 'f_year'"
    info "added $added fiscal year(s) so that today can be posted to"
}
```

and in `db_post_load()`, immediately before `info "applying tests/data/seed.sql"`:

```bash
    ensure_fiscal_year
```

`docs/superpowers/specs/2026-09-21-foundation-design.md` — where §4.5 says `SalesTypeType::areas()` is `['list' => 'SA_SALESTYPES']` and the seed role gains `SA_SALESTYPES`, and where §8 and §10 name `SA_SALESTYPES` in the seed, append to each: `*(revised by Release 2: `SA_SALESORDER`, Release 2 spec §4.2)*`.

Reload the stack's database so the seed and the fiscal years apply:

```bash
docker/fa-graphql db reset
```

Expected: the log includes `added 4 fiscal year(s) so that today can be posted to` (2023 to 2026 on 2026-09-25; the count grows with the date), then `applying tests/data/seed.sql`. A second `docker/fa-graphql db load` adds none.

- [ ] **Step 9: Run them to see them pass**

```bash
docker/fa-graphql test --testsuite unit --filter 'LookupAreasTest|ApiSchemaTest|FaModelTypeTest'
docker/fa-graphql test --testsuite integration --filter 'TypeTest'
docker/fa-graphql test --testsuite http --filter 'LookupsTest|StackTest|SalesTypeTest'
docker/fa-graphql test
```

Expected: PASS. The generated tests pass their structural checks (fields, types) and the dataset test; their lifecycle tests skip or are absent for read-only entities, as `SalesTypeTypeTest`'s are. `testQueryAndMutationFieldsAreAlphabetical` passes because the generator placed the new entries alphabetically — if it fails, the generator and the test disagree about ordering (byte order versus case-insensitive): read the entries' order in `src/ApiSchema.php` and report it rather than reordering generated entries by hand.

If a dataset test fails on the fork (`FA_REPO`/`FA_REF` for `cambell-prince/frontaccounting` @ `master-cp`) but passes on upstream, the fork's `sql/en_US-demo.sql` differs: report the row; do not weaken the assertion.

- [ ] **Step 10: Gates and commit**

```bash
docker/fa-graphql lint && docker/fa-graphql analyze
bin/generate --dry-run
git add bin/generate src/Model src/Type src/ApiSchema.php tests/Generated tests/Unit/Type tests/Unit/ApiSchemaTest.php \
  tests/Http/LookupsTest.php tests/Http/StackTest.php tests/data/seed.sql docker/fa-graphql \
  docs/superpowers/specs/2026-09-21-foundation-design.md
git commit -m "Lookups, generated read-only and listed with SA_SALESORDER; fiscal years up to today

Payment terms, tax groups, sales areas, salespeople, locations, shippers,
credit statuses, currencies and stock items. SalesType moves to the same area.
db load adds fiscal years until today is inside one.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

`bin/generate --dry-run` must report nothing to change; `git status --short` must be clean afterwards.

---

### Task 5: Customers — model, generated Type, CustomerService

Customers are created, updated and deleted through FrontAccounting's own functions the way `sales/manage/customers.php` does it: `customerCreate` also makes the default branch and CRM contact while `auto_create_branch` is on. The schema is what `anorm-graphql` generates from `CustomerModel` (`--mutations create-update`); the once-only `CustomerType` routes the three write resolvers to `CustomerService`. Spec §2.1, §3, §4.1, §4.3, §5.

Line numbers below are upstream `master` (`git -C ../.. show upstream/master:<path>`); the fork's copies of these files do not differ.

**Files:**
- Modify: `src/Fa/Bootstrap.php` (add `includeFa()`), `src/Type/FaModelType.php` (add `intId()`, `intIds()`, `rowsById()`), `tests/Generated/TestCase.php` (READ COMMITTED; sweep hook), `tests/Unit/ApiSchemaTest.php` (root fields)
- Create: `src/Fa/Service/FaIncludes.php`, `src/Fa/Service/ReferenceCheck.php`, `src/Fa/Service/BranchReferences.php`, `src/Fa/Service/CustomerService.php`, `src/Model/CustomerModel.php`, `src/Type/Customer/BranchDefaultsInput.php`, `src/Type/Customer/ContactDetailsInput.php`, `tests/Support/FaTestRows.php`
- Generated by `bin/generate`, then the once-only ones replaced as shown: `src/Type/Customer/Base/CustomerTypeBase.php`, `src/Type/Customer/Base/CustomerCreateInputBase.php`, `src/Type/Customer/Base/CustomerUpdateInputBase.php` (never edited), `src/Type/Customer/CustomerType.php`, `src/Type/Customer/CustomerCreateInput.php`, `src/Type/Customer/CustomerUpdateInput.php` (kept as generated), `tests/Generated/CustomerTypeTest.php`, `src/ApiSchema.php` entries
- Test: `tests/Unit/Type/FaModelTypeHelpersTest.php`, `tests/Integration/Service/CustomerServiceTest.php`, `tests/Generated/CustomerTypeTest.php`

**Interfaces:**
- Consumes (Task 3): `Fa\Service\ServiceCall::run(callable)` and `ServiceCall::each(array $inputs, callable $work): array` (a batch in one FaTransaction; `$work($input, $index)`; a `BadInput`/`FaRejected` comes out carrying the item's `index`), `Error\BadInput(string $message, ?string $field = null, ?int $index = null)` with `field()`, `index()`, `withIndex()` and `field`/`index` in `extensions`, `Error\NotFound`, `Error\FaRejected(string $message, array $messages = [])`, `Type\FaModelType` (fail-closed `resolveCreate/resolveUpdate/resolveDelete/resolveUpsert`), `bin/generate` with `--mutations create-update`. From anorm-graphql 0.2 (Tasks 1–2): `ModelType::resolveCreate/resolveUpdate($root, $args, Container $context): array`, `ModelType::VERB_*`, `@required` docblock tag → non-null in `<Entity>CreateInput`, once-only Inputs with a `fields()` hook. From Task 4: the lookup models exist (`SalesType`, `PaymentTerms`, `CreditStatus`, `Currency`, `Salesman`, `SalesArea`, `TaxGroup`, `Location`, `Shipper`) and `bin/generate`'s READONLY line lists them.
- Produces (Tasks 6–10 rely on these):
  - `Bootstrap::includeFa(string $relativePath): void` — include one more FrontAccounting file after boot, as though from file scope. *(Not in the contract: additive; if Task 3 already added an equivalent public include helper, use it and skip Step 4a.)*
  - `FaModelType::intId($id, string $field = 'id'): int` and `intIds(array $ids): array` (protected static) — a client ID that must be a positive integer, else `BadInput` (carrying the list index, for `intIds`).
  - `FaModelType::rowsById(Container $context, array $ids): array` (protected) — the rows for these keys through `resolveList` (so `scope()` and the list area apply), in order; a missing key is `NotFound`.
  - `Fa\Service\FaIncludes::customers(): void` — includes `sales/includes/sales_db.inc` and `includes/db/crm_contacts_db.inc`.
  - `Fa\Service\ReferenceCheck::exists(string $table, string $column, $value): bool`, `ReferenceCheck::requireAll(array $values, array $refs, string $fieldPrefix = ''): void` (`$refs`: field => `[table, column, noun]`).
  - `Fa\Service\BranchReferences::REFS` and `BranchReferences::validated(array $values, string $fieldPrefix = ''): array` — the five references a branch needs (`salesmanId`, `salesAreaId`, `taxGroupId`, `locationId`, `shipperId`).
  - `Fa\Service\CustomerService`: `create(array $input): int`, `update(array $input): void` (`$input['id']` an int), `delete(int $id): void` — as the contract; callers run them inside `ServiceCall::run()`.
  - `Model\CustomerModel` — key `id` (`debtor_no`); properties `name`, `ref`, `address`, `taxId`, `currencyId`, `salesTypeId`, `creditStatusId`, `paymentTermsId`, `discountPercent`, `paymentDiscountPercent`, `creditLimit`, `notes`, `inactive`.
  - `Type\Customer\BranchDefaultsInput` (GraphQL `BranchDefaultsInput`), `Type\Customer\ContactDetailsInput` (GraphQL `ContactDetailsInput`).
  - `Tests\Support\FaTestRows`: `prefix(): string`, `connect(): \PDO`, `sweep(\PDO $pdo, string $prefix, string $tb = '0_'): void`.
  - `tests/Generated/TestCase.php`: `protected function testRowPrefix(): ?string` (default `null`); when non-null, `tearDown()` sweeps rows with that prefix.
- **Naming, a deliberate difference from the spec's hand design:** spec §4.3's `BranchDefaultsInput` names `locationCode`; here it is `locationId`, because the generated `Branch` Type (Task 6) names `default_location` `locationId` (Foundation spec §4.4: a foreign key ends in `Id`), and the spec says generation wins. `currencyId` likewise replaces the hand design's `currency`.
- **Dates:** none in this task. (Contract change noted: a hand-built date field or input would use `\Anorm\GraphQL\Type\DateType::instance()`.)

**Facts this task rests on (read, not assumed):**
- `add_customer($CustName, $cust_ref, $address, $tax_id, $curr_code, $dimension_id, $dimension2_id, $credit_status, $payment_terms, $discount, $pymt_discount, $credit_limit, $sales_type, $notes)` — `sales/includes/db/customers_db.inc:14-29`. `$discount`, `$pymt_discount` and `$credit_limit` are concatenated into the SQL **unquoted**; everything else goes through `db_escape()`. It returns nothing: the id is `db_insert_id()`.
- `update_customer($customer_id, …same…)` — `customers_db.inc:31-52`; same unquoted three. It does not touch `inactive`: the page calls `update_record_status($id, $inactive, 'debtors_master', 'debtor_no')` (`customers.php:95-96`; `includes/db/sql_functions.inc:58-63`).
- `delete_customer($id)` — `customers_db.inc:54-64`: its own `begin_transaction()`, `delete_entity_contacts('customer', $id)` (which deletes persons left with no links, `includes/db/crm_contacts_db.inc:176-187`), the row, attachments.
- `can_process()` — `customers.php:39-77`: name empty, short name empty, credit limit numeric `>= 0`, payment discount 0–100, discount 0–100, in that order, first failure wins. The discounts are entered in percent and stored as fractions (`input_num('discount') / 100`, `:92`, `:107`).
- New customer — `customers.php:104-129`: inside `begin_transaction()`, `add_customer`; then, when `$SysPrefs->auto_create_branch == 1` (`config.default.php:63`, default `1`; `sys_prefs::__construct` copies every config variable onto `$SysPrefs`, `includes/prefs/sysprefs.inc:24-35`), `add_branch($id, name, ref, address, salesman, area, tax_group_id, '', get_company_pref('default_sales_discount_act'), get_company_pref('debtors_act'), get_company_pref('default_prompt_payment_act'), location, address, 0, ship_via, notes, bank_account)`, then `add_crm_person(ref, name, '', address, phone, phone2, fax, email, '', '')` and `add_crm_contact('cust_branch', 'general', $branch, $person)`, `add_crm_contact('customer', 'general', $id, $person)`.
- New-customer defaults — `customers.php:196-205`: currency `get_company_currency()` (`includes/banking.inc:23-26`, the `curr_default` pref), discounts 0, credit limit `$SysPrefs->default_credit_limit()`, which is `$this->prefs['default_credit_limit']` (`sysprefs.inc:93-96`) — the company pref `get_company_pref('default_credit_limit')` reads.
- Currency lock — `customers.php:240-249`: the currency is offered for change only while the customer has no `debtor_trans` and no `sales_orders` rows.
- Delete guards — `customers.php:152-175`, in order: `debtor_trans`, `sales_orders`, `cust_branch` (`key_in_foreign_table()`, `admin/db/company_db.inc:146-167`).
- `debtor_ref` is `UNIQUE` (`sql/en_US-new.sql`, `0_debtors_master`): the page relies on the database error for a duplicate; the API names the field instead (`get_customer_by_ref()`, `customers_db.inc:187-193`).
- Loading: `sales/includes/sales_db.inc` includes `banking.inc`, `branches_db.inc` and `customers_db.inc` (`:12-28`); `includes/db/crm_contacts_db.inc` is included by no file `Bootstrap` loads. `admin/db/company_db.inc` (`key_in_foreign_table`, `get_company_pref`) is loaded by `sysprefs.inc:12`.
- Demo data (`en_US-demo.sql`): salesman 1, area 1, tax groups 1–2, location `DEF`, shipper 1, sales types 1–2, payment terms 1–4, credit status 1/3/4, currencies CAD/EUR/GBP/USD, company currency USD, `default_credit_limit` 1000.
- The `GraphQL API` seed role is a copy of role 2 (System Administrator) plus `SA_GRAPHQL`, and Task 4 appends section 3072 and area 3075 (`SA_SALESORDER`). `SA_CUSTOMER` is `SS_SALES|2` = 3074 (`includes/access_levels.inc:143`), which role 2 holds in the demo dataset; Step 1's first test asserts it. If it fails, append 3074 to `tests/data/seed.sql` exactly as Task 4 appends 3075 (idempotent `UPDATE … FIND_IN_SET`), reload (`docker/fa-graphql db load`), and say so in the report.

- [ ] **Step 1: Write the failing tests**

`tests/Support/FaTestRows.php` (support code, not a test):

```php
<?php

namespace FA\GraphQL\Tests\Support;

/**
 * Cleans up what a test wrote through FrontAccounting.
 *
 * FrontAccounting writes on its own mysqli connection and commits there, so no PDO
 * transaction a test holds can roll those rows back. Every test that writes through
 * a service gives its rows a unique reference prefix, and its tearDown sweeps them.
 */
final class FaTestRows
{
    public static function prefix(): string
    {
        return 'gqlt' . bin2hex(random_bytes(4));
    }

    public static function connect(): \PDO
    {
        $c = $GLOBALS['db_connections'][0];
        $pdo = new \PDO("mysql:host={$c['host']};dbname={$c['dbname']}", $c['dbuser'], $c['dbpassword']);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    /**
     * Deletes customers and branches whose reference starts with $prefix, the
     * branches of those customers, and every CRM person whose reference starts with
     * it or who is linked to one of them — with their links.
     */
    public static function sweep(\PDO $pdo, string $prefix, string $tb = '0_'): void
    {
        $like = $prefix . '%';
        $customers = self::column($pdo, "SELECT debtor_no FROM {$tb}debtors_master WHERE debtor_ref LIKE ?", [$like]);
        $branchSql = "SELECT branch_code FROM {$tb}cust_branch WHERE branch_ref LIKE ?";
        if ($customers !== []) {
            $branchSql .= ' OR debtor_no IN (' . self::marks($customers) . ')';
        }
        $branches = self::column($pdo, $branchSql, array_merge([$like], $customers));
        $persons = self::column($pdo, "SELECT id FROM {$tb}crm_persons WHERE ref LIKE ?", [$like]);

        foreach ([['customer', $customers], ['cust_branch', $branches]] as [$type, $ids]) {
            if ($ids === []) {
                continue;
            }
            $where = 'type = ? AND entity_id IN (' . self::marks($ids) . ')';
            $params = array_merge([$type], $ids);
            $persons = array_merge($persons, self::column($pdo, "SELECT person_id FROM {$tb}crm_contacts WHERE $where", $params));
            self::run($pdo, "DELETE FROM {$tb}crm_contacts WHERE $where", $params);
        }

        $persons = array_values(array_unique($persons));
        if ($persons !== []) {
            self::run($pdo, "DELETE FROM {$tb}crm_contacts WHERE person_id IN (" . self::marks($persons) . ')', $persons);
            self::run($pdo, "DELETE FROM {$tb}crm_persons WHERE id IN (" . self::marks($persons) . ')', $persons);
        }
        if ($branches !== []) {
            self::run($pdo, "DELETE FROM {$tb}cust_branch WHERE branch_code IN (" . self::marks($branches) . ')', $branches);
        }
        if ($customers !== []) {
            self::run($pdo, "DELETE FROM {$tb}debtors_master WHERE debtor_no IN (" . self::marks($customers) . ')', $customers);
        }
    }

    /**
     * @param array<int, mixed> $params
     * @return array<int, string>
     */
    private static function column(\PDO $pdo, string $sql, array $params): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);

        return array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @param array<int, mixed> $params
     */
    private static function run(\PDO $pdo, string $sql, array $params): void
    {
        $pdo->prepare($sql)->execute($params);
    }

    /**
     * @param array<int, mixed> $values
     */
    private static function marks(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
```

`tests/Unit/Type/FaModelTypeHelpersTest.php`:

```php
<?php

namespace FA\GraphQL\Tests\Unit\Type;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Model\SalesTypeModel;
use FA\GraphQL\Type\FaModelType;
use GraphQL\Type\Definition\Type;
use PHPUnit\Framework\TestCase;

class FaModelTypeHelpersTest extends TestCase
{
    private function type(): object
    {
        return new class extends FaModelType {
            public function __construct()
            {
                parent::__construct(['name' => 'StubType', 'fields' => ['id' => ['type' => Type::id()]]]);
            }

            protected function modelClass(): string
            {
                return SalesTypeModel::class;
            }

            protected function fields(): array
            {
                return [];
            }

            protected function areas(): array
            {
                return [];
            }

            public static function id($id): int
            {
                return self::intId($id);
            }

            public static function ids(array $ids): array
            {
                return self::intIds($ids);
            }
        };
    }

    /**
     * @dataProvider validIds
     */
    public function testAPositiveIntegerIdIsAccepted($given, int $expected): void
    {
        $type = $this->type();
        $this->assertSame($expected, $type::id($given));
    }

    public function validIds(): array
    {
        return ['int' => [5, 5], 'string' => ['42', 42]];
    }

    /**
     * @dataProvider invalidIds
     */
    public function testAnythingElseIsBadInput($given): void
    {
        $type = $this->type();
        $this->expectException(BadInput::class);
        $type::id($given);
    }

    public function invalidIds(): array
    {
        return [
            'zero' => ['0'], 'negative' => ['-1'], 'trailing text' => ['5 anything'],
            'float' => ['1.5'], 'empty' => [''], 'null' => [null], 'array' => [[1]],
            'leading zero' => ['05'], 'too long' => ['12345678901'],
        ];
    }

    public function testABadIdInAListIsNamedByItsIndex(): void
    {
        $type = $this->type();
        $this->assertSame([3, 4], $type::ids(['3', 4]));
        try {
            $type::ids(['3', '4 OR 1=1', '5']);
            $this->fail('a bad id was accepted');
        } catch (BadInput $e) {
            $this->assertSame('id', $e->field());
            $this->assertSame(1, $e->index());
        }
    }
}
```

`tests/Integration/Service/CustomerServiceTest.php`:

```php
<?php

namespace FA\GraphQL\Tests\Integration\Service;

use FA\GraphQL\Auth\Claims;
use FA\GraphQL\Config;
use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\FaRejected;
use FA\GraphQL\Error\NotFound;
use FA\GraphQL\Fa\FaSession;
use FA\GraphQL\Fa\Service\CustomerService;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Tests\Integration\FaTestCase;
use FA\GraphQL\Tests\Support\FaTestRows;

/**
 * CustomerService against FrontAccounting in-process, as apitest. Every row written
 * carries this test's reference prefix and is swept in tearDown.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class CustomerServiceTest extends FaTestCase
{
    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();
        (new FaSession(Config::fromArray(['secret' => str_repeat('k', 32)])))
            ->enter(new Claims(0, 'apitest', 'jti', new \DateTimeImmutable('+5 minutes')));
        $this->prefix = FaTestRows::prefix();
    }

    protected function tearDown(): void
    {
        FaTestRows::sweep($this->pdo(), $this->prefix);
        parent::tearDown();
    }

    private function service(): CustomerService
    {
        return new CustomerService();
    }

    private function input(array $overrides = []): array
    {
        return array_merge([
            'name' => 'GraphQL Test Customer',
            'ref' => $this->prefix . 'c',
            'salesTypeId' => '1',
            'paymentTermsId' => '3',
            'creditStatusId' => '1',
            'branch' => [
                'salesmanId' => '1', 'salesAreaId' => '1', 'taxGroupId' => '1',
                'locationId' => 'DEF', 'shipperId' => '1',
            ],
            'contact' => ['phone' => '555-0100', 'email' => 'gqlt@example.com'],
        ], $overrides);
    }

    private function create(array $input): int
    {
        return ServiceCall::run(function () use ($input): int {
            return $this->service()->create($input);
        });
    }

    private function update(array $input): void
    {
        ServiceCall::run(function () use ($input): void {
            $this->service()->update($input);
        });
    }

    private function delete(int $id): void
    {
        ServiceCall::run(function () use ($id): void {
            $this->service()->delete($id);
        });
    }

    /** @return array<string, mixed>|null */
    private function one(string $sql, array $params): ?array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return array<int, array<string, mixed>> */
    private function all(string $sql, array $params): array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function customerByRef(string $ref): ?array
    {
        return $this->one('SELECT * FROM 0_debtors_master WHERE debtor_ref = ?', [$ref]);
    }

    /** A demo customer with transactions, or the test is skipped. */
    private function customerWithTransactions(): array
    {
        $row = $this->one(
            'SELECT d.* FROM 0_debtors_master d WHERE EXISTS '
            . '(SELECT 1 FROM 0_debtor_trans t WHERE t.debtor_no = d.debtor_no) ORDER BY d.debtor_no LIMIT 1',
            []
        );
        if ($row === null) {
            $this->markTestSkipped('The dataset has no customer with transactions.');
        }

        return $row;
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

    public function testApitestMayWriteCustomers(): void
    {
        $this->assertTrue($_SESSION['wa_current_user']->can_access('SA_CUSTOMER'));
    }

    public function testANewCustomerGetsItsDefaultBranchAndContactAsThePageMakesThem(): void
    {
        $id = $this->create($this->input(['address' => "1 Test Street\nTestville"]));

        $customer = $this->customerByRef($this->prefix . 'c');
        $this->assertSame((string) $id, (string) $customer['debtor_no']);
        $this->assertSame('GraphQL Test Customer', $customer['name']);
        $this->assertSame('USD', $customer['curr_code']);
        $this->assertEquals(1000, $customer['credit_limit']);
        $this->assertEquals(0, $customer['discount']);
        $this->assertEquals(0, $customer['pymt_discount']);
        $this->assertSame('1', (string) $customer['sales_type']);
        $this->assertSame('3', (string) $customer['payment_terms']);
        $this->assertSame('1', (string) $customer['credit_status']);
        $this->assertSame('0', (string) $customer['dimension_id']);

        $branches = $this->all('SELECT * FROM 0_cust_branch WHERE debtor_no = ?', [$id]);
        $this->assertCount(1, $branches);
        $branch = $branches[0];
        $this->assertSame('GraphQL Test Customer', $branch['br_name']);
        $this->assertSame($this->prefix . 'c', $branch['branch_ref']);
        $this->assertSame("1 Test Street\nTestville", $branch['br_address']);
        $this->assertSame("1 Test Street\nTestville", $branch['br_post_address']);
        $this->assertSame('1', (string) $branch['salesman']);
        $this->assertSame('1', (string) $branch['area']);
        $this->assertSame('1', (string) $branch['tax_group_id']);
        $this->assertSame('DEF', $branch['default_location']);
        $this->assertSame('1', (string) $branch['default_ship_via']);
        $this->assertSame('', $branch['sales_account']);
        $this->assertSame((string) get_company_pref('default_sales_discount_act'), $branch['sales_discount_account']);
        $this->assertSame((string) get_company_pref('debtors_act'), $branch['receivables_account']);
        $this->assertSame((string) get_company_pref('default_prompt_payment_act'), $branch['payment_discount_account']);

        $person = $this->one('SELECT * FROM 0_crm_persons WHERE ref = ?', [$this->prefix . 'c']);
        $this->assertSame('GraphQL Test Customer', $person['name']);
        $this->assertSame('555-0100', $person['phone']);
        $this->assertSame('gqlt@example.com', $person['email']);
        $links = $this->all(
            'SELECT type, action, entity_id FROM 0_crm_contacts WHERE person_id = ? ORDER BY type',
            [$person['id']]
        );
        $this->assertSame([
            ['type' => 'cust_branch', 'action' => 'general', 'entity_id' => (string) $branch['branch_code']],
            ['type' => 'customer', 'action' => 'general', 'entity_id' => (string) $id],
        ], $links);
    }

    public function testDiscountsArePercentInTheApiAndFractionsInTheTable(): void
    {
        $this->create($this->input(['discountPercent' => 12.5, 'paymentDiscountPercent' => 2]));

        $customer = $this->customerByRef($this->prefix . 'c');
        $this->assertEqualsWithDelta(0.125, (float) $customer['discount'], 1e-9);
        $this->assertEqualsWithDelta(0.02, (float) $customer['pymt_discount'], 1e-9);
    }

    /**
     * customers.php:39-77, in its order and with its messages.
     *
     * @dataProvider pageChecks
     */
    public function testThePagesChecksRefuse(array $overrides, string $field, string $message): void
    {
        $this->assertBadInput($field, $message, function () use ($overrides): void {
            $this->create($this->input($overrides));
        });
        $this->assertNull($this->customerByRef($this->prefix . 'c'));
    }

    public function pageChecks(): array
    {
        return [
            'empty name' => [['name' => ''], 'name', 'The customer name cannot be empty.'],
            'empty short name' => [['ref' => ''], 'ref', 'The customer short name cannot be empty.'],
            'negative credit limit' => [
                ['creditLimit' => -1], 'creditLimit', 'The credit limit must be numeric and not less than zero.',
            ],
            'payment discount over 100' => [
                ['paymentDiscountPercent' => 100.5], 'paymentDiscountPercent',
                'The payment discount must be numeric and is expected to be less than 100% and greater than or equal to 0.',
            ],
            'negative discount' => [
                ['discountPercent' => -0.5], 'discountPercent',
                'The discount percentage must be numeric and is expected to be less than 100% and greater than or equal to 0.',
            ],
        ];
    }

    public function testAShortNameInUseIsRefusedByName(): void
    {
        $this->create($this->input());

        $this->assertBadInput(
            'ref',
            "A customer with the short name '{$this->prefix}c' already exists.",
            function (): void {
                $this->create($this->input(['name' => 'Another']));
            }
        );
    }

    /**
     * @dataProvider unknownReferences
     */
    public function testUnknownReferencesAreRefused(array $overrides, string $field, string $message): void
    {
        $this->assertBadInput($field, $message, function () use ($overrides): void {
            $this->create(array_replace_recursive($this->input(), $overrides));
        });
        $this->assertNull($this->customerByRef($this->prefix . 'c'));
    }

    public function unknownReferences(): array
    {
        return [
            'sales type' => [['salesTypeId' => '999'], 'salesTypeId', "There is no sales type '999'."],
            'payment terms' => [['paymentTermsId' => '999'], 'paymentTermsId', "There is no payment terms '999'."],
            'credit status' => [['creditStatusId' => '999'], 'creditStatusId', "There is no credit status '999'."],
            'currency' => [['currencyId' => 'XXX'], 'currencyId', "There is no currency 'XXX'."],
            'salesperson' => [
                ['branch' => ['salesmanId' => '999']], 'branch.salesmanId', "There is no salesperson '999'.",
            ],
            'location' => [['branch' => ['locationId' => 'NOPE']], 'branch.locationId', "There is no location 'NOPE'."],
        ];
    }

    public function testANewCustomerNeedsBranchDefaultsWhileAutoCreateBranchIsOn(): void
    {
        $input = $this->input();
        unset($input['branch']);

        $this->assertBadInput(
            'branch',
            'A new customer needs its default branch (auto_create_branch is on): give branch.',
            function () use ($input): void {
                $this->create($input);
            }
        );
    }

    public function testWithAutoCreateBranchOffOnlyTheCustomerIsWritten(): void
    {
        $GLOBALS['SysPrefs']->auto_create_branch = 0;
        $input = $this->input();
        unset($input['branch'], $input['contact']);

        $id = $this->create($input);

        $this->assertNotNull($this->customerByRef($this->prefix . 'c'));
        $this->assertSame([], $this->all('SELECT * FROM 0_cust_branch WHERE debtor_no = ?', [$id]));
        $this->assertSame([], $this->all("SELECT * FROM 0_crm_contacts WHERE type = 'customer' AND entity_id = ?", [$id]));
    }

    public function testWithAutoCreateBranchOffBranchDefaultsAreRefused(): void
    {
        $GLOBALS['SysPrefs']->auto_create_branch = 0;

        $this->assertBadInput(
            'branch',
            'auto_create_branch is off, so no branch or contact is created with the customer: '
            . 'leave out branch and use branchCreate or contactCreate.',
            function (): void {
                $this->create($this->input());
            }
        );
    }

    public function testAnUpdateChangesWhatItNamesAndKeepsTheRest(): void
    {
        $id = $this->create($this->input(['discountPercent' => 10]));

        $this->update(['id' => $id, 'name' => 'Renamed', 'paymentDiscountPercent' => 5, 'inactive' => true]);

        $customer = $this->customerByRef($this->prefix . 'c');
        $this->assertSame('Renamed', $customer['name']);
        $this->assertEqualsWithDelta(0.05, (float) $customer['pymt_discount'], 1e-9);
        $this->assertEqualsWithDelta(0.1, (float) $customer['discount'], 1e-9);
        $this->assertSame('1', (string) $customer['inactive']);
        $this->assertSame('1', (string) $customer['sales_type']);
        $this->assertSame('USD', $customer['curr_code']);
    }

    public function testNullForARequiredFieldIsBadInput(): void
    {
        $id = $this->create($this->input());

        $this->assertBadInput('name', 'name cannot be null.', function () use ($id): void {
            $this->update(['id' => $id, 'name' => null]);
        });
    }

    public function testAnUpdateOfAMissingCustomerIsNotFound(): void
    {
        $this->expectException(NotFound::class);
        $this->update(['id' => 999999, 'name' => 'Nobody']);
    }

    public function testANewCustomersCurrencyCanChange(): void
    {
        $id = $this->create($this->input());

        $this->update(['id' => $id, 'currencyId' => 'EUR']);

        $this->assertSame('EUR', $this->customerByRef($this->prefix . 'c')['curr_code']);
    }

    public function testTheCurrencyOfACustomerWithTransactionsCannotChange(): void
    {
        $customer = $this->customerWithTransactions();
        $other = $customer['curr_code'] === 'EUR' ? 'GBP' : 'EUR';

        try {
            $this->update(['id' => (int) $customer['debtor_no'], 'currencyId' => $other]);
            $this->fail('a booked customer changed currency');
        } catch (FaRejected $e) {
            $this->assertSame(
                'The currency of a customer with transactions or sales orders cannot be changed.',
                $e->getMessage()
            );
        }
        $after = $this->one('SELECT curr_code FROM 0_debtors_master WHERE debtor_no = ?', [$customer['debtor_no']]);
        $this->assertSame($customer['curr_code'], $after['curr_code']);
    }

    public function testDeleteFollowsThePagesGuards(): void
    {
        $id = $this->create($this->input());

        try {
            $this->delete($id);
            $this->fail('a customer with a branch was deleted');
        } catch (FaRejected $e) {
            $this->assertSame('Cannot delete this customer because there are branch records set up against it.', $e->getMessage());
        }

        $booked = $this->customerWithTransactions();
        try {
            $this->delete((int) $booked['debtor_no']);
            $this->fail('a customer with transactions was deleted');
        } catch (FaRejected $e) {
            $this->assertSame('This customer cannot be deleted because there are transactions that refer to it.', $e->getMessage());
        }

        $branch = $this->one('SELECT branch_code FROM 0_cust_branch WHERE debtor_no = ?', [$id]);
        ServiceCall::run(function () use ($id, $branch): void {
            delete_branch($id, $branch['branch_code']);
        });
        $this->delete($id);

        $this->assertNull($this->customerByRef($this->prefix . 'c'));
        // delete_branch then delete_customer each drop their links; the person, left
        // with none, goes with the customer (delete_entity_contacts).
        $this->assertNull($this->one('SELECT id FROM 0_crm_persons WHERE ref = ?', [$this->prefix . 'c']));
    }

    public function testDeleteOfAMissingCustomerIsNotFound(): void
    {
        $this->expectException(NotFound::class);
        $this->delete(999999);
    }

    public function testARefusalRollsBackTheWholeCallAndTheNextCallStillCommits(): void
    {
        try {
            ServiceCall::run(function (): void {
                $this->service()->create($this->input());
                $this->service()->create($this->input(['ref' => $this->prefix . 'd', 'name' => '']));
            });
            $this->fail('the second create was accepted');
        } catch (BadInput $e) {
            $this->assertSame('name', $e->field());
        }
        $this->assertNull($this->customerByRef($this->prefix . 'c'), 'the first create was not rolled back');

        // FaTransaction's cancel_transaction() reset $transaction_level: this commits.
        $this->create($this->input(['ref' => $this->prefix . 'e']));
        $this->assertNotNull($this->customerByRef($this->prefix . 'e'));
    }
}
```

`tests/Generated/CustomerTypeTest.php` is written by `bin/generate` in Step 5; its GraphQL tests are in Step 5.

- [ ] **Step 2: Run to see them fail**

Run: `docker/fa-graphql test --testsuite unit --filter FaModelTypeHelpersTest`
Expected: FAIL — `Call to undefined method …::intId()`.

Run: `docker/fa-graphql test --testsuite integration --filter CustomerServiceTest`
Expected: FAIL — `Class "FA\GraphQL\Fa\Service\CustomerService" not found` (and `FaTestRows` loads).

- [ ] **Step 3: Implement the helpers**

a. `src/Fa/Bootstrap.php` — add after `boot()`:

```php
    /**
     * Includes one more FrontAccounting file after boot, as though from file scope
     * (see includeGlobal()). For the write paths — sales/includes/sales_db.inc,
     * includes/db/crm_contacts_db.inc, ... — that no request needs until it writes.
     * include_once: calling it twice is free.
     */
    public static function includeFa(string $relativePath): void
    {
        if (!self::$booted) {
            throw new \LogicException("FrontAccounting is not booted, so $relativePath cannot be included.");
        }
        self::includeGlobal($GLOBALS['path_to_root'] . '/' . ltrim($relativePath, '/'));
    }
```

b. `src/Type/FaModelType.php` — add these `use` lines and methods (keep everything Task 3 put there):

```php
use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\NotFound;
```

```php
    /**
     * A client-supplied ID naming an integer key. GraphQL hands IDs over as strings;
     * a string MySQL would cast ("5 anything" is 5) is refused here, not searched.
     *
     * @param mixed $id
     */
    protected static function intId($id, string $field = 'id'): int
    {
        if (is_int($id) && $id > 0) {
            return $id;
        }
        if (is_string($id) && preg_match('/^[1-9][0-9]{0,9}$/', $id) === 1 && (int) $id <= 2147483647) {
            return (int) $id;
        }
        throw new BadInput("$field must be a positive whole number.", $field);
    }

    /**
     * intId() for each ID of a delete's list, naming the index of a bad one.
     *
     * @param array<int, mixed> $ids
     * @return array<int, int>
     */
    protected static function intIds(array $ids): array
    {
        $checked = [];
        foreach (array_values($ids) as $index => $id) {
            try {
                $checked[] = self::intId($id);
            } catch (BadInput $e) {
                throw $e->withIndex($index);
            }
        }

        return $checked;
    }

    /**
     * The rows for these keys, read back after a FrontAccounting write the way a list
     * reads them — through resolveList, so scope() and the list verb's area apply —
     * in the order given. FrontAccounting has committed on its own connection by
     * then; the container's PDO, outside any transaction, sees it. A key with no row
     * is NOT_FOUND.
     *
     * @param array<int, int|string> $ids
     * @return array<int, array<string, mixed>>
     */
    protected function rowsById(Container $context, array $ids): array
    {
        $rows = [];
        foreach ($ids as $id) {
            $found = $this->resolveList(null, ['query' => ['selector' => json_encode(['id' => $id])]], $context);
            if (count($found) !== 1) {
                throw new NotFound($this->name . " id '" . substr((string) $id, 0, 40) . "' not found");
            }
            $rows[] = $found[0];
        }

        return $rows;
    }
```

(`NotFound` takes the message as its only argument; it inherits `\Exception`'s constructor.)

c. `src/Fa/Service/FaIncludes.php`:

```php
<?php

namespace FA\GraphQL\Fa\Service;

use FA\GraphQL\Fa\Bootstrap;

/**
 * The FrontAccounting files a write path needs, included on first use: Bootstrap
 * loads only what every request needs.
 */
final class FaIncludes
{
    /**
     * sales_db.inc brings customers_db.inc, branches_db.inc and banking.inc
     * (get_company_currency); crm_contacts_db.inc is included by nothing Bootstrap
     * loads. key_in_foreign_table() and get_company_pref() come with sysprefs.inc.
     */
    public static function customers(): void
    {
        Bootstrap::includeFa('sales/includes/sales_db.inc');
        Bootstrap::includeFa('includes/db/crm_contacts_db.inc');
    }
}
```

d. `src/Fa/Service/ReferenceCheck.php`:

```php
<?php

namespace FA\GraphQL\Fa\Service;

use FA\GraphQL\Error\BadInput;

/**
 * FrontAccounting's pages offer references from lists; an API must check what it
 * is sent. Through FrontAccounting's own key_in_foreign_table()
 * (admin/db/company_db.inc:146), which db_escape()s the value.
 */
final class ReferenceCheck
{
    /**
     * @param mixed $value
     */
    public static function exists(string $table, string $column, $value): bool
    {
        return (int) key_in_foreign_table($value, $table, $column) > 0;
    }

    /**
     * @param array<string, mixed> $values field => value
     * @param array<string, array{0: string, 1: string, 2: string}> $refs field => [table, column, noun]
     */
    public static function requireAll(array $values, array $refs, string $fieldPrefix = ''): void
    {
        foreach ($refs as $field => [$table, $column, $noun]) {
            $value = $values[$field] ?? null;
            if ($value === null || $value === '') {
                throw new BadInput("$fieldPrefix$field is required.", $fieldPrefix . $field);
            }
            if (!self::exists($table, $column, $value)) {
                throw new BadInput("There is no $noun '$value'.", $fieldPrefix . $field);
            }
        }
    }
}
```

e. `src/Fa/Service/BranchReferences.php`:

```php
<?php

namespace FA\GraphQL\Fa\Service;

/**
 * The five references every branch carries, named as the generated Branch Type
 * names them (Task 6): customer_branches.php requires each to exist before a branch
 * can be added (:29-37) and offers them from lists.
 */
final class BranchReferences
{
    public const REFS = [
        'salesmanId' => ['salesman', 'salesman_code', 'salesperson'],
        'salesAreaId' => ['areas', 'area_code', 'sales area'],
        'taxGroupId' => ['tax_groups', 'id', 'tax group'],
        'locationId' => ['locations', 'loc_code', 'location'],
        'shipperId' => ['shippers', 'shipper_id', 'shipper'],
    ];

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed> just the five, checked
     */
    public static function validated(array $values, string $fieldPrefix = ''): array
    {
        ReferenceCheck::requireAll($values, self::REFS, $fieldPrefix);

        return array_intersect_key($values, self::REFS);
    }
}
```

- [ ] **Step 4: Implement `CustomerService` and the model**

`src/Fa/Service/CustomerService.php`:

```php
<?php

namespace FA\GraphQL\Fa\Service;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\FaRejected;
use FA\GraphQL\Error\NotFound;

/**
 * Customers, written through FrontAccounting's own functions the way
 * sales/manage/customers.php writes them (upstream master line numbers).
 *
 * Run it inside ServiceCall::run(): it opens no transaction of its own. The page's
 * begin_transaction()/commit_transaction() around a new customer (:104, :129) is the
 * caller's one transaction here, and FrontAccounting's own nested begin/commit
 * (add_crm_person) only counts levels inside it.
 *
 * Input is the generated CustomerCreateInput / CustomerUpdateInput as an array:
 * discounts in percent (the page's input_num('discount') / 100, :92), IDs as GraphQL
 * hands them over (strings).
 */
final class CustomerService
{
    /** The generated input fields this service writes (the key and inactive aside). */
    private const FIELDS = [
        'name', 'ref', 'address', 'taxId', 'currencyId', 'salesTypeId', 'creditStatusId',
        'paymentTermsId', 'discountPercent', 'paymentDiscountPercent', 'creditLimit', 'notes',
    ];

    /** Columns that may be NULL; any other explicit null is refused. */
    private const NULLABLE = ['address'];

    private const REFS = [
        'salesTypeId' => ['sales_types', 'id', 'sales type'],
        'paymentTermsId' => ['payment_terms', 'terms_indicator', 'payment terms'],
        'creditStatusId' => ['credit_status', 'id', 'credit status'],
        'currencyId' => ['currencies', 'curr_abrev', 'currency'],
    ];

    /**
     * @param array<string, mixed> $input
     */
    public function create(array $input): int
    {
        FaIncludes::customers();
        $customer = $this->validated($input, self::defaults(), null);

        $autoBranch = self::autoCreateBranch();
        if ($autoBranch && !isset($input['branch'])) {
            throw new BadInput(
                'A new customer needs its default branch (auto_create_branch is on): give branch.',
                'branch'
            );
        }
        if (!$autoBranch) {
            foreach (['branch', 'contact'] as $field) {
                if (isset($input[$field])) {
                    throw new BadInput(
                        'auto_create_branch is off, so no branch or contact is created with the customer: '
                        . "leave out $field and use branchCreate or contactCreate.",
                        $field
                    );
                }
            }
        }
        $branch = $autoBranch ? BranchReferences::validated($input['branch'], 'branch.') : null;

        // customers.php:105-110. Dimensions are out of scope (spec §1): 0, as the new-customer form.
        add_customer(
            $customer['name'],
            $customer['ref'],
            (string) $customer['address'],
            $customer['taxId'],
            $customer['currencyId'],
            0,
            0,
            $customer['creditStatusId'],
            $customer['paymentTermsId'],
            self::sqlNumber($customer['discountPercent'] / 100),
            self::sqlNumber($customer['paymentDiscountPercent'] / 100),
            self::sqlNumber($customer['creditLimit']),
            $customer['salesTypeId'],
            $customer['notes']
        );
        $id = (int) db_insert_id();

        if ($branch !== null) {
            // customers.php:112-128: the default branch, its GL accounts from the
            // company preferences, and one CRM person linked to both.
            add_branch(
                $id,
                $customer['name'],
                $customer['ref'],
                (string) $customer['address'],
                $branch['salesmanId'],
                $branch['salesAreaId'],
                $branch['taxGroupId'],
                '',
                get_company_pref('default_sales_discount_act'),
                get_company_pref('debtors_act'),
                get_company_pref('default_prompt_payment_act'),
                $branch['locationId'],
                (string) $customer['address'],
                0,
                $branch['shipperId'],
                $customer['notes'],
                ''
            );
            $branchId = (int) db_insert_id();

            $contact = $input['contact'] ?? [];
            $personId = add_crm_person(
                $customer['ref'],
                $customer['name'],
                '',
                (string) $customer['address'],
                (string) ($contact['phone'] ?? ''),
                (string) ($contact['phone2'] ?? ''),
                (string) ($contact['fax'] ?? ''),
                (string) ($contact['email'] ?? ''),
                '',
                ''
            );
            add_crm_contact('cust_branch', 'general', $branchId, $personId);
            add_crm_contact('customer', 'general', $id, $personId);
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $input with an int 'id'
     */
    public function update(array $input): void
    {
        FaIncludes::customers();
        $id = (int) $input['id'];
        $row = get_customer($id);
        if (!$row) {
            throw new NotFound("Customer id '$id' not found");
        }
        $customer = $this->validated($input, self::fromRow($row), $id);

        // customers.php:240-249: the currency is offered for change only while
        // nothing is booked against the customer.
        if ($customer['currencyId'] !== $row['curr_code'] && self::hasTransactionsOrOrders($id)) {
            throw new FaRejected('The currency of a customer with transactions or sales orders cannot be changed.');
        }

        // customers.php:90-96. Dimensions are kept as they are.
        update_customer(
            $id,
            $customer['name'],
            $customer['ref'],
            (string) $customer['address'],
            $customer['taxId'],
            $customer['currencyId'],
            $row['dimension_id'],
            $row['dimension2_id'],
            $customer['creditStatusId'],
            $customer['paymentTermsId'],
            self::sqlNumber($customer['discountPercent'] / 100),
            self::sqlNumber($customer['paymentDiscountPercent'] / 100),
            self::sqlNumber($customer['creditLimit']),
            $customer['salesTypeId'],
            $customer['notes']
        );
        if (array_key_exists('inactive', $input) && $input['inactive'] !== null) {
            update_record_status($id, $input['inactive'] ? 1 : 0, 'debtors_master', 'debtor_no');
        }
    }

    public function delete(int $id): void
    {
        FaIncludes::customers();
        if (!get_customer($id)) {
            throw new NotFound("Customer id '$id' not found");
        }
        // customers.php:152-175, in the page's order and with its messages.
        if (key_in_foreign_table($id, 'debtor_trans', 'debtor_no')) {
            throw new FaRejected('This customer cannot be deleted because there are transactions that refer to it.');
        }
        if (key_in_foreign_table($id, 'sales_orders', 'debtor_no')) {
            throw new FaRejected('Cannot delete the customer record because orders have been created against it.');
        }
        if (key_in_foreign_table($id, 'cust_branch', 'debtor_no')) {
            throw new FaRejected('Cannot delete this customer because there are branch records set up against it.');
        }
        delete_customer($id);
    }

    /**
     * $base with the input's fields over it, then checked: can_process()
     * (customers.php:39-77, its order and messages), then what the page gets from
     * its lists and the unique key.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    private function validated(array $input, array $base, ?int $id): array
    {
        $customer = $base;
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            if ($input[$field] === null && !in_array($field, self::NULLABLE, true)) {
                throw new BadInput("$field cannot be null.", $field);
            }
            $customer[$field] = $input[$field];
        }

        if (strlen((string) $customer['name']) === 0) {
            throw new BadInput('The customer name cannot be empty.', 'name');
        }
        if (strlen((string) $customer['ref']) === 0) {
            throw new BadInput('The customer short name cannot be empty.', 'ref');
        }
        if (!is_numeric($customer['creditLimit']) || (float) $customer['creditLimit'] < 0) {
            throw new BadInput('The credit limit must be numeric and not less than zero.', 'creditLimit');
        }
        if (!self::isPercent($customer['paymentDiscountPercent'])) {
            throw new BadInput(
                'The payment discount must be numeric and is expected to be less than 100% '
                . 'and greater than or equal to 0.',
                'paymentDiscountPercent'
            );
        }
        if (!self::isPercent($customer['discountPercent'])) {
            throw new BadInput(
                'The discount percentage must be numeric and is expected to be less than 100% '
                . 'and greater than or equal to 0.',
                'discountPercent'
            );
        }

        // debtor_ref is UNIQUE; the page lets the database refuse a duplicate. An API
        // names the field.
        $same = get_customer_by_ref($customer['ref']);
        if ($same && (int) $same['debtor_no'] !== $id) {
            throw new BadInput("A customer with the short name '{$customer['ref']}' already exists.", 'ref');
        }
        ReferenceCheck::requireAll($customer, self::REFS);

        return $customer;
    }

    /**
     * customers.php:196-205, the new-customer form. The credit limit is
     * $SysPrefs->default_credit_limit(), which is this company pref
     * (sysprefs.inc:93-96).
     *
     * @return array<string, mixed>
     */
    private static function defaults(): array
    {
        return [
            'name' => '',
            'ref' => '',
            'address' => '',
            'taxId' => '',
            'currencyId' => get_company_currency(),
            'salesTypeId' => null,
            'creditStatusId' => null,
            'paymentTermsId' => null,
            'discountPercent' => 0,
            'paymentDiscountPercent' => 0,
            'creditLimit' => get_company_pref('default_credit_limit'),
            'notes' => '',
        ];
    }

    /**
     * @param array<string, mixed> $row debtors_master
     * @return array<string, mixed>
     */
    private static function fromRow(array $row): array
    {
        return [
            'name' => $row['name'],
            'ref' => $row['debtor_ref'],
            'address' => $row['address'],
            'taxId' => $row['tax_id'],
            'currencyId' => $row['curr_code'],
            'salesTypeId' => $row['sales_type'],
            'creditStatusId' => $row['credit_status'],
            'paymentTermsId' => $row['payment_terms'],
            'discountPercent' => (float) $row['discount'] * 100,
            'paymentDiscountPercent' => (float) $row['pymt_discount'] * 100,
            'creditLimit' => $row['credit_limit'],
            'notes' => $row['notes'],
        ];
    }

    /** customers.php:112 */
    private static function autoCreateBranch(): bool
    {
        return isset($GLOBALS['SysPrefs']->auto_create_branch) && (int) $GLOBALS['SysPrefs']->auto_create_branch === 1;
    }

    private static function hasTransactionsOrOrders(int $id): bool
    {
        return key_in_foreign_table($id, 'debtor_trans', 'debtor_no') > 0
            || key_in_foreign_table($id, 'sales_orders', 'debtor_no') > 0;
    }

    /** @param mixed $value */
    private static function isPercent($value): bool
    {
        return is_numeric($value) && (float) $value >= 0 && (float) $value <= 100;
    }

    /**
     * add_customer() and update_customer() put discount, pymt_discount and
     * credit_limit into their SQL unquoted (customers_db.inc:19-26, :43-45): a
     * number, never locale-formatted (PHP 7.4's (string) of a float honours
     * LC_NUMERIC), never exponent notation.
     */
    private static function sqlNumber(float $value): string
    {
        return sprintf('%.6F', $value);
    }
}
```

`src/Model/CustomerModel.php`:

```php
<?php

namespace FA\GraphQL\Model;

use Anorm\DataMapper;
use Anorm\Model;
use Anorm\Transform\BooleanTransform;
use Anorm\Transform\FunctionTransform;
use FA\GraphQL\Db\Connection;
use FA\GraphQL\Fa\CompanyContext;

/**
 * A customer: FrontAccounting's debtors_master. Written only through
 * CustomerService (CustomerType routes the generated create, update and delete
 * there); read through the generated Type. Drafted by `anorm make`, then given the
 * conventions of the Foundation spec §4.4. Dimensions are left out (spec §1).
 * `@required` marks what customerCreate must be given (non-null in
 * CustomerCreateInput).
 */
class CustomerModel extends Model
{
    /** @var int */
    public $id;

    /**
     * @var string
     * @required
     */
    public $name = '';

    /**
     * @var string Short name, unique (debtor_ref)
     * @required
     */
    public $ref = '';

    /** @var string|null */
    public $address;

    /** @var string Tax registration number (the page's "GSTNo") */
    public $taxId = '';

    /** @var string Currency code (curr_code); defaults to the company currency */
    public $currencyId = '';

    /**
     * @var int Price list (sales_type)
     * @required
     */
    public $salesTypeId;

    /**
     * @var int
     * @required
     */
    public $creditStatusId;

    /**
     * @var int
     * @required
     */
    public $paymentTermsId;

    /** @var float 0-100; stored as a fraction */
    public $discountPercent = 0.0;

    /** @var float 0-100; stored as a fraction (pymt_discount) */
    public $paymentDiscountPercent = 0.0;

    /** @var float */
    public $creditLimit = 0.0;

    /** @var string */
    public $notes = '';

    /** @var bool */
    public $inactive = false;

    public function __construct(?\PDO $pdo = null)
    {
        $pdo = $pdo ?: Connection::current();
        $mapper = DataMapper::create($pdo, CompanyContext::prefix() . 'debtors_master', [
            'id' => 'debtor_no',
            'name' => 'name',
            'ref' => 'debtor_ref',
            'address' => 'address',
            'taxId' => 'tax_id',
            'currencyId' => 'curr_code',
            'salesTypeId' => 'sales_type',
            'creditStatusId' => 'credit_status',
            'paymentTermsId' => 'payment_terms',
            'discountPercent' => 'discount',
            'paymentDiscountPercent' => 'pymt_discount',
            'creditLimit' => 'credit_limit',
            'notes' => 'notes',
            'inactive' => 'inactive',
        ]);
        // FrontAccounting stores discounts as fractions; the API speaks percent (spec §4.3).
        $percent = new FunctionTransform(
            function ($value) {
                return $value === null ? null : round((float) $value * 100, 6);
            },
            function ($value) {
                return $value === null ? null : (float) $value / 100;
            }
        );
        $mapper->transformers = [
            'discount' => $percent,
            'pymt_discount' => $percent,
            'inactive' => new BooleanTransform(),
        ];
        parent::__construct($pdo, $mapper);
    }
}
```

(Check `anorm make`'s draft for `0_debtors_master` against this — `docker/fa-graphql exec vendor/bin/anorm.php make …` as Task 4 ran it — only to confirm the columns; the file above is the one to commit. `FunctionTransform::__construct(callable $databaseToModel, callable $modelToDatabase)`, `vendor/saygoweb/anorm/src/Transform/FunctionTransform.php:15`.)

`src/Type/Customer/BranchDefaultsInput.php`:

```php
<?php

namespace FA\GraphQL\Type\Customer;

use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\Type;

/**
 * customerCreate's `branch`: the default branch FrontAccounting's customer page
 * creates with a new customer while auto_create_branch is on (customers.php:112-119).
 * Named as the generated Branch Type names these references.
 */
final class BranchDefaultsInput extends InputObjectType
{
    public function __construct()
    {
        parent::__construct([
            'name' => 'BranchDefaultsInput',
            'description' => "The new customer's default branch, created with it while "
                . "FrontAccounting's auto_create_branch is on. Name, reference and address come from the customer; "
                . 'GL accounts from the company preferences.',
            'fields' => [
                'salesmanId' => ['type' => Type::nonNull(Type::id())],
                'salesAreaId' => ['type' => Type::nonNull(Type::id())],
                'taxGroupId' => ['type' => Type::nonNull(Type::id())],
                'locationId' => ['type' => Type::nonNull(Type::id())],
                'shipperId' => ['type' => Type::nonNull(Type::id())],
            ],
        ]);
    }
}
```

`src/Type/Customer/ContactDetailsInput.php`:

```php
<?php

namespace FA\GraphQL\Type\Customer;

use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\Type;

/**
 * customerCreate's `contact`: the CRM person the customer page creates with the
 * default branch (customers.php:121-127), named and addressed as the customer and
 * linked to the customer and the branch.
 */
final class ContactDetailsInput extends InputObjectType
{
    public function __construct()
    {
        parent::__construct([
            'name' => 'ContactDetailsInput',
            'description' => "Phone and email of the contact created with the customer's default branch.",
            'fields' => [
                'phone' => ['type' => Type::string()],
                'phone2' => ['type' => Type::string()],
                'fax' => ['type' => Type::string()],
                'email' => ['type' => Type::string()],
            ],
        ]);
    }
}
```

- [ ] **Step 5: Generate, then write the once-only files**

Run on the host: `bin/generate --dry-run`, then `bin/generate`.
Expected (among `current`/`kept` lines for the entities already there): `written` for `src/Type/Customer/Base/CustomerTypeBase.php`, `Base/CustomerCreateInputBase.php`, `Base/CustomerUpdateInputBase.php`, `CustomerType.php`, `CustomerCreateInput.php`, `CustomerUpdateInput.php`, `tests/Generated/CustomerTypeTest.php`, and `updated src/ApiSchema.php` with `customerList`, `customerCreate`, `customerUpdate` and `customerDelete` entries led by `// anorm-graphql`. `CustomerTypeBase` extends `\FA\GraphQL\Type\FaModelType`. No `skipped` line for `CustomerModel`. Do not edit any `Base/` file; if one is wrong, it is a Task 1–2 defect — stop and report.

Check the generated `CustomerCreateInputBase`: `name`, `ref`, `salesTypeId`, `creditStatusId`, `paymentTermsId` are non-null (`@required`), `id` is absent. `CustomerUpdateInputBase`: `id: ID!`, the rest nullable.

Replace `src/Type/Customer/CustomerType.php` with:

```php
<?php

namespace FA\GraphQL\Type\Customer;

use DI\Container;
use FA\GraphQL\Fa\Service\CustomerService;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Type\Customer\Base\CustomerTypeBase;

/**
 * Yours to edit: anorm-graphql wrote this file once and never again.
 *
 * Customers are written through FrontAccounting (spec §2): the generated create,
 * update and delete are routed to CustomerService, each batch in one ServiceCall::each —
 * one FrontAccounting transaction — and the rows read back once it has committed.
 * FaModelType refuses any write this class does not route.
 */
class CustomerType extends CustomerTypeBase
{
    /**
     * @return array<string, string>
     */
    protected function areas(): array
    {
        return [
            self::VERB_LIST => 'SA_CUSTOMER',
            self::VERB_CREATE => 'SA_CUSTOMER',
            self::VERB_EDIT => 'SA_CUSTOMER',
            self::VERB_DELETE => 'SA_CUSTOMER',
        ];
    }

    public function resolveCreate($root, $args, Container $context): array
    {
        $this->authorize(self::VERB_CREATE, null, $context);
        $customers = $context->get(CustomerService::class);
        $ids = ServiceCall::each($args['input'], function (array $input) use ($customers): int {
            return $customers->create($input);
        });

        return $this->rowsById($context, $ids);
    }

    public function resolveUpdate($root, $args, Container $context): array
    {
        $this->authorize(self::VERB_EDIT, null, $context);
        $customers = $context->get(CustomerService::class);
        $ids = ServiceCall::each($args['input'], function (array $input) use ($customers): int {
            $input['id'] = self::intId($input['id'] ?? null);
            $customers->update($input);

            return $input['id'];
        });

        return $this->rowsById($context, $ids);
    }

    public function resolveDelete($root, $args, Container $context): array
    {
        $this->authorize(self::VERB_DELETE, null, $context);
        $ids = self::intIds($args['id']);
        $rows = $this->rowsById($context, $ids);
        $customers = $context->get(CustomerService::class);
        ServiceCall::each($ids, function (int $id) use ($customers): void {
            $customers->delete($id);
        });

        return $rows;
    }
}
```

Replace `src/Type/Customer/CustomerCreateInput.php` with:

```php
<?php

namespace FA\GraphQL\Type\Customer;

use Anorm\GraphQL\Builder\FieldBuilder;
use FA\GraphQL\Type\Customer\Base\CustomerCreateInputBase;

/**
 * Yours to edit: anorm-graphql wrote this file once and never again.
 *
 * Adds what the customer page creates with a new customer while auto_create_branch
 * is on: the default branch's references and the contact's details (spec §4.3).
 */
class CustomerCreateInput extends CustomerCreateInputBase
{
    private BranchDefaultsInput $branch;

    private ContactDetailsInput $contact;

    public function __construct(BranchDefaultsInput $branch, ContactDetailsInput $contact)
    {
        // Set before the parent constructor: it calls fields().
        $this->branch = $branch;
        $this->contact = $contact;
        parent::__construct();
    }

    protected function fields(): array
    {
        return array_merge(parent::fields(), [
            FieldBuilder::create('branch', $this->branch)
                ->setDescription('Required while auto_create_branch is on; refused while it is off.')
                ->build(),
            FieldBuilder::create('contact', $this->contact)
                ->setDescription('Phone and email of the contact created with the default branch.')
                ->build(),
        ]);
    }
}
```

`src/Type/Customer/CustomerUpdateInput.php` stays as generated.

Replace `tests/Generated/CustomerTypeTest.php` with the following. Keep `expectedFieldTypes()` exactly as the generator wrote it if it differs from the list below only in order; a differing *type* is a model or generator defect — stop and report.

```php
<?php

namespace FA\GraphQL\Tests\Generated;

use FA\GraphQL\Type\Customer\CustomerCreateInput;
use FA\GraphQL\Type\Customer\CustomerType;
use FA\GraphQL\Tests\Support\FaTestRows;
use GraphQL\GraphQL;

/**
 * Yours to edit: anorm-graphql wrote this file once and never again.
 * The structural tests are inherited from Anorm\GraphQL\Testing\ModelTypeTestCase;
 * the lifecycle is this file's own, because a customer is written through
 * FrontAccounting: debtor_ref is unique (two identical creates are refused), and a
 * customer with its default branch cannot be deleted (customers.php:168-173).
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class CustomerTypeTest extends TestCase
{
    private ?string $prefix = null;

    protected function typeClass(): string
    {
        return CustomerType::class;
    }

    protected function inputClass(): ?string
    {
        return CustomerCreateInput::class;
    }

    protected function entityName(): string
    {
        return 'customer';
    }

    protected function keyField(): string
    {
        return 'id';
    }

    protected function testRowPrefix(): ?string
    {
        return $this->prefix ?? ($this->prefix = FaTestRows::prefix());
    }

    protected function expectedFieldTypes(): array
    {
        return [
            'id' => 'ID!',
            'name' => 'String',
            'ref' => 'String',
            'address' => 'String',
            'taxId' => 'String',
            'currencyId' => 'ID',
            'salesTypeId' => 'ID',
            'creditStatusId' => 'ID',
            'paymentTermsId' => 'ID',
            'discountPercent' => 'Float',
            'paymentDiscountPercent' => 'Float',
            'creditLimit' => 'Float',
            'notes' => 'String',
            'inactive' => 'Boolean',
        ];
    }

    protected function sampleInput(): array
    {
        return [
            'name' => 'GraphQL Test Customer',
            'ref' => $this->testRowPrefix() . 'a',
            'salesTypeId' => '1',
            'creditStatusId' => '1',
            'paymentTermsId' => '3',
            'discountPercent' => 10.0,
        ];
    }

    protected function sampleUpdate(): array
    {
        return ['name' => 'Renamed Customer'];
    }

    private const FIELDS = 'id name ref salesTypeId creditStatusId paymentTermsId discountPercent currencyId creditLimit';

    private const BRANCH = [
        'salesmanId' => '1', 'salesAreaId' => '1', 'taxGroupId' => '1', 'locationId' => 'DEF', 'shipperId' => '1',
    ];

    /** @return array<string, mixed> the first error */
    private function errorOf(string $query, array $variables): array
    {
        $result = GraphQL::executeQuery(
            $this->createSchema($this->container),
            $query,
            null,
            $this->container,
            $variables
        )->toArray();
        $this->assertArrayHasKey('errors', $result, json_encode($result));

        return $result['errors'][0];
    }

    private function create(array $inputs): array
    {
        return $this->execute(
            'mutation ($input: [CustomerCreateInput!]!) { customerCreate(input: $input) { ' . self::FIELDS . ' } }',
            ['input' => $inputs]
        )['customerCreate'];
    }

    public function testLifecycle(): void
    {
        $prefix = $this->testRowPrefix();
        $before = count($this->listAll());

        $created = $this->create([
            $this->sampleInput() + ['branch' => self::BRANCH],
            array_merge($this->sampleInput(), ['ref' => $prefix . 'b', 'branch' => self::BRANCH]),
        ]);
        $this->assertCount(2, $created);
        foreach ($this->sampleInput() as $name => $value) {
            $this->assertEquals($value, $created[0][$name], "created $name");
        }
        $this->assertSame('USD', $created[0]['currencyId'], 'the company currency by default');
        $this->assertEquals(1000, $created[0]['creditLimit'], 'the company default credit limit');
        $this->assertCount($before + 2, $this->listAll());

        $id = $created[0]['id'];
        $this->assertCount(1, $this->listWhere(['id' => (int) $id]));

        $updated = $this->execute(
            'mutation ($input: [CustomerUpdateInput!]!) { customerUpdate(input: $input) { ' . self::FIELDS . ' } }',
            ['input' => [['id' => $id] + $this->sampleUpdate()]]
        )['customerUpdate'];
        $this->assertSame('Renamed Customer', $updated[0]['name']);
        $this->assertSame($prefix . 'a', $updated[0]['ref'], 'an update keeps what it does not name');

        $error = $this->errorOf(
            'mutation ($id: [ID!]!) { customerDelete(id: $id) { id } }',
            ['id' => [$id]]
        );
        $this->assertSame('FA_REJECTED', $error['extensions']['code']);
        $this->assertSame('Cannot delete this customer because there are branch records set up against it.', $error['message']);

        // A customer created while auto_create_branch is off has no branch and can go.
        $GLOBALS['SysPrefs']->auto_create_branch = 0;
        $lone = $this->create([array_merge($this->sampleInput(), ['ref' => $prefix . 'c'])])[0];
        $deleted = $this->execute(
            'mutation ($id: [ID!]!) { customerDelete(id: $id) { ' . self::FIELDS . ' } }',
            ['id' => [$lone['id']]]
        )['customerDelete'];
        $this->assertSame($lone['id'], $deleted[0]['id'], 'delete returns the row as it was');
        $this->assertCount(0, $this->listWhere(['id' => (int) $lone['id']]));
    }

    public function testABatchRefusalNamesTheItemAndWritesNothing(): void
    {
        $error = $this->errorOf(
            'mutation ($input: [CustomerCreateInput!]!) { customerCreate(input: $input) { id } }',
            ['input' => [
                $this->sampleInput() + ['branch' => self::BRANCH],
                array_merge($this->sampleInput(), ['ref' => $this->testRowPrefix() . 'b', 'name' => '', 'branch' => self::BRANCH]),
            ]]
        );

        $this->assertSame('BAD_INPUT', $error['extensions']['code']);
        $this->assertSame('name', $error['extensions']['field']);
        $this->assertSame(1, $error['extensions']['index']);
        $this->assertCount(0, $this->listWhere(['ref' => $this->testRowPrefix() . 'a']), 'item 0 was rolled back');
    }

    public function testAnIdThatIsNotANumberIsBadInput(): void
    {
        $error = $this->errorOf('mutation { customerDelete(id: ["1 OR 1=1"]) { id } }', []);

        $this->assertSame('BAD_INPUT', $error['extensions']['code']);
    }

    public function testAMissingCustomerIsNotFound(): void
    {
        $error = $this->errorOf('mutation { customerDelete(id: ["999999"]) { id } }', []);

        $this->assertSame('NOT_FOUND', $error['extensions']['code']);
    }
}
```

Modify `tests/Generated/TestCase.php` — in `createContainer()`, after `$gate->enter(...)` and before `return $container;`:

```php
        // FrontAccounting writes on its own mysqli connection and commits there; the
        // lifecycle reads on this PDO inside the transaction ModelTypeTestCase opens.
        // Under MySQL's default REPEATABLE READ that transaction's snapshot would not
        // see rows FrontAccounting committed after its first read.
        $container->get(\PDO::class)->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
```

and add, with `use FA\GraphQL\Tests\Support\FaTestRows;`:

```php
    /**
     * A reference prefix for rows a test writes through FrontAccounting, which no PDO
     * rollback can undo; tearDown sweeps them (FaTestRows::sweep). Null: nothing to sweep.
     */
    protected function testRowPrefix(): ?string
    {
        return null;
    }

    protected function tearDown(): void
    {
        $prefix = $this->testRowPrefix();
        parent::tearDown();
        if ($prefix !== null) {
            FaTestRows::sweep(FaTestRows::connect(), $prefix);
        }
    }
```

`tests/Unit/ApiSchemaTest.php` — in `testTheRootFieldsAreExactlyTheSpecsAndNothingElse`, add `customerList` to the Query fields and `customerCreate`, `customerDelete`, `customerUpdate` to the Mutation fields, in the order the generator placed them in `src/ApiSchema.php` (its entries are alphabetical by field name, among the hand-written ones). After Task 4 the Mutation list is:

```php
            ['customerCreate', 'customerDelete', 'customerUpdate', 'login', 'tokenRefresh', 'tokenRevoke'],
```

- [ ] **Step 6: Run to see them pass**

Run: `docker/fa-graphql test --testsuite unit --filter 'FaModelTypeHelpersTest|ApiSchemaTest'`
Expected: PASS.

Run: `docker/fa-graphql test --testsuite integration --filter 'CustomerServiceTest|CustomerTypeTest'`
Expected: PASS. Then `docker/fa-graphql exec mysql …` is not needed: check that nothing leaked —
`docker/fa-graphql db shell` → `SELECT COUNT(*) FROM 0_debtors_master WHERE debtor_ref LIKE 'gqlt%'; SELECT COUNT(*) FROM 0_crm_persons WHERE ref LIKE 'gqlt%';`
Expected: `0` and `0`.

Run: `docker/fa-graphql test`
Expected: PASS, every suite.

- [ ] **Step 7: Gates and commit**

```bash
docker/fa-graphql lint && docker/fa-graphql analyze
git add src/Fa/Bootstrap.php src/Type/FaModelType.php src/Fa/Service src/Model/CustomerModel.php \
  src/Type/Customer src/ApiSchema.php tests/Support tests/Unit/Type/FaModelTypeHelpersTest.php \
  tests/Integration/Service/CustomerServiceTest.php tests/Generated tests/Unit/ApiSchemaTest.php
git commit -m "Create, update and delete customers through FrontAccounting

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

If PHPStan reports FrontAccounting functions it cannot find (`add_customer`, `add_crm_person`, `key_in_foreign_table`, …), add the files to `phpstan.neon`'s `scanFiles` the way Task 3 added FrontAccounting files (`../../sales/includes/db/customers_db.inc`, `../../sales/includes/db/branches_db.inc`, `../../includes/db/crm_contacts_db.inc`, `../../admin/db/company_db.inc`, `../../includes/banking.inc`, `../../includes/db/sql_functions.inc`) and include `phpstan.neon` in the commit.

---

### Task 6: Branches and contacts

Branches (`cust_branch`) and contacts (CRM persons, `crm_persons`, linked to customers and branches through `crm_contacts`) are created, updated and deleted through FrontAccounting's own functions, as `sales/manage/customer_branches.php` and the CRM contact editor (`includes/ui/contacts_view.inc`) write them. The schema is generated from `BranchModel` and `ContactModel`; `ContactType` gets a computed `links` field and the contact Inputs a `links` field; `CustomerType` gets its computed `branches` and `contacts`. Spec §2.1, §3, §4.1, §4.3, §5.

Line numbers are upstream `master`; the fork's copies of these files do not differ.

**Files:**
- Create: `src/Fa/Service/BranchService.php`, `src/Fa/Service/ContactService.php`, `src/Model/BranchModel.php`, `src/Model/ContactModel.php`, `src/Type/Branch/BranchContactInput.php`, `src/Type/Contact/ContactEntityType.php`, `src/Type/Contact/ContactCategoryType.php`, `src/Type/Contact/ContactLinkType.php`, `src/Type/Contact/ContactLinkInput.php`, `src/Type/Contact/ContactLinks.php`
- Generated by `bin/generate`, then the once-only ones replaced as shown: `src/Type/Branch/Base/*`, `src/Type/Contact/Base/*` (never edited), `src/Type/Branch/BranchType.php`, `src/Type/Branch/BranchCreateInput.php`, `src/Type/Branch/BranchUpdateInput.php` (kept as generated), `src/Type/Contact/ContactType.php`, `src/Type/Contact/ContactCreateInput.php`, `src/Type/Contact/ContactUpdateInput.php`, `tests/Generated/BranchTypeTest.php`, `tests/Generated/ContactTypeTest.php`, `src/ApiSchema.php` entries
- Modify: `src/Type/Customer/CustomerType.php` (computed `branches`, `contacts`), `tests/Unit/ApiSchemaTest.php` (root fields)
- Test: `tests/Integration/Service/BranchServiceTest.php`, `tests/Integration/Service/ContactServiceTest.php`, `tests/Generated/BranchTypeTest.php`, `tests/Generated/ContactTypeTest.php`

**Interfaces:**
- Consumes: Task 3 — `ServiceCall::run/each`, `BadInput` (`field()`, `index()`, `withIndex()`), `NotFound`, `FaRejected`, `FaModelType` (fail-closed writes). Task 5 — `FaIncludes::customers()`, `ReferenceCheck::exists/requireAll`, `BranchReferences::REFS/validated`, `FaModelType::intId/intIds/rowsById`, `CustomerService::create`, `CustomerType`, `Tests\Support\FaTestRows`, `tests/Generated/TestCase::testRowPrefix()` (READ COMMITTED + sweep).
- Produces (Tasks 7–10 rely on these):
  - `Fa\Service\BranchService`: `create(array $input): int`, `update(array $input): void`, `delete(int $id): void`.
  - `Fa\Service\ContactService`: `create(array $input): int`, `update(array $input): void`, `delete(int $id): void`.
  - `Model\BranchModel` — key `id` (`branch_code`); `customerId` (`debtor_no`), `name`, `ref`, `address`, `postAddress`, `salesmanId`, `salesAreaId`, `taxGroupId`, `locationId`, `shipperId`, `notes`, `bankAccount`, `inactive`.
  - `Model\ContactModel` — key `id` (`crm_persons.id`); `ref`, `name`, `name2`, `address`, `phone`, `phone2`, `fax`, `email`, `lang`, `notes`, `inactive`.
  - GraphQL: `branchList/Create/Update/Delete`, `contactList/Create/Update/Delete`; enums `ContactEntity` (`CUSTOMER`, `BRANCH`) and `ContactCategory` (`GENERAL`, `ORDER`, `DELIVERY`, `INVOICE`); `ContactLink { entity, id, category }`; input `ContactLinkInput`; `ContactCreateInput.links: [ContactLinkInput!]!`, `ContactUpdateInput.links: [ContactLinkInput!]`; `BranchCreateInput.contact: BranchContactInput`; `CustomerType.branches: [BranchType!]!`, `CustomerType.contacts: [ContactType!]!`, `ContactType.links: [ContactLink!]!`.
  - `Type\Contact\ContactLinks::personIds(\PDO $pdo, string $type, int $entityId): array`, `ContactLinks::forPerson(\PDO $pdo, int $personId): array`.
- **Deliberate differences from the spec's hand design** (generation wins, spec §1): the branch's references are the generated names `salesmanId`, `salesAreaId`, `taxGroupId`, `locationId`, `shipperId` (the hand design's `locationCode` included); `ContactLinkInput.id` is an `ID`.
- **Dates:** none in this task.

**Facts this task rests on (read, not assumed):**
- `add_branch($customer_id, $br_name, $br_ref, $br_address, $salesman, $area, $tax_group_id, $sales_account, $sales_discount_account, $receivables_account, $payment_discount_account, $default_location, $br_post_address, $group_no, $default_ship_via, $notes, $bank_account)` — `sales/includes/db/branches_db.inc:12-37`, every value `db_escape()`d (`bank_account` with `nullify`). `update_branch($customer_id, $branch_code, …same…)` — `:39-63`, `WHERE branch_code … AND debtor_no …`. `delete_branch($customer_id, $branch_code)` — `:65-71`: `delete_entity_contacts('cust_branch', $branch_code)` (persons left with no links go too, `includes/db/crm_contacts_db.inc:176-187`), then the row; no transaction of its own. `branch_in_foreign_table($customer_id, $branch_code, $table)` — `:73-80`.
- The branch page — `customer_branches.php`: required references exist (`:29-37`); `br_name`, `br_ref` non-empty (`:64-76`, messages below); add and update inside `begin_transaction()` (`:81-110`); **every new branch gets a CRM person** — `add_crm_person($contact_name, $contact_name, '', $br_post_address, phone, phone2, fax, email, rep_lang, '')` and `add_crm_contact('cust_branch', 'general', $branch, $person)` (`:101-105`); new-branch defaults (`:203-223`): name, ref and both addresses from the customer and `contact_name` = `_('Main Branch')` for a customer's first branch, `sales_account` = `''`, the other three GL accounts from the company preferences; delete guards `debtor_trans` then `sales_orders` (`:122-138`). `inactive` is set by the list's inactive control (`:302`), i.e. `update_record_status($id, $status, 'cust_branch', 'branch_code')`.
- CRM — `includes/db/crm_contacts_db.inc`: `add_crm_person($ref, $name, $name2, $address, $phone, $phone2, $fax, $email, $lang, $notes, $cat_ids = null, $entity = null)` returns the id (`:13-41`); with `$cat_ids` it links one entity only and returns `null` without committing if that fails — so the service links with `add_crm_contact()` instead. `update_crm_person($id, …, $cat_ids, $entity = null, $type = null)` (`:43-68`) always calls `update_person_contacts($id, $cat_ids, $entity, $type)` (`:150-174`), which **deletes the person's links** (`WHERE person_id = …`, narrowed to `type = $type` when given) and re-inserts `$cat_ids` for a single entity. Passing `$cat_ids = []` and a `$type` no category has keeps every link: the delete matches nothing and nothing is inserted. `delete_crm_person($person, $with_contacts)` (`:70-85`). `add_crm_contact($type, $action, $entity_id, $person_id)` (`:251-261`). `delete_crm_contacts($person_id, $type, $entity_id, $action)` (`:272-289`). `get_crm_person($id)` (`:121-134`).
- The contact editor's checks — `contacts_view.inc:126-142`: name non-empty, reference non-empty, at least one category, in that order.
- `crm_categories` (`sql/en_US-new.sql:369-381`): `customer` and `cust_branch` each have `general`, `order`, `delivery`, `invoice`; `UNIQUE (type, action)`; an `inactive` flag. `crm_contacts.entity_id` is a `varchar(11)`.
- `crm_persons.ref` is not unique; `cust_branch.branch_ref` is not unique.

- [ ] **Step 1: Write the failing tests**

`tests/Integration/Service/BranchServiceTest.php`:

```php
<?php

namespace FA\GraphQL\Tests\Integration\Service;

use FA\GraphQL\Auth\Claims;
use FA\GraphQL\Config;
use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\FaRejected;
use FA\GraphQL\Error\NotFound;
use FA\GraphQL\Fa\FaSession;
use FA\GraphQL\Fa\Service\BranchService;
use FA\GraphQL\Fa\Service\CustomerService;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Tests\Integration\FaTestCase;
use FA\GraphQL\Tests\Support\FaTestRows;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class BranchServiceTest extends FaTestCase
{
    private string $prefix;

    private int $customerId;

    protected function setUp(): void
    {
        parent::setUp();
        (new FaSession(Config::fromArray(['secret' => str_repeat('k', 32)])))
            ->enter(new Claims(0, 'apitest', 'jti', new \DateTimeImmutable('+5 minutes')));
        $this->prefix = FaTestRows::prefix();
        // A customer with no branch yet: auto_create_branch off for its creation.
        $GLOBALS['SysPrefs']->auto_create_branch = 0;
        $this->customerId = ServiceCall::run(function (): int {
            return (new CustomerService())->create([
                'name' => 'Branch Test Customer', 'ref' => $this->prefix . 'c', 'address' => '9 Customer Road',
                'salesTypeId' => '1', 'paymentTermsId' => '3', 'creditStatusId' => '1',
            ]);
        });
        $GLOBALS['SysPrefs']->auto_create_branch = 1;
    }

    protected function tearDown(): void
    {
        FaTestRows::sweep($this->pdo(), $this->prefix);
        parent::tearDown();
    }

    private function input(array $overrides = []): array
    {
        return array_merge([
            'customerId' => (string) $this->customerId,
            'name' => 'Head Office',
            'ref' => $this->prefix . 'b',
            'address' => '1 Branch Street',
            'salesmanId' => '1', 'salesAreaId' => '1', 'taxGroupId' => '1', 'locationId' => 'DEF', 'shipperId' => '1',
        ], $overrides);
    }

    private function create(array $input): int
    {
        return ServiceCall::run(function () use ($input): int {
            return (new BranchService())->create($input);
        });
    }

    private function update(array $input): void
    {
        ServiceCall::run(function () use ($input): void {
            (new BranchService())->update($input);
        });
    }

    private function delete(int $id): void
    {
        ServiceCall::run(function () use ($id): void {
            (new BranchService())->delete($id);
        });
    }

    private function one(string $sql, array $params): ?array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function branch(int $id): ?array
    {
        return $this->one('SELECT * FROM 0_cust_branch WHERE branch_code = ?', [$id]);
    }

    /** The CRM person linked to a branch as its general contact. */
    private function branchPerson(int $id): ?array
    {
        return $this->one(
            "SELECT p.* FROM 0_crm_persons p JOIN 0_crm_contacts c ON c.person_id = p.id "
            . "WHERE c.type = 'cust_branch' AND c.action = 'general' AND c.entity_id = ?",
            [$id]
        );
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

    public function testABranchIsWrittenAsThePageWritesIt(): void
    {
        $id = $this->create($this->input(['notes' => 'deliver to the back door']));

        $branch = $this->branch($id);
        $this->assertSame((string) $this->customerId, (string) $branch['debtor_no']);
        $this->assertSame('Head Office', $branch['br_name']);
        $this->assertSame($this->prefix . 'b', $branch['branch_ref']);
        $this->assertSame('1 Branch Street', $branch['br_address']);
        $this->assertSame('1 Branch Street', $branch['br_post_address'], 'the postal address defaults to the address');
        $this->assertSame('DEF', $branch['default_location']);
        $this->assertSame('deliver to the back door', $branch['notes']);
        $this->assertSame('', $branch['sales_account']);
        $this->assertSame((string) get_company_pref('default_sales_discount_act'), $branch['sales_discount_account']);
        $this->assertSame((string) get_company_pref('debtors_act'), $branch['receivables_account']);
        $this->assertSame((string) get_company_pref('default_prompt_payment_act'), $branch['payment_discount_account']);
        $this->assertSame('0', (string) $branch['group_no']);
    }

    public function testACustomersFirstBranchGetsAMainBranchContactAndLaterOnesTheBranchName(): void
    {
        $first = $this->create($this->input());
        $second = $this->create($this->input(['name' => 'Warehouse', 'ref' => $this->prefix . 'w']));

        $this->assertSame('Main Branch', $this->branchPerson($first)['name']);
        $this->assertSame('Warehouse', $this->branchPerson($second)['name']);
        $this->assertSame('1 Branch Street', $this->branchPerson($first)['address']);
    }

    public function testAGivenContactIsTheBranchesPerson(): void
    {
        $id = $this->create($this->input([
            'contact' => ['name' => $this->prefix . 'p', 'phone' => '555-0199', 'email' => 'branch@example.com'],
        ]));

        $person = $this->branchPerson($id);
        $this->assertSame($this->prefix . 'p', $person['name']);
        $this->assertSame($this->prefix . 'p', $person['ref'], 'the page uses the contact name as its reference');
        $this->assertSame('555-0199', $person['phone']);
        $this->assertSame('branch@example.com', $person['email']);
    }

    /**
     * @dataProvider refusals
     */
    public function testRefused(array $overrides, string $field, string $message): void
    {
        $this->assertBadInput($field, $message, function () use ($overrides): void {
            $this->create($this->input($overrides));
        });
        $this->assertNull($this->one('SELECT * FROM 0_cust_branch WHERE debtor_no = ?', [$this->customerId]));
    }

    public function refusals(): array
    {
        return [
            // customer_branches.php:64-76
            'empty name' => [['name' => ''], 'name', 'The Branch name cannot be empty.'],
            'empty short name' => [['ref' => ''], 'ref', 'The Branch short name cannot be empty.'],
            'unknown customer' => [['customerId' => '999999'], 'customerId', "There is no customer '999999'."],
            'unknown salesperson' => [['salesmanId' => '999'], 'salesmanId', "There is no salesperson '999'."],
            'unknown area' => [['salesAreaId' => '999'], 'salesAreaId', "There is no sales area '999'."],
            'unknown tax group' => [['taxGroupId' => '999'], 'taxGroupId', "There is no tax group '999'."],
            'unknown location' => [['locationId' => 'NOPE'], 'locationId', "There is no location 'NOPE'."],
            'unknown shipper' => [['shipperId' => '999'], 'shipperId', "There is no shipper '999'."],
            'null address' => [['address' => null], 'address', 'address cannot be null.'],
        ];
    }

    public function testAnUpdateKeepsWhatItDoesNotNameAndTheGlAccounts(): void
    {
        $id = $this->create($this->input());
        $before = $this->branch($id);

        $this->update(['id' => $id, 'name' => 'Renamed Office', 'taxGroupId' => '2', 'inactive' => true]);

        $after = $this->branch($id);
        $this->assertSame('Renamed Office', $after['br_name']);
        $this->assertSame('2', (string) $after['tax_group_id']);
        $this->assertSame('1', (string) $after['inactive']);
        $this->assertSame($before['br_address'], $after['br_address']);
        $this->assertSame($before['receivables_account'], $after['receivables_account']);
        $this->assertSame($before['group_no'], $after['group_no']);
    }

    public function testABranchCannotMoveToAnotherCustomer(): void
    {
        $id = $this->create($this->input());

        $this->assertBadInput('customerId', 'A branch cannot move to another customer.', function () use ($id): void {
            $this->update(['id' => $id, 'customerId' => '1']);
        });
    }

    public function testAnUpdateOfAMissingBranchIsNotFound(): void
    {
        $this->expectException(NotFound::class);
        $this->update(['id' => 999999, 'name' => 'Nowhere']);
    }

    public function testDeleteRemovesTheBranchAndItsContact(): void
    {
        $id = $this->create($this->input());
        $person = $this->branchPerson($id);

        $this->delete($id);

        $this->assertNull($this->branch($id));
        $this->assertNull($this->one('SELECT id FROM 0_crm_persons WHERE id = ?', [$person['id']]));
    }

    public function testABranchWithTransactionsCannotBeDeleted(): void
    {
        $booked = $this->one(
            'SELECT b.branch_code FROM 0_cust_branch b WHERE EXISTS (SELECT 1 FROM 0_debtor_trans t '
            . 'WHERE t.branch_code = b.branch_code AND t.debtor_no = b.debtor_no) ORDER BY b.branch_code LIMIT 1',
            []
        );
        if ($booked === null) {
            $this->markTestSkipped('The dataset has no branch with transactions.');
        }

        try {
            $this->delete((int) $booked['branch_code']);
            $this->fail('a branch with transactions was deleted');
        } catch (FaRejected $e) {
            $this->assertSame(
                'Cannot delete this branch because customer transactions have been created to this branch.',
                $e->getMessage()
            );
        }
        $this->assertNotNull($this->branch((int) $booked['branch_code']));
    }

    public function testDeleteOfAMissingBranchIsNotFound(): void
    {
        $this->expectException(NotFound::class);
        $this->delete(999999);
    }
}
```

`tests/Integration/Service/ContactServiceTest.php`:

```php
<?php

namespace FA\GraphQL\Tests\Integration\Service;

use FA\GraphQL\Auth\Claims;
use FA\GraphQL\Config;
use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\NotFound;
use FA\GraphQL\Fa\FaSession;
use FA\GraphQL\Fa\Service\ContactService;
use FA\GraphQL\Fa\Service\CustomerService;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Tests\Integration\FaTestCase;
use FA\GraphQL\Tests\Support\FaTestRows;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ContactServiceTest extends FaTestCase
{
    private string $prefix;

    private int $customerId;

    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        (new FaSession(Config::fromArray(['secret' => str_repeat('k', 32)])))
            ->enter(new Claims(0, 'apitest', 'jti', new \DateTimeImmutable('+5 minutes')));
        $this->prefix = FaTestRows::prefix();
        $this->customerId = ServiceCall::run(function (): int {
            return (new CustomerService())->create([
                'name' => 'Contact Test Customer', 'ref' => $this->prefix . 'c',
                'salesTypeId' => '1', 'paymentTermsId' => '3', 'creditStatusId' => '1',
                'branch' => [
                    'salesmanId' => '1', 'salesAreaId' => '1', 'taxGroupId' => '1',
                    'locationId' => 'DEF', 'shipperId' => '1',
                ],
            ]);
        });
        $this->branchId = (int) $this->one(
            'SELECT branch_code FROM 0_cust_branch WHERE debtor_no = ?',
            [$this->customerId]
        )['branch_code'];
    }

    protected function tearDown(): void
    {
        FaTestRows::sweep($this->pdo(), $this->prefix);
        parent::tearDown();
    }

    private function input(array $overrides = []): array
    {
        return array_merge([
            'ref' => $this->prefix . 'p',
            'name' => 'Pat Accounts',
            'email' => 'pat@example.com',
            'links' => [
                ['entity' => 'customer', 'id' => (string) $this->customerId, 'category' => 'invoice'],
                ['entity' => 'cust_branch', 'id' => (string) $this->branchId, 'category' => 'delivery'],
            ],
        ], $overrides);
    }

    private function create(array $input): int
    {
        return ServiceCall::run(function () use ($input): int {
            return (new ContactService())->create($input);
        });
    }

    private function update(array $input): void
    {
        ServiceCall::run(function () use ($input): void {
            (new ContactService())->update($input);
        });
    }

    private function delete(int $id): void
    {
        ServiceCall::run(function () use ($id): void {
            (new ContactService())->delete($id);
        });
    }

    private function one(string $sql, array $params): ?array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return array<int, array<string, string>> */
    private function links(int $personId): array
    {
        $statement = $this->pdo()->prepare(
            'SELECT type, action, entity_id FROM 0_crm_contacts WHERE person_id = ? ORDER BY type, action'
        );
        $statement->execute([$personId]);

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
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

    public function testAContactIsAPersonWithItsLinks(): void
    {
        $id = $this->create($this->input());

        $person = $this->one('SELECT * FROM 0_crm_persons WHERE id = ?', [$id]);
        $this->assertSame('Pat Accounts', $person['name']);
        $this->assertSame($this->prefix . 'p', $person['ref']);
        $this->assertSame('pat@example.com', $person['email']);
        $this->assertSame([
            ['type' => 'cust_branch', 'action' => 'delivery', 'entity_id' => (string) $this->branchId],
            ['type' => 'customer', 'action' => 'invoice', 'entity_id' => (string) $this->customerId],
        ], $this->links($id));
    }

    /**
     * contacts_view.inc:126-142, then what the editor offers from lists.
     *
     * @dataProvider refusals
     */
    public function testRefused(array $overrides, string $field, string $message): void
    {
        $this->assertBadInput($field, $message, function () use ($overrides): void {
            $this->create($this->input($overrides));
        });
        $this->assertNull($this->one('SELECT id FROM 0_crm_persons WHERE ref = ?', [$this->prefix . 'p']));
    }

    public function refusals(): array
    {
        return [
            'empty name' => [['name' => ''], 'name', 'The contact name cannot be empty.'],
            'empty reference' => [['ref' => ''], 'ref', 'Contact reference cannot be empty.'],
            'no links' => [['links' => []], 'links', 'You have to select at least one category.'],
            'unknown customer' => [
                ['links' => [['entity' => 'customer', 'id' => '999999', 'category' => 'general']]],
                'links.0.id', "There is no customer '999999'.",
            ],
            'unknown category' => [
                ['links' => [['entity' => 'customer', 'id' => '1', 'category' => 'department']]],
                'links.0.category', 'There is no contact category customer/department in use.',
            ],
        ];
    }

    public function testAnUpdateWithoutLinksKeepsThem(): void
    {
        $id = $this->create($this->input());
        $before = $this->links($id);

        $this->update(['id' => $id, 'name' => 'Pat Receivables', 'phone' => '555-0142', 'inactive' => true]);

        $person = $this->one('SELECT * FROM 0_crm_persons WHERE id = ?', [$id]);
        $this->assertSame('Pat Receivables', $person['name']);
        $this->assertSame('555-0142', $person['phone']);
        $this->assertSame('pat@example.com', $person['email']);
        $this->assertSame('1', (string) $person['inactive']);
        $this->assertSame($before, $this->links($id), 'update_crm_person must not have touched the links');
    }

    public function testAnUpdateWithLinksReplacesThem(): void
    {
        $id = $this->create($this->input());

        $this->update(['id' => $id, 'links' => [
            ['entity' => 'customer', 'id' => (string) $this->customerId, 'category' => 'order'],
        ]]);

        $this->assertSame(
            [['type' => 'customer', 'action' => 'order', 'entity_id' => (string) $this->customerId]],
            $this->links($id)
        );
    }

    public function testAnUpdateCannotLeaveAContactWithNoLinks(): void
    {
        $id = $this->create($this->input());

        $this->assertBadInput('links', 'You have to select at least one category.', function () use ($id): void {
            $this->update(['id' => $id, 'links' => []]);
        });
        $this->assertCount(2, $this->links($id));
    }

    public function testAnUpdateOfAMissingContactIsNotFound(): void
    {
        $this->expectException(NotFound::class);
        $this->update(['id' => 999999, 'name' => 'Nobody']);
    }

    public function testDeleteRemovesThePersonAndItsLinks(): void
    {
        $id = $this->create($this->input());

        $this->delete($id);

        $this->assertNull($this->one('SELECT id FROM 0_crm_persons WHERE id = ?', [$id]));
        $this->assertSame([], $this->links($id));
    }

    public function testDeleteOfAMissingContactIsNotFound(): void
    {
        $this->expectException(NotFound::class);
        $this->delete(999999);
    }
}
```

(The service is handed FrontAccounting's names — `customer`/`cust_branch`, `general`/…: the GraphQL enums `ContactEntity` and `ContactCategory` carry them as their internal values, so a resolver passes them through unchanged.)

- [ ] **Step 2: Run to see them fail**

Run: `docker/fa-graphql test --testsuite integration --filter 'BranchServiceTest|ContactServiceTest'`
Expected: FAIL — `Class "FA\GraphQL\Fa\Service\BranchService" not found`, `… ContactService" not found`.

- [ ] **Step 3: Implement the services**

`src/Fa/Service/BranchService.php`:

```php
<?php

namespace FA\GraphQL\Fa\Service;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\FaRejected;
use FA\GraphQL\Error\NotFound;

/**
 * Customer branches, written through FrontAccounting's own functions the way
 * sales/manage/customer_branches.php writes them (upstream master line numbers).
 * Run it inside ServiceCall: it opens no transaction of its own.
 */
final class BranchService
{
    private const FIELDS = [
        'name', 'ref', 'address', 'postAddress', 'salesmanId', 'salesAreaId', 'taxGroupId',
        'locationId', 'shipperId', 'notes', 'bankAccount',
    ];

    /** bank_account is the one nullable column; any other explicit null is refused. */
    private const NULLABLE = ['bankAccount'];

    /**
     * @param array<string, mixed> $input
     */
    public function create(array $input): int
    {
        FaIncludes::customers();
        $customerId = $input['customerId'] ?? null;
        if ($customerId === null || $customerId === '' || !ReferenceCheck::exists('debtors_master', 'debtor_no', $customerId)) {
            throw new BadInput("There is no customer '$customerId'.", 'customerId');
        }
        $base = [
            'name' => '', 'ref' => '', 'address' => '', 'postAddress' => null, 'notes' => '', 'bankAccount' => null,
            'salesmanId' => null, 'salesAreaId' => null, 'taxGroupId' => null, 'locationId' => null, 'shipperId' => null,
        ];
        $branch = self::validated($input, $base);
        // customer_branches.php:210: the page offers the address as the postal address.
        $branch['postAddress'] = $branch['postAddress'] ?? $branch['address'];
        $isFirst = !ReferenceCheck::exists('cust_branch', 'debtor_no', $customerId);

        // customer_branches.php:94-99, with the new-branch GL defaults of :213-223.
        add_branch(
            $customerId,
            $branch['name'],
            $branch['ref'],
            $branch['address'],
            $branch['salesmanId'],
            $branch['salesAreaId'],
            $branch['taxGroupId'],
            '',
            get_company_pref('default_sales_discount_act'),
            get_company_pref('debtors_act'),
            get_company_pref('default_prompt_payment_act'),
            $branch['locationId'],
            $branch['postAddress'],
            0,
            $branch['shipperId'],
            $branch['notes'],
            (string) $branch['bankAccount']
        );
        $id = (int) db_insert_id();

        // customer_branches.php:101-105: every new branch gets a CRM person, named
        // and referenced by the contact name. The page defaults that name to
        // "Main Branch" for a customer's first branch (:209) and leaves it blank
        // otherwise; a blank-named person is no use to anyone, so a later branch's
        // person takes the branch's name.
        $contact = $input['contact'] ?? [];
        $name = (string) ($contact['name'] ?? ($isFirst ? _('Main Branch') : $branch['name']));
        $personId = add_crm_person(
            $name,
            $name,
            '',
            $branch['postAddress'],
            (string) ($contact['phone'] ?? ''),
            (string) ($contact['phone2'] ?? ''),
            (string) ($contact['fax'] ?? ''),
            (string) ($contact['email'] ?? ''),
            (string) ($contact['lang'] ?? ''),
            ''
        );
        add_crm_contact('cust_branch', 'general', $id, $personId);

        return $id;
    }

    /**
     * @param array<string, mixed> $input with an int 'id'
     */
    public function update(array $input): void
    {
        FaIncludes::customers();
        $id = (int) $input['id'];
        $row = self::row($id);
        if ($row === null) {
            throw new NotFound("Branch id '$id' not found");
        }
        if (isset($input['customerId']) && (string) $input['customerId'] !== (string) $row['debtor_no']) {
            throw new BadInput('A branch cannot move to another customer.', 'customerId');
        }
        $branch = self::validated($input, [
            'name' => $row['br_name'],
            'ref' => $row['branch_ref'],
            'address' => $row['br_address'],
            'postAddress' => $row['br_post_address'],
            'salesmanId' => $row['salesman'],
            'salesAreaId' => $row['area'],
            'taxGroupId' => $row['tax_group_id'],
            'locationId' => $row['default_location'],
            'shipperId' => $row['default_ship_via'],
            'notes' => $row['notes'],
            'bankAccount' => $row['bank_account'],
        ]);

        // customer_branches.php:84-88. The GL accounts and the sales group are not
        // API fields (spec §1): kept as they are.
        update_branch(
            $row['debtor_no'],
            $id,
            $branch['name'],
            $branch['ref'],
            $branch['address'],
            $branch['salesmanId'],
            $branch['salesAreaId'],
            $branch['taxGroupId'],
            $row['sales_account'],
            $row['sales_discount_account'],
            $row['receivables_account'],
            $row['payment_discount_account'],
            $branch['locationId'],
            $branch['postAddress'],
            $row['group_no'],
            $branch['shipperId'],
            $branch['notes'],
            (string) $branch['bankAccount']
        );
        if (array_key_exists('inactive', $input) && $input['inactive'] !== null) {
            update_record_status($id, $input['inactive'] ? 1 : 0, 'cust_branch', 'branch_code');
        }
    }

    public function delete(int $id): void
    {
        FaIncludes::customers();
        $row = self::row($id);
        if ($row === null) {
            throw new NotFound("Branch id '$id' not found");
        }
        // customer_branches.php:122-138, in the page's order and with its messages.
        if (branch_in_foreign_table($row['debtor_no'], $id, 'debtor_trans')) {
            throw new FaRejected('Cannot delete this branch because customer transactions have been created to this branch.');
        }
        if (branch_in_foreign_table($row['debtor_no'], $id, 'sales_orders')) {
            throw new FaRejected('Cannot delete this branch because sales orders exist for it. Purge old sales orders first.');
        }
        delete_branch($row['debtor_no'], $id);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    private static function validated(array $input, array $base): array
    {
        $branch = $base;
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            if ($input[$field] === null && !in_array($field, self::NULLABLE, true)) {
                throw new BadInput("$field cannot be null.", $field);
            }
            $branch[$field] = $input[$field];
        }
        // customer_branches.php:64-76
        if (strlen((string) $branch['name']) === 0) {
            throw new BadInput('The Branch name cannot be empty.', 'name');
        }
        if (strlen((string) $branch['ref']) === 0) {
            throw new BadInput('The Branch short name cannot be empty.', 'ref');
        }
        BranchReferences::validated($branch);

        return $branch;
    }

    /**
     * The branch row by its key alone. get_branch() (branches_db.inc:82-93) inner-joins
     * the salesperson, so it would miss a branch whose salesperson is gone.
     *
     * @return array<string, mixed>|null
     */
    private static function row(int $id): ?array
    {
        $result = db_query(
            'SELECT * FROM ' . TB_PREF . 'cust_branch WHERE branch_code=' . db_escape($id),
            'could not read the branch'
        );
        $row = db_fetch($result);

        return $row ?: null;
    }
}
```

`src/Fa/Service/ContactService.php`:

```php
<?php

namespace FA\GraphQL\Fa\Service;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\NotFound;

/**
 * CRM persons and their links to customers and branches, written through
 * FrontAccounting's own functions (includes/db/crm_contacts_db.inc) with the CRM
 * contact editor's checks (includes/ui/contacts_view.inc:126-142). Run it inside
 * ServiceCall: it opens no transaction of its own.
 *
 * A link is [entity, id, category]: entity 'customer' or 'cust_branch' (crm_contacts.type),
 * category 'general', 'order', 'delivery' or 'invoice' (crm_contacts.action).
 */
final class ContactService
{
    private const FIELDS = ['ref', 'name', 'name2', 'address', 'phone', 'phone2', 'fax', 'email', 'lang', 'notes'];

    private const NULLABLE = ['name2', 'address', 'phone', 'phone2', 'fax', 'email', 'lang'];

    /**
     * No crm_categories type has this name: passed to update_crm_person() as $type,
     * it narrows the link delete of update_person_contacts() (crm_contacts_db.inc:150-174)
     * to nothing, and with no category ids nothing is inserted — the person's links
     * are kept. The service replaces links itself, for several entities at once, which
     * update_person_contacts() cannot (it links one entity).
     */
    private const KEEP_LINKS = 'graphql-keep-links';

    private const ENTITIES = [
        'customer' => ['debtors_master', 'debtor_no', 'customer'],
        'cust_branch' => ['cust_branch', 'branch_code', 'branch'],
    ];

    /**
     * @param array<string, mixed> $input
     */
    public function create(array $input): int
    {
        FaIncludes::customers();
        $person = self::validated($input, [
            'ref' => '', 'name' => '', 'name2' => null, 'address' => null, 'phone' => null, 'phone2' => null,
            'fax' => null, 'email' => null, 'lang' => null, 'notes' => '',
        ]);
        $links = self::validatedLinks($input['links'] ?? []);

        // Without $cat_ids: add_crm_person() links only one entity that way, and
        // returns without committing when linking fails (crm_contacts_db.inc:33-37).
        $id = (int) add_crm_person(
            $person['ref'],
            $person['name'],
            $person['name2'],
            $person['address'],
            $person['phone'],
            $person['phone2'],
            $person['fax'],
            $person['email'],
            $person['lang'],
            $person['notes']
        );
        foreach ($links as [$type, $action, $entityId]) {
            add_crm_contact($type, $action, $entityId, $id);
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $input with an int 'id'
     */
    public function update(array $input): void
    {
        FaIncludes::customers();
        $id = (int) $input['id'];
        $row = get_crm_person($id);
        if (!$row) {
            throw new NotFound("Contact id '$id' not found");
        }
        $person = self::validated($input, array_intersect_key($row, array_flip(self::FIELDS)));
        $links = array_key_exists('links', $input) && $input['links'] !== null
            ? self::validatedLinks($input['links'])
            : null;

        update_crm_person(
            $id,
            $person['ref'],
            $person['name'],
            $person['name2'],
            $person['address'],
            $person['phone'],
            $person['phone2'],
            $person['fax'],
            $person['email'],
            $person['lang'],
            $person['notes'],
            [],
            null,
            self::KEEP_LINKS
        );
        if (array_key_exists('inactive', $input) && $input['inactive'] !== null) {
            update_record_status($id, $input['inactive'] ? 1 : 0, 'crm_persons', 'id');
        }
        if ($links !== null) {
            delete_crm_contacts($id);
            foreach ($links as [$type, $action, $entityId]) {
                add_crm_contact($type, $action, $entityId, $id);
            }
        }
    }

    public function delete(int $id): void
    {
        FaIncludes::customers();
        if (!get_crm_person($id)) {
            throw new NotFound("Contact id '$id' not found");
        }
        delete_crm_person($id, true);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    private static function validated(array $input, array $base): array
    {
        $person = $base;
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            if ($input[$field] === null && !in_array($field, self::NULLABLE, true)) {
                throw new BadInput("$field cannot be null.", $field);
            }
            $person[$field] = $input[$field];
        }
        // contacts_view.inc:127-136
        if (strlen((string) $person['name']) === 0) {
            throw new BadInput('The contact name cannot be empty.', 'name');
        }
        if (strlen((string) $person['ref']) === 0) {
            throw new BadInput('Contact reference cannot be empty.', 'ref');
        }

        return $person;
    }

    /**
     * @param array<int, array<string, mixed>> $links
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    private static function validatedLinks(array $links): array
    {
        // contacts_view.inc:137-141
        if ($links === []) {
            throw new BadInput('You have to select at least one category.', 'links');
        }
        $checked = [];
        foreach (array_values($links) as $i => $link) {
            $type = (string) ($link['entity'] ?? '');
            $action = (string) ($link['category'] ?? '');
            $entityId = (string) ($link['id'] ?? '');
            if (!isset(self::ENTITIES[$type])) {
                throw new BadInput("A contact links to a customer or a branch, not '$type'.", "links.$i.entity");
            }
            [$table, $column, $noun] = self::ENTITIES[$type];
            if (preg_match('/^[1-9][0-9]{0,9}$/', $entityId) !== 1 || !ReferenceCheck::exists($table, $column, $entityId)) {
                throw new BadInput("There is no $noun '$entityId'.", "links.$i.id");
            }
            if (!self::categoryInUse($type, $action)) {
                throw new BadInput("There is no contact category $type/$action in use.", "links.$i.category");
            }
            $checked[] = [$type, $action, $entityId];
        }

        return $checked;
    }

    private static function categoryInUse(string $type, string $action): bool
    {
        $result = db_query(
            'SELECT inactive FROM ' . TB_PREF . 'crm_categories WHERE type=' . db_escape($type)
            . ' AND action=' . db_escape($action),
            'could not read the contact category'
        );
        $row = db_fetch($result);

        return $row && !$row['inactive'];
    }
}
```

- [ ] **Step 4: Models, enums, link types and inputs**

`src/Model/BranchModel.php`:

```php
<?php

namespace FA\GraphQL\Model;

use Anorm\DataMapper;
use Anorm\Model;
use Anorm\Transform\BooleanTransform;
use FA\GraphQL\Db\Connection;
use FA\GraphQL\Fa\CompanyContext;

/**
 * A customer branch: FrontAccounting's cust_branch. Its primary key is
 * (branch_code, debtor_no), but branch_code is AUTO_INCREMENT and unique alone, so
 * it is the key here. Written only through BranchService; the GL accounts and sales
 * group are not API fields (spec §1). Foundation spec §4.4 conventions.
 */
class BranchModel extends Model
{
    /** @var int */
    public $id;

    /**
     * @var int The customer (debtor_no)
     * @required
     */
    public $customerId;

    /**
     * @var string
     * @required
     */
    public $name = '';

    /**
     * @var string Short name (branch_ref)
     * @required
     */
    public $ref = '';

    /** @var string */
    public $address = '';

    /** @var string Postal address; defaults to the address */
    public $postAddress = '';

    /**
     * @var int
     * @required
     */
    public $salesmanId;

    /**
     * @var int Sales area (area)
     * @required
     */
    public $salesAreaId;

    /**
     * @var int
     * @required
     */
    public $taxGroupId;

    /**
     * @var string Default inventory location (default_location)
     * @required
     */
    public $locationId = '';

    /**
     * @var int Default shipper (default_ship_via)
     * @required
     */
    public $shipperId;

    /** @var string */
    public $notes = '';

    /** @var string|null */
    public $bankAccount;

    /** @var bool */
    public $inactive = false;

    public function __construct(?\PDO $pdo = null)
    {
        $pdo = $pdo ?: Connection::current();
        $mapper = DataMapper::create($pdo, CompanyContext::prefix() . 'cust_branch', [
            'id' => 'branch_code',
            'customerId' => 'debtor_no',
            'name' => 'br_name',
            'ref' => 'branch_ref',
            'address' => 'br_address',
            'postAddress' => 'br_post_address',
            'salesmanId' => 'salesman',
            'salesAreaId' => 'area',
            'taxGroupId' => 'tax_group_id',
            'locationId' => 'default_location',
            'shipperId' => 'default_ship_via',
            'notes' => 'notes',
            'bankAccount' => 'bank_account',
            'inactive' => 'inactive',
        ]);
        $mapper->transformers = ['inactive' => new BooleanTransform()];
        parent::__construct($pdo, $mapper);
    }
}
```

`src/Model/ContactModel.php`:

```php
<?php

namespace FA\GraphQL\Model;

use Anorm\DataMapper;
use Anorm\Model;
use Anorm\Transform\BooleanTransform;
use FA\GraphQL\Db\Connection;
use FA\GraphQL\Fa\CompanyContext;

/**
 * A contact: a FrontAccounting CRM person (crm_persons). Its links to customers and
 * branches are crm_contacts rows, read by ContactType's `links` and written by
 * ContactService. Foundation spec §4.4 conventions.
 */
class ContactModel extends Model
{
    /** @var int */
    public $id;

    /**
     * @var string
     * @required
     */
    public $ref = '';

    /**
     * @var string
     * @required
     */
    public $name = '';

    /** @var string|null */
    public $name2;

    /** @var string|null */
    public $address;

    /** @var string|null */
    public $phone;

    /** @var string|null */
    public $phone2;

    /** @var string|null */
    public $fax;

    /** @var string|null */
    public $email;

    /** @var string|null Document language code */
    public $lang;

    /** @var string */
    public $notes = '';

    /** @var bool */
    public $inactive = false;

    public function __construct(?\PDO $pdo = null)
    {
        $pdo = $pdo ?: Connection::current();
        $mapper = DataMapper::create($pdo, CompanyContext::prefix() . 'crm_persons', [
            'id' => 'id',
            'ref' => 'ref',
            'name' => 'name',
            'name2' => 'name2',
            'address' => 'address',
            'phone' => 'phone',
            'phone2' => 'phone2',
            'fax' => 'fax',
            'email' => 'email',
            'lang' => 'lang',
            'notes' => 'notes',
            'inactive' => 'inactive',
        ]);
        $mapper->transformers = ['inactive' => new BooleanTransform()];
        parent::__construct($pdo, $mapper);
    }
}
```

`src/Type/Contact/ContactEntityType.php`:

```php
<?php

namespace FA\GraphQL\Type\Contact;

use GraphQL\Type\Definition\EnumType;

/** What a contact is linked to. Internal values are FrontAccounting's crm_contacts.type. */
final class ContactEntityType extends EnumType
{
    public function __construct()
    {
        parent::__construct([
            'name' => 'ContactEntity',
            'values' => [
                'CUSTOMER' => ['value' => 'customer'],
                'BRANCH' => ['value' => 'cust_branch'],
            ],
        ]);
    }
}
```

`src/Type/Contact/ContactCategoryType.php`:

```php
<?php

namespace FA\GraphQL\Type\Contact;

use GraphQL\Type\Definition\EnumType;

/**
 * What a contact is for, per link. Internal values are FrontAccounting's
 * crm_contacts.action — the system categories every customer and branch has
 * (sql/en_US-new.sql:369-377).
 */
final class ContactCategoryType extends EnumType
{
    public function __construct()
    {
        parent::__construct([
            'name' => 'ContactCategory',
            'values' => [
                'GENERAL' => ['value' => 'general'],
                'ORDER' => ['value' => 'order'],
                'DELIVERY' => ['value' => 'delivery'],
                'INVOICE' => ['value' => 'invoice'],
            ],
        ]);
    }
}
```

`src/Type/Contact/ContactLinkType.php`:

```php
<?php

namespace FA\GraphQL\Type\Contact;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

final class ContactLinkType extends ObjectType
{
    public function __construct(ContactEntityType $entity, ContactCategoryType $category)
    {
        parent::__construct([
            'name' => 'ContactLink',
            'fields' => [
                'entity' => ['type' => Type::nonNull($entity)],
                'id' => ['type' => Type::nonNull(Type::id())],
                'category' => ['type' => Type::nonNull($category)],
            ],
        ]);
    }
}
```

`src/Type/Contact/ContactLinkInput.php`:

```php
<?php

namespace FA\GraphQL\Type\Contact;

use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\Type;

final class ContactLinkInput extends InputObjectType
{
    public function __construct(ContactEntityType $entity, ContactCategoryType $category)
    {
        parent::__construct([
            'name' => 'ContactLinkInput',
            'fields' => [
                'entity' => ['type' => Type::nonNull($entity)],
                'id' => ['type' => Type::nonNull(Type::id())],
                'category' => ['type' => Type::nonNull($category)],
            ],
        ]);
    }
}
```

`src/Type/Contact/ContactLinks.php`:

```php
<?php

namespace FA\GraphQL\Type\Contact;

use FA\GraphQL\Fa\CompanyContext;

/**
 * Reads crm_contacts on the container's PDO, bound: which persons a customer or
 * branch has, and what a person is linked to. Only the links this API models are
 * returned — customers and branches, in the four system categories — so a
 * supplier link or a custom category never reaches an enum that cannot name it.
 */
final class ContactLinks
{
    private const TYPES = "('customer', 'cust_branch')";

    private const ACTIONS = "('general', 'order', 'delivery', 'invoice')";

    /**
     * @return array<int, int>
     */
    public static function personIds(\PDO $pdo, string $type, int $entityId): array
    {
        $statement = $pdo->prepare(
            'SELECT DISTINCT person_id FROM ' . CompanyContext::prefix() . 'crm_contacts'
            . ' WHERE type = ? AND entity_id = ? ORDER BY person_id'
        );
        $statement->execute([$type, (string) $entityId]);

        return array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @return array<int, array{entity: string, id: string, category: string}>
     */
    public static function forPerson(\PDO $pdo, int $personId): array
    {
        $statement = $pdo->prepare(
            'SELECT type, action, entity_id FROM ' . CompanyContext::prefix() . 'crm_contacts'
            . ' WHERE person_id = ? AND type IN ' . self::TYPES . ' AND action IN ' . self::ACTIONS . ' ORDER BY id'
        );
        $statement->execute([$personId]);
        $links = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $links[] = ['entity' => $row['type'], 'id' => (string) $row['entity_id'], 'category' => $row['action']];
        }

        return $links;
    }
}
```

`src/Type/Branch/BranchContactInput.php`:

```php
<?php

namespace FA\GraphQL\Type\Branch;

use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\Type;

/**
 * branchCreate's `contact`: the CRM person FrontAccounting's branch page creates
 * with every new branch (customer_branches.php:101-105). Without it the person is
 * named "Main Branch" for a customer's first branch, otherwise after the branch.
 */
final class BranchContactInput extends InputObjectType
{
    public function __construct()
    {
        parent::__construct([
            'name' => 'BranchContactInput',
            'fields' => [
                'name' => ['type' => Type::string(), 'description' => 'Also its reference, as on the page.'],
                'phone' => ['type' => Type::string()],
                'phone2' => ['type' => Type::string()],
                'fax' => ['type' => Type::string()],
                'email' => ['type' => Type::string()],
                'lang' => ['type' => Type::string(), 'description' => 'Document language code.'],
            ],
        ]);
    }
}
```

- [ ] **Step 5: Generate, then write the once-only files**

Run on the host: `bin/generate --dry-run`, then `bin/generate`.
Expected: `written` for `src/Type/Branch/Base/BranchTypeBase.php`, `Base/BranchCreateInputBase.php`, `Base/BranchUpdateInputBase.php`, `BranchType.php`, `BranchCreateInput.php`, `BranchUpdateInput.php`, `tests/Generated/BranchTypeTest.php`, and the same six plus test for `Contact`; `updated src/ApiSchema.php` with `branchList/Create/Delete/Update` and `contactList/Create/Delete/Update`. No `skipped` line. `BranchCreateInputBase`: `customerId`, `name`, `ref`, `salesmanId`, `salesAreaId`, `taxGroupId`, `locationId`, `shipperId` non-null; `ContactCreateInputBase`: `ref`, `name` non-null.

Replace `src/Type/Branch/BranchType.php`:

```php
<?php

namespace FA\GraphQL\Type\Branch;

use DI\Container;
use FA\GraphQL\Fa\Service\BranchService;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Type\Branch\Base\BranchTypeBase;

/**
 * Yours to edit: anorm-graphql wrote this file once and never again.
 *
 * Branches are written through FrontAccounting (spec §2): the generated create,
 * update and delete go to BranchService, each batch in one ServiceCall::each.
 */
class BranchType extends BranchTypeBase
{
    /**
     * @return array<string, string>
     */
    protected function areas(): array
    {
        return [
            self::VERB_LIST => 'SA_CUSTOMER',
            self::VERB_CREATE => 'SA_CUSTOMER',
            self::VERB_EDIT => 'SA_CUSTOMER',
            self::VERB_DELETE => 'SA_CUSTOMER',
        ];
    }

    public function resolveCreate($root, $args, Container $context): array
    {
        $this->authorize(self::VERB_CREATE, null, $context);
        $branches = $context->get(BranchService::class);
        $ids = ServiceCall::each($args['input'], function (array $input) use ($branches): int {
            return $branches->create($input);
        });

        return $this->rowsById($context, $ids);
    }

    public function resolveUpdate($root, $args, Container $context): array
    {
        $this->authorize(self::VERB_EDIT, null, $context);
        $branches = $context->get(BranchService::class);
        $ids = ServiceCall::each($args['input'], function (array $input) use ($branches): int {
            $input['id'] = self::intId($input['id'] ?? null);
            $branches->update($input);

            return $input['id'];
        });

        return $this->rowsById($context, $ids);
    }

    public function resolveDelete($root, $args, Container $context): array
    {
        $this->authorize(self::VERB_DELETE, null, $context);
        $ids = self::intIds($args['id']);
        $rows = $this->rowsById($context, $ids);
        $branches = $context->get(BranchService::class);
        ServiceCall::each($ids, function (int $id) use ($branches): void {
            $branches->delete($id);
        });

        return $rows;
    }
}
```

Replace `src/Type/Branch/BranchCreateInput.php`:

```php
<?php

namespace FA\GraphQL\Type\Branch;

use Anorm\GraphQL\Builder\FieldBuilder;
use FA\GraphQL\Type\Branch\Base\BranchCreateInputBase;

/**
 * Yours to edit: anorm-graphql wrote this file once and never again.
 *
 * Adds the CRM person FrontAccounting creates with every new branch (spec §4.3).
 */
class BranchCreateInput extends BranchCreateInputBase
{
    private BranchContactInput $contact;

    public function __construct(BranchContactInput $contact)
    {
        $this->contact = $contact;
        parent::__construct();
    }

    protected function fields(): array
    {
        return array_merge(parent::fields(), [
            FieldBuilder::create('contact', $this->contact)
                ->setDescription("The branch's contact person, created with it.")
                ->build(),
        ]);
    }
}
```

Replace `src/Type/Contact/ContactType.php`:

```php
<?php

namespace FA\GraphQL\Type\Contact;

use Anorm\GraphQL\Builder\FieldBuilder;
use DI\Container;
use FA\GraphQL\Fa\Service\ContactService;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Type\Contact\Base\ContactTypeBase;
use GraphQL\Type\Definition\Type;

/**
 * Yours to edit: anorm-graphql wrote this file once and never again.
 *
 * Contacts are written through FrontAccounting (spec §2), and `links` says what a
 * contact is for: which customers and branches, in which categories.
 */
class ContactType extends ContactTypeBase
{
    private ContactLinkType $linkType;

    public function __construct(ContactLinkType $linkType)
    {
        $this->linkType = $linkType;
        parent::__construct();
    }

    protected function fields(): array
    {
        return array_merge(parent::fields(), [
            FieldBuilder::create('links', Type::nonNull(Type::listOf(Type::nonNull($this->linkType))))
                ->setDescription('The customers and branches this contact is linked to, and for what.')
                ->setResolver(function (array $row, $args, Container $context): array {
                    return ContactLinks::forPerson($context->get(\PDO::class), (int) $row['id']);
                })
                ->build(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function areas(): array
    {
        return [
            self::VERB_LIST => 'SA_CUSTOMER',
            self::VERB_CREATE => 'SA_CUSTOMER',
            self::VERB_EDIT => 'SA_CUSTOMER',
            self::VERB_DELETE => 'SA_CUSTOMER',
        ];
    }

    public function resolveCreate($root, $args, Container $context): array
    {
        $this->authorize(self::VERB_CREATE, null, $context);
        $contacts = $context->get(ContactService::class);
        $ids = ServiceCall::each($args['input'], function (array $input) use ($contacts): int {
            return $contacts->create($input);
        });

        return $this->rowsById($context, $ids);
    }

    public function resolveUpdate($root, $args, Container $context): array
    {
        $this->authorize(self::VERB_EDIT, null, $context);
        $contacts = $context->get(ContactService::class);
        $ids = ServiceCall::each($args['input'], function (array $input) use ($contacts): int {
            $input['id'] = self::intId($input['id'] ?? null);
            $contacts->update($input);

            return $input['id'];
        });

        return $this->rowsById($context, $ids);
    }

    public function resolveDelete($root, $args, Container $context): array
    {
        $this->authorize(self::VERB_DELETE, null, $context);
        $ids = self::intIds($args['id']);
        $rows = $this->rowsById($context, $ids);
        $contacts = $context->get(ContactService::class);
        ServiceCall::each($ids, function (int $id) use ($contacts): void {
            $contacts->delete($id);
        });

        return $rows;
    }
}
```

(`FieldBuilder::setResolver()` receives the row as `Mapper::toArray` made it, then the args and the context — `anorm-graphql` `docs/customising.md`, "A computed field via `fields()`".)

Replace `src/Type/Contact/ContactCreateInput.php`:

```php
<?php

namespace FA\GraphQL\Type\Contact;

use Anorm\GraphQL\Builder\FieldBuilder;
use FA\GraphQL\Type\Contact\Base\ContactCreateInputBase;
use GraphQL\Type\Definition\Type;

/**
 * Yours to edit: anorm-graphql wrote this file once and never again.
 *
 * A contact is created with at least one link (contacts_view.inc:137-141).
 */
class ContactCreateInput extends ContactCreateInputBase
{
    private ContactLinkInput $link;

    public function __construct(ContactLinkInput $link)
    {
        $this->link = $link;
        parent::__construct();
    }

    protected function fields(): array
    {
        return array_merge(parent::fields(), [
            FieldBuilder::create('links', Type::nonNull(Type::listOf(Type::nonNull($this->link))))
                ->setDescription('At least one: the customers and branches this contact is for, and in which category.')
                ->build(),
        ]);
    }
}
```

Replace `src/Type/Contact/ContactUpdateInput.php`:

```php
<?php

namespace FA\GraphQL\Type\Contact;

use Anorm\GraphQL\Builder\FieldBuilder;
use FA\GraphQL\Type\Contact\Base\ContactUpdateInputBase;
use GraphQL\Type\Definition\Type;

/**
 * Yours to edit: anorm-graphql wrote this file once and never again.
 */
class ContactUpdateInput extends ContactUpdateInputBase
{
    private ContactLinkInput $link;

    public function __construct(ContactLinkInput $link)
    {
        $this->link = $link;
        parent::__construct();
    }

    protected function fields(): array
    {
        return array_merge(parent::fields(), [
            FieldBuilder::create('links', Type::listOf(Type::nonNull($this->link)))
                ->setDescription('When given, replaces every link; at least one. Omitted: links are kept.')
                ->build(),
        ]);
    }
}
```

`src/Type/Customer/CustomerType.php` — add a constructor and the two computed fields (keep `areas()` and the three resolvers from Task 5):

```php
use Anorm\GraphQL\Builder\FieldBuilder;
use FA\GraphQL\Type\Branch\BranchType;
use FA\GraphQL\Type\Contact\ContactLinks;
use FA\GraphQL\Type\Contact\ContactType;
use GraphQL\Type\Definition\Type;
```

```php
    private BranchType $branchType;

    private ContactType $contactType;

    public function __construct(BranchType $branchType, ContactType $contactType)
    {
        // Set before the parent constructor: it calls fields(). The container hands
        // out one instance of each Type, the same ones ApiSchema lists.
        $this->branchType = $branchType;
        $this->contactType = $contactType;
        parent::__construct();
    }

    protected function fields(): array
    {
        return array_merge(parent::fields(), [
            FieldBuilder::create('branches', Type::nonNull(Type::listOf(Type::nonNull($this->branchType))))
                ->setDescription("The customer's branches.")
                ->setResolver(function (array $row, $args, Container $context): array {
                    return $this->branchType->resolveList(
                        null,
                        ['query' => ['selector' => json_encode(['customerId' => (int) $row['id']])]],
                        $context
                    );
                })
                ->build(),
            FieldBuilder::create('contacts', Type::nonNull(Type::listOf(Type::nonNull($this->contactType))))
                ->setDescription('The contacts linked to the customer itself (a branch has its own).')
                ->setResolver(function (array $row, $args, Container $context): array {
                    $ids = ContactLinks::personIds($context->get(\PDO::class), 'customer', (int) $row['id']);

                    return $ids === [] ? [] : $this->contactType->resolveList(
                        null,
                        ['query' => ['selector' => json_encode(['id' => ['$in' => $ids]])]],
                        $context
                    );
                })
                ->build(),
        ]);
    }
```

(`$in` is an operator of Anorm's Mango parser — `vendor/saygoweb/anorm/…/MangoQueryParser.php`, `case 'in'`; `ModelType` checks the field name, `id`, against the model.)

Replace `tests/Generated/BranchTypeTest.php` (keep `expectedFieldTypes()` as generated if it differs only in order):

```php
<?php

namespace FA\GraphQL\Tests\Generated;

use FA\GraphQL\Fa\Service\CustomerService;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Tests\Support\FaTestRows;
use FA\GraphQL\Type\Branch\BranchCreateInput;
use FA\GraphQL\Type\Branch\BranchType;
use GraphQL\GraphQL;

/**
 * Yours to edit: anorm-graphql wrote this file once and never again.
 * The lifecycle is this file's own: a branch is written through FrontAccounting and
 * needs a customer to belong to.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class BranchTypeTest extends TestCase
{
    private ?string $prefix = null;

    private int $customerId;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['SysPrefs']->auto_create_branch = 0;
        $this->customerId = ServiceCall::run(function (): int {
            return (new CustomerService())->create([
                'name' => 'Branch Type Customer', 'ref' => $this->testRowPrefix() . 'c',
                'salesTypeId' => '1', 'paymentTermsId' => '3', 'creditStatusId' => '1',
            ]);
        });
        $GLOBALS['SysPrefs']->auto_create_branch = 1;
    }

    protected function typeClass(): string
    {
        return BranchType::class;
    }

    protected function inputClass(): ?string
    {
        return BranchCreateInput::class;
    }

    protected function entityName(): string
    {
        return 'branch';
    }

    protected function testRowPrefix(): ?string
    {
        return $this->prefix ?? ($this->prefix = FaTestRows::prefix());
    }

    protected function expectedFieldTypes(): array
    {
        return [
            'id' => 'ID!',
            'customerId' => 'ID',
            'name' => 'String',
            'ref' => 'String',
            'address' => 'String',
            'postAddress' => 'String',
            'salesmanId' => 'ID',
            'salesAreaId' => 'ID',
            'taxGroupId' => 'ID',
            'locationId' => 'ID',
            'shipperId' => 'ID',
            'notes' => 'String',
            'bankAccount' => 'String',
            'inactive' => 'Boolean',
        ];
    }

    protected function sampleInput(): array
    {
        return [
            'customerId' => (string) $this->customerId,
            'name' => 'Head Office',
            'ref' => $this->testRowPrefix() . 'b',
            'salesmanId' => '1', 'salesAreaId' => '1', 'taxGroupId' => '1', 'locationId' => 'DEF', 'shipperId' => '1',
        ];
    }

    protected function sampleUpdate(): array
    {
        return ['name' => 'Renamed Office'];
    }

    private const FIELDS = 'id customerId name ref salesmanId salesAreaId taxGroupId locationId shipperId';

    public function testLifecycle(): void
    {
        $before = count($this->listAll());

        $created = $this->execute(
            'mutation ($input: [BranchCreateInput!]!) { branchCreate(input: $input) { ' . self::FIELDS . ' } }',
            ['input' => [$this->sampleInput(), $this->sampleInput()]]
        )['branchCreate'];
        $this->assertCount(2, $created);
        foreach ($this->sampleInput() as $name => $value) {
            $this->assertEquals($value, $created[0][$name], "created $name");
        }
        $this->assertCount($before + 2, $this->listAll());

        $id = $created[0]['id'];
        $updated = $this->execute(
            'mutation ($input: [BranchUpdateInput!]!) { branchUpdate(input: $input) { ' . self::FIELDS . ' } }',
            ['input' => [['id' => $id] + $this->sampleUpdate()]]
        )['branchUpdate'];
        $this->assertSame('Renamed Office', $updated[0]['name']);
        $this->assertSame('DEF', $updated[0]['locationId']);

        $deleted = $this->execute(
            'mutation ($id: [ID!]!) { branchDelete(id: $id) { ' . self::FIELDS . ' } }',
            ['id' => [$id]]
        )['branchDelete'];
        $this->assertSame($id, $deleted[0]['id']);
        $this->assertCount(0, $this->listWhere(['id' => (int) $id]));
        $this->assertCount($before + 1, $this->listAll());
    }

    public function testACustomerListsItsBranches(): void
    {
        $this->execute(
            'mutation ($input: [BranchCreateInput!]!) { branchCreate(input: $input) { id } }',
            ['input' => [$this->sampleInput()]]
        );

        $customers = $this->execute(
            'query ($q: MangoInput) { customerList(query: $q) { id branches { name ref } } }',
            ['q' => ['selector' => json_encode(['id' => $this->customerId])]]
        )['customerList'];

        $this->assertSame([['name' => 'Head Office', 'ref' => $this->testRowPrefix() . 'b']], $customers[0]['branches']);
    }

    public function testAnUnknownReferenceIsBadInputNamingTheField(): void
    {
        $result = GraphQL::executeQuery(
            $this->createSchema($this->container),
            'mutation ($input: [BranchCreateInput!]!) { branchCreate(input: $input) { id } }',
            null,
            $this->container,
            ['input' => [array_merge($this->sampleInput(), ['shipperId' => '999'])]]
        )->toArray();

        $this->assertSame('BAD_INPUT', $result['errors'][0]['extensions']['code']);
        $this->assertSame('shipperId', $result['errors'][0]['extensions']['field']);
        $this->assertSame(0, $result['errors'][0]['extensions']['index']);
    }
}
```

Replace `tests/Generated/ContactTypeTest.php`:

```php
<?php

namespace FA\GraphQL\Tests\Generated;

use FA\GraphQL\Fa\Service\CustomerService;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Tests\Support\FaTestRows;
use FA\GraphQL\Type\Contact\ContactCreateInput;
use FA\GraphQL\Type\Contact\ContactType;

/**
 * Yours to edit: anorm-graphql wrote this file once and never again.
 * The lifecycle is this file's own: a contact is written through FrontAccounting and
 * is created with at least one link, which is an object field (`links { … }`).
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ContactTypeTest extends TestCase
{
    private ?string $prefix = null;

    private int $customerId;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['SysPrefs']->auto_create_branch = 0;
        $this->customerId = ServiceCall::run(function (): int {
            return (new CustomerService())->create([
                'name' => 'Contact Type Customer', 'ref' => $this->testRowPrefix() . 'c',
                'salesTypeId' => '1', 'paymentTermsId' => '3', 'creditStatusId' => '1',
            ]);
        });
        $GLOBALS['SysPrefs']->auto_create_branch = 1;
    }

    protected function typeClass(): string
    {
        return ContactType::class;
    }

    protected function inputClass(): ?string
    {
        return ContactCreateInput::class;
    }

    protected function entityName(): string
    {
        return 'contact';
    }

    protected function testRowPrefix(): ?string
    {
        return $this->prefix ?? ($this->prefix = FaTestRows::prefix());
    }

    protected function expectedFieldTypes(): array
    {
        return [
            'id' => 'ID!',
            'ref' => 'String',
            'name' => 'String',
            'name2' => 'String',
            'address' => 'String',
            'phone' => 'String',
            'phone2' => 'String',
            'fax' => 'String',
            'email' => 'String',
            'lang' => 'String',
            'notes' => 'String',
            'inactive' => 'Boolean',
        ];
    }

    protected function sampleInput(): array
    {
        return [
            'ref' => $this->testRowPrefix() . 'p',
            'name' => 'Pat Accounts',
            'email' => 'pat@example.com',
            'links' => [['entity' => 'CUSTOMER', 'id' => (string) $this->customerId, 'category' => 'INVOICE']],
        ];
    }

    protected function sampleUpdate(): array
    {
        return ['name' => 'Pat Receivables'];
    }

    private const FIELDS = 'id ref name email links { entity id category }';

    public function testLifecycle(): void
    {
        $before = count($this->listAll());

        $created = $this->execute(
            'mutation ($input: [ContactCreateInput!]!) { contactCreate(input: $input) { ' . self::FIELDS . ' } }',
            ['input' => [$this->sampleInput(), $this->sampleInput()]]
        )['contactCreate'];
        $this->assertCount(2, $created);
        $this->assertSame('Pat Accounts', $created[0]['name']);
        $this->assertSame($this->sampleInput()['links'], $created[0]['links']);
        $this->assertCount($before + 2, $this->listAll());

        $id = $created[0]['id'];
        $updated = $this->execute(
            'mutation ($input: [ContactUpdateInput!]!) { contactUpdate(input: $input) { ' . self::FIELDS . ' } }',
            ['input' => [['id' => $id] + $this->sampleUpdate()]]
        )['contactUpdate'];
        $this->assertSame('Pat Receivables', $updated[0]['name']);
        $this->assertSame($this->sampleInput()['links'], $updated[0]['links'], 'an update without links keeps them');

        $relinked = $this->execute(
            'mutation ($input: [ContactUpdateInput!]!) { contactUpdate(input: $input) { ' . self::FIELDS . ' } }',
            ['input' => [['id' => $id, 'links' => [
                ['entity' => 'CUSTOMER', 'id' => (string) $this->customerId, 'category' => 'ORDER'],
            ]]]]
        )['contactUpdate'];
        $this->assertSame('ORDER', $relinked[0]['links'][0]['category']);

        $deleted = $this->execute(
            'mutation ($id: [ID!]!) { contactDelete(id: $id) { id name } }',
            ['id' => [$id]]
        )['contactDelete'];
        $this->assertSame($id, $deleted[0]['id']);
        $this->assertCount(0, $this->listWhere(['id' => (int) $id]));
        $this->assertCount($before + 1, $this->listAll());
    }

    public function testACustomerListsItsContacts(): void
    {
        $this->execute(
            'mutation ($input: [ContactCreateInput!]!) { contactCreate(input: $input) { id } }',
            ['input' => [$this->sampleInput()]]
        );

        $customers = $this->execute(
            'query ($q: MangoInput) { customerList(query: $q) { id contacts { name links { category } } } }',
            ['q' => ['selector' => json_encode(['id' => $this->customerId])]]
        )['customerList'];

        $this->assertSame(
            [['name' => 'Pat Accounts', 'links' => [['category' => 'INVOICE']]]],
            $customers[0]['contacts']
        );
    }
}
```

`tests/Unit/ApiSchemaTest.php` — add `branchList` and `contactList` to the Query fields, and `branchCreate`, `branchDelete`, `branchUpdate`, `contactCreate`, `contactDelete`, `contactUpdate` to the Mutation fields, in the generator's (alphabetical) order. The Mutation list becomes:

```php
            [
                'branchCreate', 'branchDelete', 'branchUpdate', 'contactCreate', 'contactDelete', 'contactUpdate',
                'customerCreate', 'customerDelete', 'customerUpdate', 'login', 'tokenRefresh', 'tokenRevoke',
            ],
```

- [ ] **Step 6: Run to see them pass**

Run: `docker/fa-graphql test --testsuite integration --filter 'BranchServiceTest|ContactServiceTest|BranchTypeTest|ContactTypeTest|CustomerTypeTest'`
Expected: PASS.

Run: `docker/fa-graphql test --testsuite unit --filter ApiSchemaTest`
Expected: PASS.

Run: `docker/fa-graphql test`
Expected: PASS, every suite. Then `docker/fa-graphql db shell` → `SELECT COUNT(*) FROM 0_cust_branch WHERE branch_ref LIKE 'gqlt%'; SELECT COUNT(*) FROM 0_crm_persons WHERE ref LIKE 'gqlt%'; SELECT COUNT(*) FROM 0_debtors_master WHERE debtor_ref LIKE 'gqlt%';` — expected `0`, `0`, `0`. A person named `Main Branch` or after a branch is swept through its link (`FaTestRows::sweep`).

- [ ] **Step 7: Gates and commit**

```bash
docker/fa-graphql lint && docker/fa-graphql analyze
git add src/Fa/Service src/Model/BranchModel.php src/Model/ContactModel.php src/Type/Branch src/Type/Contact \
  src/Type/Customer/CustomerType.php src/ApiSchema.php tests/Integration/Service tests/Generated tests/Unit/ApiSchemaTest.php
git commit -m "Create, update and delete branches and contacts through FrontAccounting

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

If PHPStan reports `TB_PREF`, `branch_in_foreign_table`, `get_crm_person` or the other CRM functions as unknown, add `../../sales/includes/db/branches_db.inc` and `../../includes/db/crm_contacts_db.inc` (and the file defining `TB_PREF`, `../../includes/db/connect_db_mysqli.inc` or as Task 3 found it) to `phpstan.neon`'s `scanFiles` and commit it with the rest.

---

### Checkpoint B — after Task 6

The first writes into FrontAccounting's master data are in: write plumbing (Task 3), lookups (Task 4), customers (Task 5), branches and contacts (Task 6). Sales orders build on every piece, so this is where a mistake in the write path is cheapest to fix.

- [ ] **Review.** An independent reviewer — not the implementer of any of Tasks 3–6 — runs `/code-review medium` (or the equivalent: a correctness review at medium effort) over the diff from the commit before Task 3's first commit to `HEAD` (`git log --oneline` names it; the ledger records Task 3's base). Fix confirmed findings; commit as `Address Checkpoint B review`. Particular attention:
  - **No generated write reaches a table directly.** Every writable Type (`CustomerType`, `BranchType`, `ContactType`) overrides `resolveCreate`, `resolveUpdate` and `resolveDelete`; `resolveUpsert` is not overridden anywhere, so `FaModelType` refuses it. `grep -rn "function resolve" src/Type` lists exactly those overrides.
  - **Authorisation.** Each override calls `$this->authorize()` with the right verb before anything else; every lookup and entity has `areas()` as spec §4.2/§4.3 say (`SA_SALESORDER` for lookups and `SalesType`; `SA_CUSTOMER` for customers, branches, contacts).
  - **SQL.** Every value a client supplies reaches SQL through FrontAccounting's `db_escape()` (the FA functions, `key_in_foreign_table`, the services' own `db_query` calls) or a bound PDO parameter (`ContactLinks`, `FaTestRows`). The only unquoted values are `add_customer`/`update_customer`'s three numbers, which pass `CustomerService::sqlNumber()`. `grep -rn "TB_PREF" src` shows no concatenated client value.
  - **IDs.** Client IDs for integer keys pass `FaModelType::intId()/intIds()`; `"5 anything"` is `BAD_INPUT`, never row 5.
  - **Transactions.** Every service call runs inside `ServiceCall::run/each`; no service opens or commits a transaction of its own; a refusal mid-batch rolls back the whole batch and a later write in the same request still commits (`$transaction_level` reset — Task 3 and `CustomerServiceTest::testARefusalRollsBackTheWholeCallAndTheNextCallStillCommits`).
  - **The one FrontAccounting quirk leaned on:** `ContactService::KEEP_LINKS` passed to `update_crm_person()` — confirm against `crm_contacts_db.inc:150-174` that it deletes and inserts nothing, and that `ContactServiceTest::testAnUpdateWithoutLinksKeepsThem` would fail without it.
  - **Test hygiene.** Every test that writes through FrontAccounting sweeps its prefix; after `docker/fa-graphql test`, `SELECT COUNT(*)` of `gqlt%` rows in `debtors_master`, `cust_branch`, `crm_persons` is 0.
- [ ] **Spec compliance** — confirm each against the spec, or record the deviation in it, marked *(revised)*, with the reason:
  - §2.2 — writes fail closed; the Forbidden message is the spec's.
  - §2.3 — every write in these mutations is on FrontAccounting's mysqli; the container's PDO only reads (`grep -rn "->exec\|->prepare" src` outside `src/Auth`, `src/Db` shows reads only).
  - §3.1 — `FaTransaction` nesting and `cancel_transaction()`; a batch is one transaction and names the index.
  - §3.2 — errors become `FA_REJECTED` with `extensions.messages`; warnings reach top-level `extensions.warnings`; notifications are dropped.
  - §3.3 — `FaSession::isActive()` exists and is tested (used from Task 9).
  - §4.1 — the generated conventions: `<entity>List/Create/Update/Delete`, Create inputs with `@required` non-null and no key, Update inputs with `id: ID!`; every key property is `id`.
  - §4.2 — every lookup in the table, read-only, `SA_SALESORDER`; the seed role carries it.
  - §4.3 — every bullet: customer create with the default branch and contact per `auto_create_branch`; the page's validations and messages; percent discounts; the currency lock; the delete guards; branch create/update/delete with company GL accounts and the page's guards; contacts with `links` required on create, replaced on update when given, kept otherwise; `CustomerType.branches`/`contacts` and `ContactType.links`.
  - §5 — `BAD_INPUT` with `extensions.field` (and `index` in a batch), `NOT_FOUND`, `FA_REJECTED`, `FORBIDDEN`.
  - Recorded deviations to write into the Release 2 spec now: `BranchDefaultsInput.locationId` (not `locationCode`) and `currencyId` (not `currency`) — generated names win; a later branch's default contact is named after the branch (the page leaves it blank); `Bootstrap::includeFa()` and the `FaModelType` helpers (`intId`, `intIds`, `rowsById`) added in Task 5.
- [ ] **Suites.** `docker/fa-graphql ci` is green on the main stack (upstream FrontAccounting `master`, PHP 7.4), and on the fork in a throwaway stack — `FA_REPO=https://github.com/cambell-prince/frontaccounting.git FA_REF=master-cp COMPOSE_PROJECT_NAME=fa-graphql-fork HTTP_PORT=8105 DB_PORT=3325 PMA_PORT=8106 docker/fa-graphql up --build`, `docker/fa-graphql test`, `lint`, `analyze`, then `destroy --yes` — never ports 8110/3330/8111 (sgw_sales) and never touching other containers. PHP 8.3 is left for Checkpoint D.

---

### Task 7: Sales orders — read and create

`sales_orders` and `sales_order_details` become generated entities (`SalesOrder` read-write under `create-update`, `SalesOrderLine` read-only with input-only Inputs), and `salesOrderCreate` goes through FrontAccounting's `Cart` exactly as `sales/sales_order_entry.php` does, without the page (Release 2 spec §4.4, §2.1, §3). Update and delete stay refused (`FaModelType`'s fail-closed resolvers) until Task 8.

**Files:**
- Modify: `src/Fa/Service/FaIncludes.php` (`orders()`), `bin/generate` (`READONLY`, `INPUT_ONLY`), `tests/Unit/ApiSchemaTest.php` (root field lists)
- Create: `src/Db/SqlDateTransform.php`, `src/Db/PercentTransform.php` (if Task 5 or 6 has not already — see Step 3), `src/Model/SalesOrderModel.php`, `src/Model/SalesOrderLineModel.php`, `src/Fa/Service/SalesOrderService.php`
- Generate, then edit the once-only files: `src/Type/SalesOrder/{SalesOrderType,SalesOrderCreateInput,SalesOrderUpdateInput}.php` (+ `Base/*`), `src/Type/SalesOrderLine/{SalesOrderLineType,SalesOrderLineCreateInput,SalesOrderLineUpdateInput}.php` (+ `Base/*`), `tests/Generated/SalesOrderTypeTest.php`, `tests/Generated/SalesOrderLineTypeTest.php`, `src/ApiSchema.php` (generator-maintained entries)
- Test: `tests/Unit/Db/TransformsTest.php`, `tests/Unit/Model/SalesOrderModelsTest.php`, `tests/Unit/Type/SalesOrderAreasTest.php`, `tests/Integration/SalesOrder/SalesOrderTestCase.php`, `tests/Integration/SalesOrder/SalesOrderCreateTest.php`

**Interfaces:**
- Consumes:
  - anorm-graphql 0.2 (Tasks 1–2): `--mutations create-update`, `--input-only`, `@required`, `Anorm\GraphQL\ModelType::resolveCreate($root, $args, Container $context): array`, `Anorm\GraphQL\Type\DateType::instance()` (the one shared `Date` scalar; generated bases use it for properties declared `\DateTimeInterface`).
  - Task 3: `Fa\Service\ServiceCall::run(callable)`, `ServiceCall::each(array $inputs, callable $work): array` (`$work($input, int $index)`, one `FaTransaction`, `BadInput`/`FaRejected` tagged with the index), `Fa\DateConversion::toFa($date, ?string $field = null): string` / `fromFa(string): string` / `iso($date, ?string $field = null): string`, `Error\BadInput(string $message, ?string $field = null, ?int $index = null)`, `Error\FaRejected(string $message, array $messages = [], ?int $index = null)`, `Fa\FaMessages`, `Fa\Warnings`, `Type\FaModelType` (writes throw `Forbidden` until overridden). `bin/generate` has `READONLY`, `INPUT_ONLY` and `--mutations create-update`.
  - Task 5: `Fa\Bootstrap::includeFa(string $relativePath): void` (one file, after boot); `Fa\Service\FaIncludes` (`customers()`), to which this task adds `orders()`; `Type\FaModelType::intId($id, string $field = 'id'): int`, `intIds(array $ids): array`, `rowsById(Container $context, array $ids): array` (read back through `resolveList`, so `scope()` and the list area apply; a missing key is `NOT_FOUND`); `tests/Generated/TestCase.php` sets READ COMMITTED on the container PDO and sweeps by `testRowPrefix()`.
  - Task 4: `READONLY="SalesType,PaymentTerms,TaxGroup,SalesArea,Salesman,Location,Shipper,CreditStatus,Currency,StockItem"`; the stack has a fiscal year covering today; apitest's role holds `SA_SALESORDER` (3075), `SA_SALESTRANSVIEW` and `SA_EDITOTHERSTRANS` (copied from System Administrator).
  - FrontAccounting, upstream `master` (line numbers below are upstream's; the fork differs only where noted): `Cart` (`sales/includes/cart_class.inc`), `get_customer_to_order()` (`sales/includes/db/sales_order_db.inc:409`), `get_customer_details_to_order()` and `add_to_order()` (`sales/includes/ui/sales_order_ui.inc:72`, `:15`), `get_kit_price()` (`sales/includes/sales_db.inc:141`), `get_item_kit()` (`inventory/includes/db/items_codes_db.inc:82`), `get_sales_type()` (`sales/includes/db/sales_types_db.inc:37`), `get_payment_terms()` (`admin/db/company_db.inc:123`), `get_shipper()` (`admin/db/shipping_db.inc:63`), `get_item_location()` (`inventory/includes/db/items_locations_db.inc:56`), `is_date_in_fiscalyears()` (`admin/db/fiscalyears_db.inc:74`), `db_has_currency_rates()` (`includes/data_checks.inc:44`), `db_has_cash_accounts()` (`:517`), `get_unit_cost()` / `is_inventory_item()` (`includes/db/inventory_db.inc:104`, `:132`), `$Refs->get_next()` / `is_valid()` (`includes/references.inc:239`, `:275`).
- Produces (Tasks 8–10 use these exact names):
  - `FA\GraphQL\Fa\Service\FaIncludes::orders(): void` — what a `Cart` needs beyond boot (alongside Task 5's `customers()`).
  - `FA\GraphQL\Db\SqlDateTransform` (DATE ↔ `\DateTimeImmutable`, zero date ↔ `null`), `FA\GraphQL\Db\PercentTransform` (stored fraction ↔ 0–100 percent).
  - `FA\GraphQL\Model\SalesOrderModel` — `id` (order_no), `transType`, `version`, `template`, `customerId` @required, `branchId` @required, `reference`, `customerRef`, `comments`, `orderDate` @required, `salesTypeId`, `shipperId`, `deliveryAddress`, `phone`, `email`, `deliverTo`, `freight`, `locationCode`, `deliveryDate`, `paymentTermsId`, `total`, `prepaymentAmount`, `allocated`.
  - `FA\GraphQL\Model\SalesOrderLineModel` — `id`, `orderId`, `transType`, `stockId` @required, `description`, `qtyDelivered` (qty_sent), `unitPrice`, `quantity` @required, `qtyInvoiced` (invoiced), `discountPercent` (0–100).
  - `FA\GraphQL\Fa\Service\SalesOrderService`: `const TRANS_TYPE = 30`; `create(array $input): int`. Protected helpers Task 8 builds on: `setCustomer(\Cart, $customerId, $branchId, string $field)`, `applyHeader(\Cart, array $input)`, `reference(\Cart, ?string $given)`, `addLine(\Cart, array $line, string $field): void`, `checkLine(...)`, `validate(\Cart)`, `assertFiscalYear(string $faDate, string $field)`.
  - `FA\GraphQL\Type\SalesOrder\SalesOrderType`: areas `list` → `SA_SALESTRANSVIEW`, `create`/`edit`/`delete` → `SA_SALESORDER`; `scope()` `['transType' => 30]`; computed `lines: [SalesOrderLineType!]!`; `resolveCreate` (reads back with Task 5's `rowsById()`); `public static function linesOf(int $orderId, $context): array` (the `lines` resolver; Task 8's delete snapshot).
  - `SalesOrderCreateInput::SERVER_SET` (fields removed from the generated Input) and a non-null `lines: [SalesOrderLineCreateInput!]!`; `SalesOrderLineCreateInput` without the server-set line fields.
  - Test support `FA\GraphQL\Tests\Integration\SalesOrder\SalesOrderTestCase`: `$this->container`, `service(): SalesOrderService`, `orderInput(array $overrides = []): array`, `createOrder(array $overrides = []): int`, `track(int $orderNo): void`, `deliver(int $orderNo, array $qtyByLineId): int`, `lineIds(int $orderNo): array` (line ids in entry order), `orderRow(int $orderNo): ?array`, `lineRows(int $orderNo): array`, `purgeOrder(int $orderNo): void`, `today(): string`.
- FrontAccounting facts this task relies on (read upstream master; verified while planning):
  - `new Cart(ST_SALESORDER, 0)` (`cart_class.inc:94-110`, `read()` `:246-286`) takes a default date and reference; `get_customer_details_to_order()` then computes `due_date` from `$cart->document_date` (`sales_order_ui.inc:124-125`), so the document date is set **before** it is called.
  - `get_customer_to_order()` inner-joins `credit_status` and `sales_types` (`sales_order_db.inc:409-437`): an unknown customer, or one whose sales type or credit status is missing, returns false. `get_customer_details_to_order()` returns an error text for a customer on hold (`:81-82`, it continues setting the cart) or a branch that is not the customer's (`:100-103`, it returns at once); the page then shows no Place Order button, so either is a refusal.
  - `Cart::write(1)` on a new order (`cart_class.inc:288-347`) returns the order number, or **-1** when the reference is not new and `ref_no_auto_increase` is off. It runs in its own `begin_transaction()`, which only counts levels inside `ServiceCall`'s.
  - `add_sales_order()` (`sales_order_db.inc:14-83`) writes `unit_price`, `quantity` and `discount_percent` into SQL unquoted (`:52-56`): they must be numbers. It never writes `contact_email`. `add_audit_trail()` (`includes/db/audit_trail_db.inc:13-37`) sets `audit_trail.fiscal_year`, a `NOT NULL` column, from a `LEFT JOIN fiscal_year` on the document date: a date no fiscal year covers is a database error, which is why `assertFiscalYear()` checks first (the page itself skips the fiscal-year check for orders, `sales_order_entry.php:386-390`).
  - A plain stock item has an `item_codes` row with `item_code = stock_id` (demo data `0_item_codes` rows 1–5, 8), so `get_item_kit()` returns one row for it and several for a kit (`501` = `102` + `103`); no row means no such item.
  - Demo data used by the tests: customer 1 "Donald Easter LLC" (USD, sales type 1 Retail, payment terms 4 *Cash Only*, discount 0) with branch 1; customer 2 "MoneyMaker Ltd." (EUR) with branch 2; items `101` (price 300 on Retail USD), `102` (250), `103` (50), kit `501`; payment terms 3 (10 days, credit), 4 (cash), 5 (prepaid, `days_before_due = -1`); location `DEF`; shipper 1; EUR's only exchange rate is dated 2021-05-07; fiscal year 2021 exists (closed).

- [ ] **Step 1: Preconditions**

```bash
docker/fa-graphql exec php -r 'require "vendor/autoload.php"; var_dump(method_exists(Anorm\GraphQL\ModelType::class, "resolveCreate"), class_exists(Anorm\GraphQL\Type\DateType::class));'
grep -n 'INPUT_ONLY\|--mutations create-update' bin/generate
docker/fa-graphql db shell -e "SELECT id, begin, end FROM 0_fiscal_year WHERE CURDATE() BETWEEN begin AND end"
docker/fa-graphql db shell -e "SELECT COUNT(*) FROM 0_sales_recurring"
```

Expected: `bool(true) bool(true)`; both `bin/generate` lines present (Task 3); one fiscal-year row covering today (Task 4); the last query answers a number (sgw_sales' table exists in the stack; Task 9 needs it, `purgeOrder()` uses it). If any fails, stop and report BLOCKED naming the missing piece — do not work around it here.

- [ ] **Step 2: Write the failing unit tests**

`tests/Unit/Db/TransformsTest.php`:

```php
<?php

namespace FA\GraphQL\Tests\Unit\Db;

use FA\GraphQL\Db\PercentTransform;
use FA\GraphQL\Db\SqlDateTransform;
use PHPUnit\Framework\TestCase;

class TransformsTest extends TestCase
{
    public function testADateColumnBecomesAnImmutableDate(): void
    {
        $date = (new SqlDateTransform())->txDatabaseToModel('2026-09-25');

        $this->assertInstanceOf(\DateTimeImmutable::class, $date);
        $this->assertSame('2026-09-25', $date->format('Y-m-d'));
    }

    /**
     * FrontAccounting's NOT NULL date columns default to the zero date; Anorm's own
     * SqlDateTimeTransform would make it -0001-11-30.
     *
     * @dataProvider noDates
     */
    public function testTheZeroDateAndNullAreNull($value): void
    {
        $this->assertNull((new SqlDateTransform())->txDatabaseToModel($value));
    }

    public function noDates(): array
    {
        return ['null' => [null], 'empty' => [''], 'zero date' => ['0000-00-00'], 'zero datetime' => ['0000-00-00 00:00:00']];
    }

    public function testADateGoesBackAsYmd(): void
    {
        $transform = new SqlDateTransform();

        $this->assertSame('2026-01-31', $transform->txModelToDatabase(new \DateTimeImmutable('2026-01-31')));
        $this->assertSame('2026-01-31', $transform->txModelToDatabase('2026-01-31'));
        $this->assertNull($transform->txModelToDatabase(null));
    }

    public function testAFractionIsAPercentAndBack(): void
    {
        $transform = new PercentTransform();

        $this->assertSame(12.5, $transform->txDatabaseToModel('0.125'));
        $this->assertSame(0.0, $transform->txDatabaseToModel(0));
        $this->assertSame(0.125, $transform->txModelToDatabase(12.5));
        $this->assertNull($transform->txDatabaseToModel(null));
    }
}
```

`tests/Unit/Model/SalesOrderModelsTest.php`:

```php
<?php

namespace FA\GraphQL\Tests\Unit\Model;

use Anorm\Transform\BooleanTransform;
use FA\GraphQL\Db\PercentTransform;
use FA\GraphQL\Db\SqlDateTransform;
use FA\GraphQL\Model\SalesOrderLineModel;
use FA\GraphQL\Model\SalesOrderModel;
use PHPUnit\Framework\TestCase;

/**
 * Constructing a model runs no query (Foundation spec section 4.4): the generator
 * builds every model on a PDO that cannot run one.
 */
class SalesOrderModelsTest extends TestCase
{
    private function pdo(): \PDO
    {
        return new \PDO('sqlite::memory:');
    }

    public function testAnOrderMapsFrontAccountingsColumns(): void
    {
        $model = new SalesOrderModel($this->pdo());
        $mapper = $model->mapper();

        $this->assertSame('0_sales_orders', $mapper->table);
        $this->assertSame('order_no', $mapper->map['id']);
        $this->assertSame('debtor_no', $mapper->map['customerId']);
        $this->assertSame('branch_code', $mapper->map['branchId']);
        $this->assertSame('ord_date', $mapper->map['orderDate']);
        $this->assertSame('order_type', $mapper->map['salesTypeId']);
        $this->assertSame('ship_via', $mapper->map['shipperId']);
        $this->assertSame('from_stk_loc', $mapper->map['locationCode']);
        $this->assertSame('delivery_date', $mapper->map['deliveryDate']);
        $this->assertSame('payment_terms', $mapper->map['paymentTermsId']);
        $this->assertSame('prep_amount', $mapper->map['prepaymentAmount']);
        $this->assertSame('alloc', $mapper->map['allocated']);
        $this->assertInstanceOf(SqlDateTransform::class, $mapper->transformers['ord_date']);
        $this->assertInstanceOf(SqlDateTransform::class, $mapper->transformers['delivery_date']);
        $this->assertInstanceOf(BooleanTransform::class, $mapper->transformers['type']);
        $this->assertSame(30, $model->transType);
    }

    public function testALineMapsItsColumnsAndShowsItsDiscountAsAPercent(): void
    {
        $mapper = (new SalesOrderLineModel($this->pdo()))->mapper();

        $this->assertSame('0_sales_order_details', $mapper->table);
        $this->assertSame('order_no', $mapper->map['orderId']);
        $this->assertSame('stk_code', $mapper->map['stockId']);
        $this->assertSame('qty_sent', $mapper->map['qtyDelivered']);
        $this->assertSame('invoiced', $mapper->map['qtyInvoiced']);
        $this->assertSame('unit_price', $mapper->map['unitPrice']);
        $this->assertInstanceOf(PercentTransform::class, $mapper->transformers['discount_percent']);
    }

    /**
     * anorm-graphql 0.2 makes an @required property non-null in the Create input.
     */
    public function testTheRequiredPropertiesAreMarked(): void
    {
        foreach ([
            [SalesOrderModel::class, ['customerId', 'branchId', 'orderDate']],
            [SalesOrderLineModel::class, ['stockId', 'quantity']],
        ] as [$class, $required]) {
            foreach ((new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                $marked = strpos((string) $property->getDocComment(), '@required') !== false;
                $this->assertSame(
                    in_array($property->getName(), $required, true),
                    $marked,
                    "$class::\${$property->getName()}"
                );
            }
        }
    }
}
```

(If `mapper()` is not public on Anorm 3.2.1's `Model`, use `$model->_mapper` — the SalesType pilot's tests show which the module already uses.)

- [ ] **Step 3: Run them to see them fail; transforms and models**

```bash
docker/fa-graphql test --testsuite unit --filter 'TransformsTest|SalesOrderModelsTest'
```

Expected: FAIL — `Class "FA\GraphQL\Db\SqlDateTransform" not found`, `Class "FA\GraphQL\Model\SalesOrderModel" not found`.

`ls src/Db` first: if Task 5 or 6 already created `SqlDateTransform.php` / `PercentTransform.php` with this behaviour, keep theirs (and their tests) and skip the matching file below; the tests above must pass against it either way.

`src/Db/SqlDateTransform.php`:

```php
<?php

namespace FA\GraphQL\Db;

use Anorm\TransformInterface;

/**
 * A DATE column as a \DateTimeImmutable (the Date scalar's value), and
 * FrontAccounting's zero date — the default of its NOT NULL date columns — as null.
 * Anorm's SqlDateTimeTransform would read '0000-00-00' as -0001-11-30.
 */
final class SqlDateTransform implements TransformInterface
{
    public function txDatabaseToModel($value)
    {
        if ($value === null || $value === '' || strpos((string) $value, '0000-00-00') === 0) {
            return null;
        }

        return new \DateTimeImmutable(substr((string) $value, 0, 10), new \DateTimeZone('UTC'));
    }

    public function txModelToDatabase($value)
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return $value;
    }
}
```

`src/Db/PercentTransform.php`:

```php
<?php

namespace FA\GraphQL\Db;

use Anorm\TransformInterface;

/**
 * FrontAccounting stores discounts as fractions (0.1); the API shows percentages
 * (10), everywhere the same (Release 2 spec section 4.3).
 */
final class PercentTransform implements TransformInterface
{
    public function txDatabaseToModel($value)
    {
        return $value === null ? null : round((float) $value * 100, 6);
    }

    public function txModelToDatabase($value)
    {
        return $value === null ? null : (float) $value / 100;
    }
}
```

`src/Model/SalesOrderModel.php`:

```php
<?php

namespace FA\GraphQL\Model;

use Anorm\DataMapper;
use Anorm\Model;
use Anorm\Transform\BooleanTransform;
use FA\GraphQL\Db\Connection;
use FA\GraphQL\Db\SqlDateTransform;
use FA\GraphQL\Fa\CompanyContext;

/**
 * A sales order: FrontAccounting's sales_orders, which also holds quotations
 * (trans_type 32). SalesOrderType's scope() keeps the API to trans_type 30.
 * Written only through FrontAccounting's Cart (SalesOrderService); read here.
 * Drafted by `anorm make`, then given the conventions of the Foundation spec
 * section 4.4.
 */
class SalesOrderModel extends Model
{
    /** @var int order_no */
    public $id;

    /** @var int 30 for a sales order, 32 for a quotation */
    public $transType = 30;

    /** @var int FrontAccounting raises it on every write; salesOrderUpdate must carry the one it read */
    public $version = 0;

    /** @var bool A template order (FrontAccounting's "type") */
    public $template = false;

    /**
     * @var int
     * @required
     */
    public $customerId;

    /**
     * @var int
     * @required
     */
    public $branchId;

    /** @var string Default: the next automatic reference */
    public $reference = '';

    /** @var string The customer's own reference (a purchase order number, say) */
    public $customerRef = '';

    /** @var string */
    public $comments;

    /**
     * @var \DateTimeInterface
     * @required
     */
    public $orderDate;

    /** @var int The price list; default: the customer's */
    public $salesTypeId;

    /** @var int Default: the branch's */
    public $shipperId;

    /** @var string */
    public $deliveryAddress = '';

    /** @var string */
    public $phone;

    /** @var string Read only: FrontAccounting 2.4 never writes this column */
    public $email;

    /** @var string */
    public $deliverTo = '';

    /** @var float */
    public $freight = 0.0;

    /** @var string Default: the branch's location */
    public $locationCode = '';

    /** @var \DateTimeInterface Default: the order date plus the company's delivery lead time */
    public $deliveryDate;

    /** @var int Default: the customer's */
    public $paymentTermsId;

    /** @var float Computed by FrontAccounting */
    public $total = 0.0;

    /** @var float Required, above 0 and at most the total, for prepaid terms */
    public $prepaymentAmount = 0.0;

    /** @var float Payments allocated to the order */
    public $allocated = 0.0;

    public function __construct(?\PDO $pdo = null)
    {
        $pdo = $pdo ?: Connection::current();
        $mapper = DataMapper::create($pdo, CompanyContext::prefix() . 'sales_orders', [
            'id' => 'order_no',
            'transType' => 'trans_type',
            'version' => 'version',
            'template' => 'type',
            'customerId' => 'debtor_no',
            'branchId' => 'branch_code',
            'reference' => 'reference',
            'customerRef' => 'customer_ref',
            'comments' => 'comments',
            'orderDate' => 'ord_date',
            'salesTypeId' => 'order_type',
            'shipperId' => 'ship_via',
            'deliveryAddress' => 'delivery_address',
            'phone' => 'contact_phone',
            'email' => 'contact_email',
            'deliverTo' => 'deliver_to',
            'freight' => 'freight_cost',
            'locationCode' => 'from_stk_loc',
            'deliveryDate' => 'delivery_date',
            'paymentTermsId' => 'payment_terms',
            'total' => 'total',
            'prepaymentAmount' => 'prep_amount',
            'allocated' => 'alloc',
        ]);
        $mapper->transformers = [
            'type' => new BooleanTransform(),
            'ord_date' => new SqlDateTransform(),
            'delivery_date' => new SqlDateTransform(),
        ];
        parent::__construct($pdo, $mapper);
    }
}
```

`src/Model/SalesOrderLineModel.php`:

```php
<?php

namespace FA\GraphQL\Model;

use Anorm\DataMapper;
use Anorm\Model;
use FA\GraphQL\Db\Connection;
use FA\GraphQL\Db\PercentTransform;
use FA\GraphQL\Fa\CompanyContext;

/**
 * A line of a sales order: FrontAccounting's sales_order_details. Read-only as an
 * entity; its Inputs are nested in the order's (bin/generate: READONLY and
 * INPUT_ONLY), and SalesOrderService writes it through the Cart.
 */
class SalesOrderLineModel extends Model
{
    /** @var int */
    public $id;

    /** @var int order_no */
    public $orderId;

    /** @var int 30 for a sales order's line */
    public $transType = 30;

    /**
     * @var string The item — or a kit, which is expanded into its components on entry
     * @required
     */
    public $stockId;

    /** @var string Default: the item's; honoured only for items whose description is editable */
    public $description;

    /** @var float Quantity delivered so far (qty_sent) */
    public $qtyDelivered = 0.0;

    /** @var float Default: the price list's price */
    public $unitPrice = 0.0;

    /**
     * @var float
     * @required
     */
    public $quantity;

    /** @var float Quantity invoiced so far */
    public $qtyInvoiced = 0.0;

    /** @var float 0 to 100; default: the customer's discount */
    public $discountPercent = 0.0;

    public function __construct(?\PDO $pdo = null)
    {
        $pdo = $pdo ?: Connection::current();
        $mapper = DataMapper::create($pdo, CompanyContext::prefix() . 'sales_order_details', [
            'id' => 'id',
            'orderId' => 'order_no',
            'transType' => 'trans_type',
            'stockId' => 'stk_code',
            'description' => 'description',
            'qtyDelivered' => 'qty_sent',
            'unitPrice' => 'unit_price',
            'quantity' => 'quantity',
            'qtyInvoiced' => 'invoiced',
            'discountPercent' => 'discount_percent',
        ]);
        $mapper->transformers = ['discount_percent' => new PercentTransform()];
        parent::__construct($pdo, $mapper);
    }
}
```

```bash
docker/fa-graphql test --testsuite unit --filter 'TransformsTest|SalesOrderModelsTest'
```

Expected: PASS.

- [ ] **Step 4: Generate**

`bin/generate` — the two list lines become:

```bash
READONLY="SalesType,PaymentTerms,TaxGroup,SalesArea,Salesman,Location,Shipper,CreditStatus,Currency,StockItem,SalesOrderLine"
INPUT_ONLY="SalesOrderLine"
```

(If Tasks 5–6 appended to either list, keep their entries and append these.) Then, on the host:

```bash
bin/generate --dry-run --only SalesOrder,SalesOrderLine
bin/generate --only SalesOrder,SalesOrderLine
```

Expected (file names as Tasks 1–2 built them; the dry run shows the `ApiSchema.php` diff adding `salesOrderList`, `salesOrderLineList`, `salesOrderCreate`, `salesOrderUpdate`, `salesOrderDelete` — and no `salesOrderLineCreate/Update/Delete`):

```
written  src/Type/SalesOrder/Base/SalesOrderTypeBase.php
written  src/Type/SalesOrder/Base/SalesOrderCreateInputBase.php
written  src/Type/SalesOrder/Base/SalesOrderUpdateInputBase.php
written  src/Type/SalesOrder/SalesOrderType.php
written  src/Type/SalesOrder/SalesOrderCreateInput.php
written  src/Type/SalesOrder/SalesOrderUpdateInput.php
written  tests/Generated/SalesOrderTypeTest.php
written  src/Type/SalesOrderLine/Base/SalesOrderLineTypeBase.php
written  src/Type/SalesOrderLine/Base/SalesOrderLineCreateInputBase.php
written  src/Type/SalesOrderLine/Base/SalesOrderLineUpdateInputBase.php
written  src/Type/SalesOrderLine/SalesOrderLineType.php
written  src/Type/SalesOrderLine/SalesOrderLineCreateInput.php
written  src/Type/SalesOrderLine/SalesOrderLineUpdateInput.php
written  tests/Generated/SalesOrderLineTypeTest.php
updated  src/ApiSchema.php
```

Check in `Base/SalesOrderTypeBase.php`: `extends \FA\GraphQL\Type\FaModelType`; `orderDate`/`deliveryDate` use the `Date` scalar; `customerId`, `branchId`, `salesTypeId`, `shipperId`, `paymentTermsId`, `orderId` are `ID`; `locationCode` is `String`. In `Base/SalesOrderCreateInputBase.php`: `customerId: ID!`, `branchId: ID!`, `orderDate: Date!`, no `id`. In `Base/SalesOrderLineCreateInputBase.php`: `stockId: ID!`, `quantity: Float!`. A `skipped …` line means a model did not load: fix it and run again.

The generated `SalesOrderType` and `SalesOrderLineType` are empty subclasses of bases that extend `FaModelType`, whose `areas()` is abstract: nothing that builds `ApiSchema` loads until Step 5 is done. Do Steps 5 and 6 before running anything.

- [ ] **Step 5: The once-only Types and Inputs**

`src/Type/SalesOrderLine/SalesOrderLineType.php` (whole file):

```php
<?php

namespace FA\GraphQL\Type\SalesOrderLine;

use FA\GraphQL\Fa\Service\SalesOrderService;
use FA\GraphQL\Type\SalesOrderLine\Base\SalesOrderLineTypeBase;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 *
 * Read-only (bin/generate: READONLY): a line is written with its order, through
 * salesOrderCreate/salesOrderUpdate. sales_order_details also holds quotation
 * lines; scope() keeps the API to sales orders'.
 */
class SalesOrderLineType extends SalesOrderLineTypeBase
{
    /**
     * @return array<string, string>
     */
    protected function areas(): array
    {
        return [self::VERB_LIST => 'SA_SALESTRANSVIEW'];
    }

    protected function scope(): array
    {
        return ['transType' => SalesOrderService::TRANS_TYPE];
    }
}
```

`src/Type/SalesOrderLine/SalesOrderLineCreateInput.php` (whole file):

```php
<?php

namespace FA\GraphQL\Type\SalesOrderLine;

use FA\GraphQL\Type\SalesOrderLine\Base\SalesOrderLineCreateInputBase;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 *
 * A new line, nested in SalesOrderCreateInput.lines and SalesOrderUpdateInput.lines.
 * The fields FrontAccounting sets itself are removed from the generated Input.
 */
class SalesOrderLineCreateInput extends SalesOrderLineCreateInputBase
{
    /** Set by FrontAccounting, never by a client. */
    public const SERVER_SET = ['orderId', 'transType', 'qtyDelivered', 'qtyInvoiced'];

    protected function fields(): array
    {
        return array_values(array_filter(parent::fields(), function (array $field): bool {
            return !in_array($field['name'], self::SERVER_SET, true);
        }));
    }
}
```

`src/Type/SalesOrderLine/SalesOrderLineUpdateInput.php` — Task 8 fills it; for now remove the server-set fields the same way (copy the class above with `SalesOrderLineUpdateInputBase` as parent, keeping its `SERVER_SET`).

`src/Type/SalesOrder/SalesOrderCreateInput.php` (whole file):

```php
<?php

namespace FA\GraphQL\Type\SalesOrder;

use Anorm\GraphQL\Builder\FieldBuilder;
use FA\GraphQL\Type\SalesOrder\Base\SalesOrderCreateInputBase;
use FA\GraphQL\Type\SalesOrderLine\SalesOrderLineCreateInput;
use GraphQL\Type\Definition\Type;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 *
 * The generated Input minus what FrontAccounting sets itself, plus the order's lines
 * (Release 2 spec section 4.4). email is dropped: FrontAccounting 2.4's
 * add_sales_order() never writes contact_email.
 */
class SalesOrderCreateInput extends SalesOrderCreateInputBase
{
    /** Set by FrontAccounting (or never written by it), never by a client. */
    public const SERVER_SET = ['transType', 'version', 'template', 'total', 'allocated', 'email'];

    private SalesOrderLineCreateInput $lineInput;

    public function __construct(SalesOrderLineCreateInput $lineInput)
    {
        // Before parent::__construct(), which builds the fields.
        $this->lineInput = $lineInput;
        parent::__construct();
    }

    protected function fields(): array
    {
        $fields = array_values(array_filter(parent::fields(), function (array $field): bool {
            return !in_array($field['name'], self::SERVER_SET, true);
        }));
        $fields[] = FieldBuilder::create('lines', Type::nonNull(Type::listOf(Type::nonNull($this->lineInput))))
            ->setDescription('At least one. A kit is expanded into its components.')
            ->build();

        return $fields;
    }
}
```

`src/Type/SalesOrder/SalesOrderUpdateInput.php` — leave as generated; Task 8 fills it (`salesOrderUpdate` is refused by `FaModelType` until then).

`src/Type/SalesOrder/SalesOrderType.php` (whole file):

```php
<?php

namespace FA\GraphQL\Type\SalesOrder;

use Anorm\DataMapper;
use Anorm\GraphQL\Builder\FieldBuilder;
use Anorm\GraphQL\Mapper;
use DI\Container;
use FA\GraphQL\Fa\Service\SalesOrderService;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Model\SalesOrderLineModel;
use FA\GraphQL\Type\SalesOrder\Base\SalesOrderTypeBase;
use FA\GraphQL\Type\SalesOrderLine\SalesOrderLineType;
use GraphQL\Type\Definition\Type;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 *
 * Read like any generated Type; written only through FrontAccounting's Cart
 * (SalesOrderService), never by ModelType's own write (Release 2 spec section 2).
 * sales_orders also holds quotations; scope() keeps the API to trans_type 30.
 */
class SalesOrderType extends SalesOrderTypeBase
{
    private SalesOrderLineType $lineType;

    public function __construct(SalesOrderLineType $lineType)
    {
        // Before parent::__construct(), which calls fields().
        $this->lineType = $lineType;
        parent::__construct();
    }

    /**
     * @return array<string, string>
     */
    protected function areas(): array
    {
        return [
            self::VERB_LIST => 'SA_SALESTRANSVIEW',
            self::VERB_CREATE => 'SA_SALESORDER',
            self::VERB_EDIT => 'SA_SALESORDER',
            self::VERB_DELETE => 'SA_SALESORDER',
        ];
    }

    protected function scope(): array
    {
        return ['transType' => SalesOrderService::TRANS_TYPE];
    }

    protected function fields(): array
    {
        return array_merge(parent::fields(), [
            FieldBuilder::create('lines', Type::nonNull(Type::listOf(Type::nonNull($this->lineType))))
                ->setDescription('The order\'s lines, in entry order.')
                ->setResolver(function (array $row, $args, $context): array {
                    // A snapshot taken before a delete (Task 8) is returned as it was.
                    return $row['lines'] ?? self::linesOf((int) $row['id'], $context);
                })
                ->build(),
        ]);
    }

    public function resolveCreate($root, $args, Container $context): array
    {
        $this->authorize(self::VERB_CREATE, null, $context);
        $service = $context->get(SalesOrderService::class);
        $ids = ServiceCall::each($args['input'], function (array $input) use ($service): int {
            return $service->create($input);
        });

        return $this->rowsById($context, $ids);
    }

    /**
     * @param mixed $context the container
     * @return array<int, array<string, mixed>>
     */
    public static function linesOf(int $orderId, $context): array
    {
        $rows = [];
        $lines = DataMapper::find(SalesOrderLineModel::class, $context->get(\PDO::class))
            ->where('order_no = :id AND trans_type = :type', [':id' => $orderId, ':type' => SalesOrderService::TRANS_TYPE])
            ->orderBy('id')
            ->some();
        foreach ($lines as $line) {
            $rows[] = Mapper::toArray($line);
        }

        return $rows;
    }
}
```

(`orderBy()` takes SQL, as `where()` does — Anorm 3.2 `QueryBuilder::orderBy($sql)`.)

- [ ] **Step 6: Write the failing service and Type tests**

`tests/Unit/Type/SalesOrderAreasTest.php`:

```php
<?php

namespace FA\GraphQL\Tests\Unit\Type;

use DI\Container;
use FA\GraphQL\Error\Forbidden;
use FA\GraphQL\Type\SalesOrder\SalesOrderType;
use FA\GraphQL\Type\SalesOrderLine\SalesOrderLineType;
use PHPUnit\Framework\TestCase;

class SalesOrderAreasTest extends TestCase
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

    private function areasOf(object $type): array
    {
        $areas = new \ReflectionMethod($type, 'areas');
        $areas->setAccessible(true);

        return $areas->invoke($type);
    }

    public function testReadingNeedsTheViewAreaAndWritingTheOrderArea(): void
    {
        $this->assertSame(
            ['list' => 'SA_SALESTRANSVIEW', 'create' => 'SA_SALESORDER', 'edit' => 'SA_SALESORDER', 'delete' => 'SA_SALESORDER'],
            $this->areasOf(new SalesOrderType(new SalesOrderLineType()))
        );
        $this->assertSame(['list' => 'SA_SALESTRANSVIEW'], $this->areasOf(new SalesOrderLineType()));
    }

    public function testCreatingWithoutTheOrderAreaIsForbiddenBeforeAnythingIsTouched(): void
    {
        $this->signIn(['SA_GRAPHQL', 'SA_SALESTRANSVIEW']);

        $this->expectException(Forbidden::class);
        $this->expectExceptionMessage('SA_SALESORDER');
        (new SalesOrderType(new SalesOrderLineType()))->resolveCreate(null, ['input' => [[]]], new Container());
    }

    public function testTheLineTypeIsScopedToSalesOrders(): void
    {
        $scope = new \ReflectionMethod(SalesOrderLineType::class, 'scope');
        $scope->setAccessible(true);

        $this->assertSame(['transType' => 30], $scope->invoke(new SalesOrderLineType()));
    }
}
```

`tests/Integration/SalesOrder/SalesOrderTestCase.php`:

```php
<?php

namespace FA\GraphQL\Tests\Integration\SalesOrder;

use DI\Container;
use FA\GraphQL\Auth\Claims;
use FA\GraphQL\Config;
use FA\GraphQL\Fa\Bootstrap;
use FA\GraphQL\Fa\FaMessages;
use FA\GraphQL\Fa\Service\FaIncludes;
use FA\GraphQL\Fa\Service\SalesOrderService;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Fa\Warnings;
use FA\GraphQL\RequestInfo;
use FA\GraphQL\SessionGate;
use FA\GraphQL\Tests\Integration\FaTestCase;

/**
 * Sales-order tests against FrontAccounting in-process, signed in as apitest the way
 * a bearer token would be. What they write, FrontAccounting commits on its own mysqli
 * connection: nothing rolls it back, so every order a test makes is tracked and
 * purged — with its deliveries — in tearDown.
 *
 * Every subclass must carry
 *
 *     @runTestsInSeparateProcesses
 *     @preserveGlobalState disabled
 */
abstract class SalesOrderTestCase extends FaTestCase
{
    protected Container $container;

    /** @var int[] */
    private array $orders = [];

    protected function setUp(): void
    {
        parent::setUp();

        $config = Config::fromArray([
            'secret' => '0123456789abcdef0123456789abcdef',
            'fa_root' => Bootstrap::defaultRoot(),
        ]);
        $factory = require dirname(__DIR__, 3) . '/container.php';
        $this->container = $factory($config, new RequestInfo(false, 'phpunit 127.0.0.1'));

        $gate = $this->container->get(SessionGate::class);
        $gate->boot();
        $gate->enter(new Claims(0, 'apitest', 'sales-order-test', new \DateTimeImmutable('+5 minutes')));

        FaIncludes::orders();
        FaMessages::reset();
        Warnings::reset();
    }

    protected function tearDown(): void
    {
        foreach ($this->orders as $orderNo) {
            $this->purgeOrder($orderNo);
        }
        $this->orders = [];
        parent::tearDown();
    }

    protected function service(): SalesOrderService
    {
        return $this->container->get(SalesOrderService::class);
    }

    protected function today(): string
    {
        return date('Y-m-d');
    }

    /**
     * A valid order for demo customer 1 / branch 1: payment terms 3 (10 days, credit
     * terms, so the delivery checks apply — the customer's own terms, 4, are cash-only),
     * dated today (Task 4 made a fiscal year cover it).
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function orderInput(array $overrides = []): array
    {
        return array_merge([
            'customerId' => 1,
            'branchId' => 1,
            'orderDate' => new \DateTimeImmutable($this->today()),
            'paymentTermsId' => 3,
            'deliverTo' => 'Donald Easter',
            'deliveryAddress' => '1 Test Street',
            'lines' => [['stockId' => '101', 'quantity' => 2.0]],
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function createOrder(array $overrides = []): int
    {
        $input = $this->orderInput($overrides);
        $orderNo = ServiceCall::run(function () use ($input): int {
            return $this->service()->create($input);
        });
        $this->track($orderNo);

        return $orderNo;
    }

    protected function track(int $orderNo): void
    {
        $this->orders[] = $orderNo;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function orderRow(int $orderNo): ?array
    {
        $statement = $this->pdo()->prepare('SELECT * FROM 0_sales_orders WHERE order_no = ? AND trans_type = 30');
        $statement->execute([$orderNo]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function lineRows(int $orderNo): array
    {
        $statement = $this->pdo()->prepare(
            'SELECT * FROM 0_sales_order_details WHERE order_no = ? AND trans_type = 30 ORDER BY id'
        );
        $statement->execute([$orderNo]);

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * @return int[]
     */
    protected function lineIds(int $orderNo): array
    {
        return array_map('intval', array_column($this->lineRows($orderNo), 'id'));
    }

    /**
     * Deliver part of an order with FrontAccounting's own functions, as sgw_sales'
     * RecurringInvoiceService::generateInvoice() does (modules/sgw_sales
     * includes/service/RecurringInvoiceService.php): the order's Cart made a child
     * (a delivery), quantities set, reference 'auto'. Dated today, explicitly: upstream's
     * prepare_child() takes new_doc_date(), the fork's Today().
     *
     * @param array<int, float> $qtyByLineId sales_order_details.id => quantity to deliver
     * @return int the delivery's trans_no
     */
    protected function deliver(int $orderNo, array $qtyByLineId): int
    {
        return ServiceCall::run(function () use ($orderNo, $qtyByLineId): int {
            $delivery = new \Cart(ST_SALESORDER, [$orderNo], true);
            $delivery->reference = 'auto';
            $delivery->document_date = \Today();
            $delivery->due_date = \Today();
            foreach ($delivery->line_items as $line) {
                $line->qty_done = 0;
                $line->qty_dispatched = (float) ($qtyByLineId[(int) $line->src_id] ?? 0);
            }

            return (int) $delivery->write(1);
        });
    }

    /**
     * Remove an order and everything FrontAccounting wrote for it and its deliveries.
     * The tables are those add_sales_order(), write_sales_delivery() and sgw_sales
     * write (sales/includes/db/sales_order_db.inc, sales_delivery_db.inc).
     */
    protected function purgeOrder(int $orderNo): void
    {
        $pdo = $this->pdo();
        $deliveries = $pdo->prepare('SELECT trans_no FROM 0_debtor_trans WHERE type = 13 AND order_ = ?');
        $deliveries->execute([$orderNo]);
        foreach ($deliveries->fetchAll(\PDO::FETCH_COLUMN) as $dn) {
            foreach ([
                'DELETE FROM 0_debtor_trans_details WHERE debtor_trans_type = 13 AND debtor_trans_no = ?',
                'DELETE FROM 0_stock_moves WHERE type = 13 AND trans_no = ?',
                'DELETE FROM 0_gl_trans WHERE type = 13 AND type_no = ?',
                'DELETE FROM 0_trans_tax_details WHERE trans_type = 13 AND trans_no = ?',
                'DELETE FROM 0_audit_trail WHERE type = 13 AND trans_no = ?',
                'DELETE FROM 0_refs WHERE type = 13 AND id = ?',
                'DELETE FROM 0_comments WHERE type = 13 AND id = ?',
                'DELETE FROM 0_debtor_trans WHERE type = 13 AND trans_no = ?',
            ] as $sql) {
                $pdo->prepare($sql)->execute([$dn]);
            }
        }
        foreach ([
            'DELETE FROM 0_sales_order_details WHERE trans_type = 30 AND order_no = ?',
            'DELETE FROM 0_sales_orders WHERE trans_type = 30 AND order_no = ?',
            'DELETE FROM 0_audit_trail WHERE type = 30 AND trans_no = ?',
            'DELETE FROM 0_refs WHERE type = 30 AND id = ?',
            'DELETE FROM 0_comments WHERE type = 30 AND id = ?',
            'DELETE FROM 0_cust_allocations WHERE trans_type_to = 30 AND trans_no_to = ?',
        ] as $sql) {
            $pdo->prepare($sql)->execute([$orderNo]);
        }
        if ($pdo->query("SHOW TABLES LIKE '0_sales_recurring'")->fetch() !== false) {
            $pdo->prepare('DELETE FROM 0_sales_recurring WHERE trans_no = ?')->execute([$orderNo]);
        }
    }
}
```

Before relying on `purgeOrder()`, read `write_sales_delivery()` in upstream `sales/includes/db/sales_delivery_db.inc` and add any table it writes that the list above misses (the list is what planning found: `debtor_trans`, `debtor_trans_details`, `stock_moves`, `gl_trans`, `trans_tax_details`, `audit_trail`, `refs`, `comments`, and `sales_order_details.qty_sent`, which the order's own purge removes).

`tests/Integration/SalesOrder/SalesOrderCreateTest.php`:

```php
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
            'locationCode' => 'DEF',
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

    public function testAnotherCustomersBranchIsBadInput(): void
    {
        $this->refused(['branchId' => 2], BadInput::class, 'branchId');
    }

    public function testAnUnknownItemIsBadInput(): void
    {
        $this->refused(['lines' => [['stockId' => 'NO-SUCH-ITEM', 'quantity' => 1.0]]], BadInput::class, 'lines.0.stockId');
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
            'discount over 100' => [['stockId' => '101', 'quantity' => 1.0, 'discountPercent' => 101.0], 'lines.0.discountPercent'],
            'negative discount' => [['stockId' => '101', 'quantity' => 1.0, 'discountPercent' => -1.0], 'lines.0.discountPercent'],
            'negative price, stock item' => [['stockId' => '101', 'quantity' => 1.0, 'unitPrice' => -5.0], 'lines.0.unitPrice'],
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

        $rows = $type->resolveCreate(null, ['input' => [$this->orderInput(['customerRef' => $marker])]], $this->container);
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
}
```

- [ ] **Step 7: Run them to see them fail**

```bash
docker/fa-graphql test --testsuite unit --filter SalesOrderAreasTest
docker/fa-graphql test --testsuite integration --filter SalesOrderCreateTest
```

Expected: `SalesOrderAreasTest` PASS (the Types exist; the Forbidden comes from `Guard` before any service call) — if it errors instead, fix Step 5 first. `SalesOrderCreateTest` FAIL — `Class "FA\GraphQL\Fa\Service\SalesOrderService" not found`.

- [ ] **Step 8: `FaIncludes::orders()` and the service**

`src/Fa/Service/FaIncludes.php` (Task 5's) — add:

```php
    /**
     * What a Cart needs beyond boot. includes/ui.inc is taken for granted by
     * FrontAccounting's sales code (count_array() in sales_db.inc, for one); sgw_sales
     * includes it for the same reason. sales_order_ui.inc is for
     * get_customer_details_to_order(); its display functions are never called.
     */
    public static function orders(): void
    {
        self::customers();
        foreach ([
            'includes/ui.inc',
            'includes/db/inventory_db.inc',
            'sales/includes/cart_class.inc',
            'sales/includes/ui/sales_order_ui.inc',
            'inventory/includes/db/items_codes_db.inc',
            'inventory/includes/db/items_locations_db.inc',
            'admin/db/fiscalyears_db.inc',
            'admin/db/shipping_db.inc',
        ] as $file) {
            Bootstrap::includeFa($file);
        }
    }
```

`src/Fa/Service/SalesOrderService.php`:

```php
<?php

namespace FA\GraphQL\Fa\Service;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\FaRejected;
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
        $date = DateConversion::toFa($input['orderDate'], 'orderDate');
        $this->assertFiscalYear($date, 'orderDate');

        // cart_class.inc :94-110, read() :246-286: a new cart with a default date and
        // reference. The date is replaced before anything is computed from it.
        $cart = new \Cart(ST_SALESORDER, 0);
        $cart->document_date = $date;
        $cart->cust_ref = '';
        $cart->Comments = '';
        $this->setCustomer($cart, $input['customerId'], $input['branchId'], 'customerId');
        $this->applyHeader($cart, $input);
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
        $customerId = (int) $customerId;
        $branchId = (int) $branchId;
        // sales_order_db.inc :409-437 inner-joins credit_status and sales_types.
        $customer = get_customer_to_order($customerId);
        if (!$customer) {
            throw new BadInput(
                'There is no such customer, or its price list or credit status is missing.',
                $customerField
            );
        }
        $error = get_customer_details_to_order($cart, $customerId, $branchId);
        if ($error !== '') {
            if ((int) $customer['dissallow_invoices'] === 1) {
                // On hold (:81-82): the page offers no Place Order button.
                throw new FaRejected($error, [$error]);
            }
            // Not this customer's branch (:100-103).
            throw new BadInput($error, 'branchId');
        }
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
            $type = get_sales_type((int) $input['salesTypeId']);
            if (!$type) {
                throw new BadInput('There is no such price list.', 'salesTypeId');
            }
            $cart->set_sales_type($type['id'], $type['sales_type'], $type['tax_included'], $type['factor']);
        }
        if (self::given($input, 'paymentTermsId')) {
            $terms = get_payment_terms((int) $input['paymentTermsId']);
            if (!$terms) {
                throw new BadInput('There are no such payment terms.', 'paymentTermsId');
            }
            $cart->payment = $terms['terms_indicator'];
            $cart->payment_terms = $terms;
            if ($terms['cash_sale']) {
                // copy_to_cart() :289-295 on a change to cash terms; set_customer()
                // (cart_class.inc :358-361) and display_order_header() (:386-389) move a
                // cash sale to the point of sale's location.
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
                if (!get_shipper((int) $input['shipperId'])) {
                    throw new BadInput('There is no such shipper.', 'shipperId');
                }
                $cart->ship_via = (int) $input['shipperId'];
            }
            if (self::given($input, 'prepaymentAmount')) {
                // :303-304: only on a new order, or one nothing has been delivered or
                // invoiced from (Task 8 refuses the change on a started order).
                $cart->prep_amount = (float) $input['prepaymentAmount'];
            }
        }
        if (self::given($input, 'locationCode')) {
            $location = get_item_location((string) $input['locationCode']);
            if (!$location) {
                throw new BadInput('There is no such location.', 'locationCode');
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
        // :452-456 the reference: reference().
        if (!db_has_currency_rates($cart->customer_currency, $cart->document_date)) {
            // :457-458 (the page shows nothing; an API has to say why)
            throw new BadInput(
                sprintf('There is no exchange rate for %s as of %s.', $cart->customer_currency, $cart->document_date),
                'orderDate'
            );
        }
        if ($cart->get_items_total() < 0) {
            // :460-463
            throw new BadInput('The order total cannot be less than zero.', 'lines');
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
}
```

- [ ] **Step 9: Run them to see them pass**

```bash
docker/fa-graphql test --testsuite integration --filter SalesOrderCreateTest
```

Expected: PASS. Where a FrontAccounting behaviour turns out different from the comments above (a message text, a column FrontAccounting leaves NULL), fix the service to match FrontAccounting and correct the comment — do not bend a test that pins a spec rule.

- [ ] **Step 10: The generated tests and the schema's root fields**

`tests/Generated/SalesOrderTypeTest.php` is generated once and is yours. Open `vendor/saygoweb/anorm-graphql/src/Testing/ModelTypeTestCase.php` first: the inherited tests that write (0.1's `testLifecycle`; whatever create/update tests 0.2 added) run their mutations inside a PDO transaction that `tearDown` rolls back — but this Type writes through FrontAccounting's mysqli, which that rollback cannot reach, and whose commits a PDO transaction opened earlier would not see. Override **each** inherited test that runs a mutation to call `lifecycleThroughFrontAccounting()` below (as Tasks 5–6 do for their Types; Task 5's `TestCase` already sets READ COMMITTED on the container PDO, and its `tearDown()` must still run — call `parent::tearDown()`), and the input-mirror test(s) to allow for the removed server-set fields. Add to the generated class (keeping its generated `typeClass()`, `inputClass()`/0.2 equivalents, `entityName()` and `expectedFieldTypes()`):

```php
    /** @var int[] */
    private array $made = [];

    protected function tearDown(): void
    {
        foreach ($this->made as $orderNo) {
            $pdo = $this->container->get(\PDO::class);
            foreach ([
                'DELETE FROM 0_sales_order_details WHERE trans_type = 30 AND order_no = ?',
                'DELETE FROM 0_sales_orders WHERE trans_type = 30 AND order_no = ?',
                'DELETE FROM 0_audit_trail WHERE type = 30 AND trans_no = ?',
                'DELETE FROM 0_refs WHERE type = 30 AND id = ?',
            ] as $sql) {
                $pdo->prepare($sql)->execute([$orderNo]);
            }
        }
        $this->made = [];
        parent::tearDown();
    }

    protected function sampleInput(): array
    {
        // Demo customer 1 / branch 1 on credit terms (its own are cash-only).
        return [
            'customerId' => 1,
            'branchId' => 1,
            'orderDate' => date('Y-m-d'),
            'paymentTermsId' => 3,
            'deliverTo' => 'Donald Easter',
            'deliveryAddress' => '1 Test Street',
            'lines' => [['stockId' => '101', 'quantity' => 1.0]],
        ];
    }

    /**
     * Through the schema, with no PDO transaction open (see above): create, then read
     * back by id with the computed lines. Task 8 extends it with update and delete.
     */
    private function lifecycleThroughFrontAccounting(): void
    {
        $created = $this->run(
            'mutation ($input: [SalesOrderCreateInput!]!) { salesOrderCreate(input: $input) { id version customerId orderDate } }',
            ['input' => [$this->sampleInput()]]
        )['salesOrderCreate'];
        $id = (int) $created[0]['id'];
        $this->made[] = $id;
        $this->assertSame(date('Y-m-d'), $created[0]['orderDate']);

        $read = $this->run(
            'query ($q: MangoInput) { salesOrderList(query: $q) { id lines { stockId quantity qtyDelivered discountPercent } } }',
            ['q' => ['selector' => json_encode(['id' => $id])]]
        )['salesOrderList'];
        $this->assertCount(1, $read);
        $this->assertSame('101', $read[0]['lines'][0]['stockId']);
    }

    /**
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    private function run(string $query, array $variables): array
    {
        $result = \GraphQL\GraphQL::executeQuery(
            $this->createSchema($this->container),
            $query,
            null,
            $this->container,
            $variables
        )->toArray(\GraphQL\Error\DebugFlag::INCLUDE_DEBUG_MESSAGE);
        $this->assertArrayNotHasKey('errors', $result, json_encode($result['errors'] ?? null));

        return $result['data'];
    }
```

For each inherited mutating test, add an override with the same name, e.g. `public function testLifecycle(): void { $this->lifecycleThroughFrontAccounting(); }`. For the input-mirror test(s), override to assert that every expected field except `id` and `SalesOrderCreateInput::SERVER_SET` is on `SalesOrderCreateInput` with the Type's type minus `!` (and `customerId`, `branchId`, `orderDate` with it), and that `lines` is `[SalesOrderLineCreateInput!]!`. Do the same in `tests/Generated/SalesOrderLineTypeTest.php` for `SalesOrderLineCreateInput` minus its `SERVER_SET` (it is read-only: no mutating test to override).

`tests/Unit/ApiSchemaTest.php` — in `testTheRootFieldsAreExactlyTheSpecsAndNothingElse()` add `salesOrderLineList` and `salesOrderList` to the query list, and `salesOrderCreate`, `salesOrderDelete`, `salesOrderUpdate` to the mutation list, each in its alphabetical place among what Tasks 3–6 left there. Extend the docblock: "plus Release 2's generated entities (Release 2 spec section 4)".

- [ ] **Step 11: Run everything**

```bash
docker/fa-graphql test --testsuite unit
docker/fa-graphql test --testsuite integration
docker/fa-graphql test --testsuite http
```

Expected: PASS. `salesOrderUpdate` and `salesOrderDelete` exist in the schema and answer `FORBIDDEN` ("no write path is declared") until Task 8 — that is `FaModelType` failing closed, not a bug.

- [ ] **Step 12: Gates and commit**

```bash
docker/fa-graphql lint && docker/fa-graphql analyze
git add bin/generate src/Db src/Model/SalesOrderModel.php src/Model/SalesOrderLineModel.php \
    src/Fa/Service/FaIncludes.php src/Fa/Service/SalesOrderService.php src/Type/SalesOrder src/Type/SalesOrderLine \
    src/ApiSchema.php tests/Unit/Db tests/Unit/Model/SalesOrderModelsTest.php tests/Unit/Type/SalesOrderAreasTest.php \
    tests/Unit/ApiSchemaTest.php tests/Integration/SalesOrder tests/Generated/SalesOrderTypeTest.php \
    tests/Generated/SalesOrderLineTypeTest.php
git commit -m "Sales orders: generated read side, created through FrontAccounting's Cart

SalesOrder and SalesOrderLine are generated from their models; salesOrderCreate
goes through the Cart with the page's rules ported (can_process, check_item_data,
add_to_order's kit expansion), every date explicit. Update and delete stay
refused by FaModelType until they are wired.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Sales orders — update and delete

`salesOrderUpdate` and `salesOrderDelete` go through FrontAccounting as `sales/sales_order_entry.php` edits and cancels an order (Release 2 spec §4.4): the version the client read is checked under a row lock, lines are replaced by the given set with FrontAccounting's delivered-line rules, the header freezes once anything is delivered or invoiced, and delete is FrontAccounting's cancel — deleted when nothing was delivered, otherwise closed.

**Ruling applied (coordinator, contract change):** `salesOrderDelete` keeps the generated shape `salesOrderDelete(id: [ID!]!)` — no version argument and **no version check on delete**; the version check is for update only. Step 9 writes this into spec §4.4.

**Files:**
- Modify: `src/Fa/Service/SalesOrderService.php` (`update`, `delete`, and the hooks Task 9 fills), `src/Type/SalesOrder/SalesOrderType.php` (`resolveUpdate`, `resolveDelete`), `src/Type/SalesOrder/SalesOrderUpdateInput.php`, `src/Type/SalesOrderLine/SalesOrderLineUpdateInput.php`, `tests/Generated/SalesOrderTypeTest.php`, `docs/superpowers/specs/2026-09-25-release-2-panel-design.md` (§4.4, delete)
- Test: `tests/Integration/SalesOrder/SalesOrderUpdateTest.php`, `tests/Integration/SalesOrder/SalesOrderDeleteTest.php`, `tests/Unit/Type/SalesOrderInputsTest.php`

**Interfaces:**
- Consumes: Task 5 — `FaIncludes`, `FaModelType::intId()` / `intIds()` / `rowsById()`. Task 7 — `FaIncludes::orders()`, `SalesOrderService` (`TRANS_TYPE`, `setCustomer()`, `applyHeader()`, `reference()`, `addLine()`, `checkLine()`, `validate()`, `assertFiscalYear()`, `given()`), `SalesOrderType::linesOf()`, the `lines` resolver's `$row['lines']` snapshot, `SalesOrderTestCase` (`createOrder`, `deliver`, `lineIds`, `orderRow`, `lineRows`, `track`, `orderInput`, `today`). Task 3 — `ServiceCall::each()`, `DateConversion::toFa()` / `iso()` / `fromFa()`, `BadInput`, `FaRejected`, `Warnings`, `Error\NotFound`, `Error\Forbidden`.
  FrontAccounting (upstream `master`): `read_sales_order()` (`sales/includes/db/sales_order_db.inc:303-355`; `trans_no = [order_no => version]`, lines carry `id` and `qty_done` = `qty_sent`), `update_sales_order()` (`:122-222`; `UPDATE … WHERE version = <read version>`, `version + 1`; returns nothing; deletes lines `WHERE id NOT IN (<kept ids>)`, which an empty list makes invalid SQL, `:164-170`), `is_sales_order_started()` (`:602-613`), `is_prepaid_order_open()` (`:625-642`), `sales_order_has_deliveries()` (`:357-373`), `close_sales_order()` (`:376-385`; no hook, no audit, no transaction of its own), `delete_sales_order()` (`:88-107`), `check_is_editable()` (`includes/data_checks.inc:646-659`), `get_audit_trail_last()` (`includes/db/audit_trail_db.inc:47`), `is_new_reference()` (`includes/references.inc:436`), `get_kit_price()`, `get_base_sales_type()`, `is_company_currency()`.
- Produces (Task 9 and 10 use these names):
  - `SalesOrderService::update(array $input): void` (a `SalesOrderUpdateInput`: `id`, `version`, patch fields, optional `lines`); `SalesOrderService::delete(int $id): string` returning `SalesOrderService::DELETED` (`'DELETED'`) or `SalesOrderService::CLOSED` (`'CLOSED'`); `SalesOrderService::STALE` (the stale-version message).
  - Hooks, empty here, filled by Task 9: `protected function isRecurringOrder(int $id, array $input): bool` (false), `protected function afterUpdate(int $id, array $input): void`, `protected function afterDelete(int $id): void`, `protected function afterClose(int $id): void`.
  - `SalesOrderUpdateInput`: generated fields minus `SERVER_SET` (`transType`, `template`, `total`, `allocated`, `email`), `version: Int!`, `lines: [SalesOrderLineUpdateInput!]`. `SalesOrderLineUpdateInput`: `id: ID` (nullable — omitted for a new line), minus its `SERVER_SET`.
  - `SalesOrderType::resolveUpdate` (returns the orders after the write, with their new `version`), `resolveDelete` (returns the orders as they were, lines included; a closed one adds a warning).
- FrontAccounting rules ported here (upstream `sales/sales_order_entry.php` unless noted):
  - **Editable at all:** `is_prepaid_order_open()` refuses an order with prepaid terms that already has invoices or allocations (`:104-108`); `check_is_editable()` refuses another user's order unless the role holds `SA_EDITOTHERSTRANS` (`:109-112`). Both apply to update and delete (the page's Cancel Order button is on the edit page).
  - **Frozen once started** (`is_started()`: any line invoiced or delivered): customer, branch, price list and order date are shown read-only (`display_order_header($cart, !is_started(), …)`, `:766`; `sales_order_ui.inc:254-263`), payment terms too (`sales_order_ui.inc:378-379`), and the prepayment (`copy_to_cart()` `:303-304`).
  - **Lines:** a delivered line cannot be deleted (`handle_delete_item()` `:591-599`); its quantity cannot go below what was delivered (`check_item_data()` `:549-554`); an existing line's item cannot change (the page edits quantity, price, discount and description only — `update_cart_item()`, `cart_class.inc:412-420`).
  - **Repricing:** changing the price list re-prices every line from the list (`sales_order_ui.inc:407-412`, `:460-466`); so does changing the date of a foreign-currency order when a base price list is set (`:425-429`); a changed date also moves the delivery date to the date plus the company's lead time (`:430-436`).
  - **Cancel:** an order with deliveries is closed (`close_sales_order()`: quantities set to what was delivered), otherwise deleted (`handle_cancel_order()` `:634-648`).
  - FrontAccounting's `version` is `tinyint unsigned`: its 256th update of one order fails in FrontAccounting itself (out of range). Not addressed here; recorded for Checkpoint C.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Type/SalesOrderInputsTest.php`:

```php
<?php

namespace FA\GraphQL\Tests\Unit\Type;

use FA\GraphQL\Type\SalesOrder\SalesOrderCreateInput;
use FA\GraphQL\Type\SalesOrder\SalesOrderUpdateInput;
use FA\GraphQL\Type\SalesOrderLine\SalesOrderLineCreateInput;
use FA\GraphQL\Type\SalesOrderLine\SalesOrderLineUpdateInput;
use PHPUnit\Framework\TestCase;

/**
 * The generated Inputs, trimmed and extended in their once-only files
 * (Release 2 spec section 4.4).
 */
class SalesOrderInputsTest extends TestCase
{
    /**
     * @return array<string, string> field => type as printed
     */
    private function fieldsOf(object $input): array
    {
        $fields = [];
        foreach ($input->getFields() as $name => $field) {
            $fields[$name] = (string) $field->getType();
        }

        return $fields;
    }

    public function testUpdateNeedsTheIdAndTheVersionAndTakesAnOptionalLineSet(): void
    {
        $fields = $this->fieldsOf(new SalesOrderUpdateInput(new SalesOrderLineUpdateInput()));

        $this->assertSame('ID!', $fields['id']);
        $this->assertSame('Int!', $fields['version']);
        $this->assertSame('[SalesOrderLineUpdateInput!]', $fields['lines']);
        $this->assertSame('ID', $fields['customerId'], 'a patch: nothing else is required');
        foreach (SalesOrderUpdateInput::SERVER_SET as $name) {
            $this->assertArrayNotHasKey($name, $fields);
        }
    }

    public function testAnUpdatedLineMayOmitItsIdToBeNew(): void
    {
        $fields = $this->fieldsOf(new SalesOrderLineUpdateInput());

        $this->assertSame('ID', $fields['id']);
        $this->assertArrayHasKey('quantity', $fields);
        foreach (SalesOrderLineUpdateInput::SERVER_SET as $name) {
            $this->assertArrayNotHasKey($name, $fields);
        }
    }

    public function testCreateStillRequiresItsFieldsAndLines(): void
    {
        $fields = $this->fieldsOf(new SalesOrderCreateInput(new SalesOrderLineCreateInput()));

        $this->assertSame('ID!', $fields['customerId']);
        $this->assertSame('[SalesOrderLineCreateInput!]!', $fields['lines']);
        $this->assertArrayNotHasKey('version', $fields);
        $this->assertArrayNotHasKey('id', $fields);
    }
}
```

`tests/Integration/SalesOrder/SalesOrderUpdateTest.php`:

```php
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

        $this->refused($orderNo, ['lines' => [['id' => $this->lineIds($other)[0], 'quantity' => 1.0]]], BadInput::class, 'lines.0.id');
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

        $this->refused($orderNo, ['lines' => [['id' => $first, 'quantity' => 1.0]]], BadInput::class, 'lines.0.quantity');
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
            'INSERT INTO 0_cust_allocations (person_id, amt, date_alloc, trans_no_from, trans_type_from, trans_no_to, trans_type_to)'
            . ' VALUES (1, 10, CURDATE(), 999999, 12, ?, 30)'
        )->execute([$orderNo]);

        $this->refused($orderNo, ['comments' => 'x'], FaRejected::class);
    }

    public function testAnotherUsersOrderNeedsEditOtherUsersTransactions(): void
    {
        global $security_areas;
        $orderNo = $this->createOrder();
        $this->pdo()->prepare('UPDATE 0_audit_trail SET user = 999 WHERE type = 30 AND trans_no = ?')->execute([$orderNo]);
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
```

`tests/Integration/SalesOrder/SalesOrderDeleteTest.php`:

```php
<?php

namespace FA\GraphQL\Tests\Integration\SalesOrder;

use FA\GraphQL\Error\NotFound;
use FA\GraphQL\Fa\Service\SalesOrderService;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Fa\Warnings;
use FA\GraphQL\Type\SalesOrder\SalesOrderType;

/**
 * salesOrderDelete is FrontAccounting's cancel (handle_cancel_order(),
 * sales/sales_order_entry.php :634-648; Release 2 spec section 4.4).
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SalesOrderDeleteTest extends SalesOrderTestCase
{
    private function delete(int $orderNo): string
    {
        return ServiceCall::run(function () use ($orderNo): string {
            return $this->service()->delete($orderNo);
        });
    }

    public function testAnOrderWithNoDeliveriesIsDeleted(): void
    {
        $orderNo = $this->createOrder();

        $this->assertSame(SalesOrderService::DELETED, $this->delete($orderNo));
        $this->assertNull($this->orderRow($orderNo));
        $this->assertSame([], $this->lineRows($orderNo));
    }

    public function testAnOrderWithDeliveriesIsClosedToWhatWasDelivered(): void
    {
        $orderNo = $this->createOrder(['lines' => [['stockId' => '101', 'quantity' => 3.0]]]);
        $this->deliver($orderNo, [$this->lineIds($orderNo)[0] => 1.0]);

        $this->assertSame(SalesOrderService::CLOSED, $this->delete($orderNo));
        $line = $this->lineRows($orderNo)[0];
        $this->assertEquals(1, $line['quantity']);
        $this->assertEquals(1, $line['qty_sent']);
    }

    public function testAnUnknownOrderIsNotFound(): void
    {
        $this->expectException(NotFound::class);
        $this->delete(999999);
    }

    public function testTheTypeReturnsTheOrdersAsTheyWereAndWarnsOfAClose(): void
    {
        $deleted = $this->createOrder();
        $closed = $this->createOrder();
        $this->deliver($closed, [$this->lineIds($closed)[0] => 1.0]);
        $type = $this->container->get(SalesOrderType::class);

        $rows = $type->resolveDelete(null, ['id' => [(string) $deleted, (string) $closed]], $this->container);

        $this->assertSame([$deleted, $closed], array_map('intval', array_column($rows, 'id')));
        $this->assertCount(1, $rows[0]['lines'], 'the deleted order\'s lines, as they were');
        $this->assertEquals(2, $rows[1]['lines'][0]['quantity'], 'the closed order before it was closed');
        $this->assertNull($this->orderRow($deleted));
        $this->assertCount(1, array_filter(Warnings::all(), function (string $w) use ($closed): bool {
            return strpos($w, "Sales order $closed") === 0;
        }));
    }
}
```

- [ ] **Step 2: Run them to see them fail**

```bash
docker/fa-graphql test --testsuite unit --filter SalesOrderInputsTest
docker/fa-graphql test --testsuite integration --filter 'SalesOrderUpdateTest|SalesOrderDeleteTest'
```

Expected: FAIL — `Undefined class constant 'SERVER_SET'` / `version` is `Int`; `Call to undefined method …SalesOrderService::update()`; `delete()` likewise.

- [ ] **Step 3: The update Inputs**

`src/Type/SalesOrderLine/SalesOrderLineUpdateInput.php` (whole file):

```php
<?php

namespace FA\GraphQL\Type\SalesOrderLine;

use FA\GraphQL\Type\SalesOrderLine\Base\SalesOrderLineUpdateInputBase;
use GraphQL\Type\Definition\Type;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 *
 * A line in SalesOrderUpdateInput.lines, which replaces the order's set: with an id
 * it is that existing line, updated; without one it is new. So the generated
 * `id: ID!` becomes optional here. FrontAccounting's own fields are removed.
 */
class SalesOrderLineUpdateInput extends SalesOrderLineUpdateInputBase
{
    /** Set by FrontAccounting, never by a client. */
    public const SERVER_SET = ['orderId', 'transType', 'qtyDelivered', 'qtyInvoiced'];

    protected function fields(): array
    {
        $fields = [];
        foreach (parent::fields() as $field) {
            if (in_array($field['name'], self::SERVER_SET, true)) {
                continue;
            }
            if ($field['name'] === 'id') {
                $field['type'] = Type::id();
                $field['description'] = 'An existing line of this order; omit it for a new line.';
            }
            $fields[] = $field;
        }

        return $fields;
    }
}
```

`src/Type/SalesOrder/SalesOrderUpdateInput.php` (whole file):

```php
<?php

namespace FA\GraphQL\Type\SalesOrder;

use Anorm\GraphQL\Builder\FieldBuilder;
use FA\GraphQL\Type\SalesOrder\Base\SalesOrderUpdateInputBase;
use FA\GraphQL\Type\SalesOrderLine\SalesOrderLineUpdateInput;
use GraphQL\Type\Definition\Type;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 *
 * A patch: omitted fields are left as they are. version is required — the one the
 * client read; the order is refused if it has changed since (Release 2 spec section
 * 4.4). lines, when given, replaces the order's lines.
 */
class SalesOrderUpdateInput extends SalesOrderUpdateInputBase
{
    /** Set by FrontAccounting (or never written by it), never by a client. */
    public const SERVER_SET = ['transType', 'template', 'total', 'allocated', 'email'];

    private SalesOrderLineUpdateInput $lineInput;

    public function __construct(SalesOrderLineUpdateInput $lineInput)
    {
        // Before parent::__construct(), which builds the fields.
        $this->lineInput = $lineInput;
        parent::__construct();
    }

    protected function fields(): array
    {
        $fields = [];
        foreach (parent::fields() as $field) {
            if (in_array($field['name'], self::SERVER_SET, true)) {
                continue;
            }
            if ($field['name'] === 'version') {
                $field['type'] = Type::nonNull(Type::int());
                $field['description'] = 'The version you read. A changed order is refused: read it again.';
            }
            $fields[] = $field;
        }
        $fields[] = FieldBuilder::create('lines', Type::listOf(Type::nonNull($this->lineInput)))
            ->setDescription(
                'When given, replaces the lines: one with an id is updated, one without is added, '
                . 'an omitted one is deleted. A delivered line cannot be deleted.'
            )
            ->build();

        return $fields;
    }
}
```

```bash
docker/fa-graphql test --testsuite unit --filter SalesOrderInputsTest
```

Expected: PASS.

- [ ] **Step 4: The service**

`src/Fa/Service/SalesOrderService.php` — add `use FA\GraphQL\Error\Forbidden;` and `use FA\GraphQL\Error\NotFound;` (it already uses `FaIncludes`, same namespace), the constants after `TRANS_TYPE`, and the methods below the existing ones:

```php
    public const DELETED = 'DELETED';
    public const CLOSED = 'CLOSED';
    public const STALE = 'The order was changed by someone else; read it again.';
    private const FROZEN = 'Something on this order has been delivered or invoiced: its customer, branch, price list, '
        . 'date, payment terms and prepayment can no longer change.';
```

```php
    /**
     * Edit an order as sales_order_entry.php?ModifyOrderNumber= does.
     *
     * @param array<string, mixed> $input a SalesOrderUpdateInput
     */
    public function update(array $input): void
    {
        FaIncludes::orders();
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
        $customerId = self::given($input, 'customerId') ? (int) $input['customerId'] : (int) $cart->customer_id;
        $branchId = self::given($input, 'branchId') ? (int) $input['branchId'] : (int) $cart->Branch;
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
     * Task 9: whether the order has (or is being given) a recurring schedule, which
     * relaxes the frozen header and the delivered-quantity floor as sgw_sales does.
     *
     * @param array<string, mixed> $input
     */
    protected function isRecurringOrder(int $id, array $input): bool
    {
        return false;
    }

    /**
     * @param array<string, mixed> $input
     */
    protected function afterUpdate(int $id, array $input): void
    {
    }

    protected function afterDelete(int $id): void
    {
    }

    protected function afterClose(int $id): void
    {
    }

    /**
     * The row lock and the version check, inside the caller's transaction: the lock
     * holds until it commits, so nobody writes between this check and ours.
     * FrontAccounting's own check (update_sales_order()'s WHERE version = …) ignores a
     * zero-row update and rewrites the lines anyway.
     */
    private function lockVersion(int $id, int $version): void
    {
        if ($this->lockOrder($id) !== $version) {
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
            if ((int) $user->user !== (int) $audit['user']) {
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
            if (self::given($input, $field) && (int) $input[$field] !== $value) {
                throw new BadInput(self::FROZEN, $field);
            }
        }
        if (
            self::given($input, 'orderDate')
            && DateConversion::iso($input['orderDate'], 'orderDate') !== DateConversion::fromFa($cart->document_date)
        ) {
            throw new BadInput(self::FROZEN, 'orderDate');
        }
        if (self::given($input, 'prepaymentAmount') && abs((float) $input['prepaymentAmount'] - (float) $cart->prep_amount) > 0.00001) {
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
            $id = (int) $given['id'];
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
            $this->checkLine($line->stock_id, $quantity, $price, $discount, $field, (float) $line->qty_done, $recurring);
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
```

(`TB_PREF` is FrontAccounting's `&TB_PREF&` placeholder, substituted by `db_query()`: use it only inside SQL passed to `db_query()`.)

- [ ] **Step 5: The Type's resolvers**

`src/Type/SalesOrder/SalesOrderType.php` — add `use FA\GraphQL\Fa\Warnings;` and these methods:

```php
    public function resolveUpdate($root, $args, Container $context): array
    {
        $this->authorize(self::VERB_EDIT, null, $context);
        $service = $context->get(SalesOrderService::class);
        $ids = ServiceCall::each($args['input'], function (array $input) use ($service): int {
            $input['id'] = self::intId($input['id']);
            $service->update($input);

            return $input['id'];
        });

        return $this->rowsById($context, $ids);
    }

    /**
     * FrontAccounting's cancel (Release 2 spec section 4.4): returns each order as it
     * was, lines included. An order with deliveries is closed rather than deleted; the
     * response's extensions.warnings says so.
     */
    public function resolveDelete($root, $args, Container $context): array
    {
        $this->authorize(self::VERB_DELETE, null, $context);
        $ids = self::intIds($args['id']);
        $before = [];
        foreach ($this->rowsById($context, $ids) as $index => $row) {
            $row['lines'] = self::linesOf($ids[$index], $context);
            $before[] = $row;
        }

        $service = $context->get(SalesOrderService::class);
        $outcomes = ServiceCall::each($ids, function (int $id) use ($service): string {
            return $service->delete($id);
        });
        foreach ($outcomes as $index => $outcome) {
            if ($outcome === SalesOrderService::CLOSED) {
                Warnings::add("Sales order {$ids[$index]} has deliveries: its undelivered part was cancelled "
                    . '(the order is closed), not deleted.');
            }
        }

        return $before;
    }
```

(The close warning is added only after `ServiceCall::each()` has committed the batch, so a rolled-back batch leaves none.)

- [ ] **Step 6: Run the tests to see them pass**

```bash
docker/fa-graphql test --testsuite integration --filter 'SalesOrderUpdateTest|SalesOrderDeleteTest|SalesOrderCreateTest'
```

Expected: PASS. If `current_user` holds its areas under another name than `role_set` (`includes/current_user.inc`), adapt `testAnotherUsersOrderNeedsEditOtherUsersTransactions` to it — the rule under test is `check_is_editable()`'s, unchanged.

- [ ] **Step 7: The generated test's lifecycle, extended**

`tests/Generated/SalesOrderTypeTest.php` — extend `lifecycleThroughFrontAccounting()` after the read:

```php
        $version = (int) $created[0]['version'];
        $updated = $this->run(
            'mutation ($input: [SalesOrderUpdateInput!]!) { salesOrderUpdate(input: $input) { id version comments } }',
            ['input' => [['id' => $id, 'version' => $version, 'comments' => 'updated']]]
        )['salesOrderUpdate'];
        $this->assertSame('updated', $updated[0]['comments']);
        $this->assertSame($version + 1, (int) $updated[0]['version']);

        $stale = \GraphQL\GraphQL::executeQuery(
            $this->createSchema($this->container),
            'mutation ($input: [SalesOrderUpdateInput!]!) { salesOrderUpdate(input: $input) { id } }',
            null,
            $this->container,
            ['input' => [['id' => $id, 'version' => $version, 'comments' => 'stale']]]
        )->toArray();
        $this->assertSame('FA_REJECTED', $stale['errors'][0]['extensions']['code']);

        $deleted = $this->run('mutation ($id: [ID!]!) { salesOrderDelete(id: $id) { id comments } }', ['id' => [$id]])
            ['salesOrderDelete'];
        $this->assertSame('updated', $deleted[0]['comments'], 'as it was');
        $this->assertSame([], $this->run(
            'query ($q: MangoInput) { salesOrderList(query: $q) { id } }',
            ['q' => ['selector' => json_encode(['id' => $id])]]
        )['salesOrderList']);
```

(`tearDown` still purges `$this->made`; a deleted order leaves nothing to purge but its `refs` row, which the purge removes.)

```bash
docker/fa-graphql test --testsuite integration --filter SalesOrderTypeTest
```

Expected: PASS.

- [ ] **Step 8: Run everything**

```bash
docker/fa-graphql test --testsuite unit
docker/fa-graphql test --testsuite integration
docker/fa-graphql test --testsuite http
```

Expected: PASS.

- [ ] **Step 9: The spec follows the ruling**

`docs/superpowers/specs/2026-09-25-release-2-panel-design.md` §4.4, `salesOrderDelete` — replace "The version is checked as for update." with:

```markdown
  *(revised)* The version is not checked on delete: the generated
  `salesOrderDelete(id: [ID!]!)` takes only ids (generation wins, §1). The order row
  is locked (`SELECT … FOR UPDATE`) inside the transaction, so the deliveries check
  and the delete or close are one step. An order that is closed rather than deleted
  is reported in `extensions.warnings`.
```

- [ ] **Step 10: Gates and commit**

```bash
docker/fa-graphql lint && docker/fa-graphql analyze
git add src/Fa/Service/SalesOrderService.php src/Type/SalesOrder src/Type/SalesOrderLine \
    tests/Unit/Type/SalesOrderInputsTest.php tests/Integration/SalesOrder tests/Generated/SalesOrderTypeTest.php \
    docs/superpowers/specs/2026-09-25-release-2-panel-design.md
git commit -m "Sales orders: update with the version read, delete as FrontAccounting's cancel

salesOrderUpdate locks the order and refuses a stale version, replaces the lines
with FrontAccounting's delivered-line rules, freezes the header once anything is
delivered or invoiced, and re-prices on a new price list. salesOrderDelete deletes
an undelivered order and closes a delivered one; it takes only ids, as generated.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Recurrence — sgw_sales schedules on sales orders

When `sgw_sales` is active for the company, an order can carry a recurring schedule: `recurring` on the order Inputs and on `SalesOrderType` (Release 2 spec §4.5). The schedule is sgw_sales' `sales_recurring` row, written with FrontAccounting's `db_query()` inside the order's transaction — so order and schedule commit or roll back together, and deleting or closing an order deletes or ends its schedule in the same step (fixing sgw_sales' orphaned rows). A recurring order gets sgw_sales' two relaxations: its header stays editable after delivery, and a line's quantity may drop below what was delivered. Invoice generation itself is Release 3.

**Files:**
- Create: `src/Fa/Service/RecurringSchedule.php`, `src/Type/SalesOrder/RecurrenceType.php`, `src/Type/SalesOrder/RecurrenceInputType.php`, `src/Type/SalesOrder/RecurrenceRepeatsType.php`
- Modify: `src/Fa/Service/SalesOrderService.php` (constructor, `create()`, the Task 8 hooks), `src/Type/SalesOrder/SalesOrderType.php` (`recurring` field, delete snapshot), `src/Type/SalesOrder/SalesOrderCreateInput.php`, `src/Type/SalesOrder/SalesOrderUpdateInput.php`, `tests/Unit/Type/SalesOrderAreasTest.php`, `tests/Unit/Type/SalesOrderInputsTest.php` (constructor arguments), `tests/Generated/SalesOrderTypeTest.php`
- Test: `tests/Unit/Fa/RecurrenceColumnsTest.php`, `tests/Integration/SalesOrder/SalesOrderRecurrenceTest.php`

**Interfaces:**
- Consumes: Task 3 — `FaSession::isActive(string $package): bool` (instance; true when a company is open and `$GLOBALS['Hooks'][$package]` is set), `DateConversion::iso()` / `fromSql()`, `BadInput`, `FaRejected`, `ServiceCall::each()`. Task 7/8 — `SalesOrderService` (`create()`, `given()`, hooks `isRecurringOrder()`, `afterUpdate()`, `afterDelete()`, `afterClose()`, `DELETED`/`CLOSED`), `SalesOrderType::linesOf()`/`resolveDelete()` (snapshot via Task 5's `rowsById()`), `SalesOrderTestCase`. anorm-graphql 0.2 — `Anorm\GraphQL\Type\DateType::instance()` (the one shared `Date` scalar; hand-built fields and inputs use it, never the container).
  sgw_sales (`modules/sgw_sales`, master): `sales_recurring` after `sql/update_1.0.sql` + `sql/update_1.4.sql` — `id` int AUTO_INCREMENT, `trans_no` UNIQUE (the order), `dt_start` date, `dt_end` date NULL, `dt_next` date NULL, `auto` tinyint(1), `every` tinyint(4), `repeats` enum('year','month'), `occur` varchar(5). Its page writes `occur` as `'MM-DD'` for yearly (`sales_order_entry.php:563-566`) and `sprintf('%d', day)` for monthly (`:567-569`), `dt_next` NULL when blank ("may be blank to auto-calculate", `includes/ui/sales_recurring_ui.inc`; `RecurrenceSchedule` computes it from `dt_start` when NULL). Its two relaxations: a recurring order's header stays editable when started (`sales_order_entry.php:827`, `display_order_header(…, check_value('sale_recurring') || !is_started(), …)`), and the `qty >= qty_done` check is skipped (`:616-617`). `activate_extension()` applies only `update_1.0.sql`; the docker stack also applies `update_1.4.sql` (Foundation spec §9).
- Produces:
  - `FA\GraphQL\Fa\Service\RecurringSchedule`: `const PACKAGE = 'sgw_sales'`, `const NOT_ACTIVE`; `isAvailable(): bool`; `assertWritable(string $field): void` (not active → `BadInput`; table not in its 1.4 shape → `FaRejected` naming `update_1.4.sql`); `read(int $orderNo): ?array` (a `Recurrence` row, or null when there is none or sgw_sales is not active); `write(int $orderNo, array $recurrence): void`; `delete(int $orderNo): void`; `end(int $orderNo, string $isoDate): void`; `static toColumns(array $recurrence): array`; `static fromRow(array $row): array`.
  - GraphQL: `enum RecurrenceRepeats { MONTH YEAR }` (values `'month'`/`'year'`), `type Recurrence { start: Date! end: Date next: Date repeats: RecurrenceRepeats! every: Int! day: Int monthDay: String auto: Boolean! }`, `input RecurrenceInput { start: Date! end: Date repeats: RecurrenceRepeats! every: Int! day: Int monthDay: String auto: Boolean = true }`; `SalesOrderType.recurring: Recurrence`; `recurring: RecurrenceInput` on `SalesOrderCreateInput` and `SalesOrderUpdateInput`.
  - `SalesOrderService::__construct(RecurringSchedule $schedule)`.
- Semantics (spec §4.5, and these decisions):
  - A given `recurring` is a whole schedule (its required fields are required): on update it replaces the one there. To end a schedule, give it an `end`. There is no way to remove a schedule except deleting the order (spec §4.5).
  - `next` (`dt_next`) is sgw_sales' state, never written from input: NULL on a new schedule (sgw_sales computes it); kept when an update leaves `start`, `repeats`, `every` and `day`/`monthDay` as they were, reset to NULL when any changes.
  - Delete: the schedule row is deleted with the order. Close: `end` becomes today, unless it already ends earlier.
  - "A recurring order" (for the relaxations) = one that has a schedule, or is being given one by this update — as sgw_sales keys them to its "Recurring Order" box.
  - Reading `recurring` where sgw_sales is not active answers null; the schema is the same either way.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Fa/RecurrenceColumnsTest.php`:

```php
<?php

namespace FA\GraphQL\Tests\Unit\Fa;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Fa\Service\RecurringSchedule;
use PHPUnit\Framework\TestCase;

/**
 * RecurrenceInput <-> sgw_sales' sales_recurring columns (Release 2 spec section 4.5;
 * sgw_sales sales_order_entry.php :549-576).
 */
class RecurrenceColumnsTest extends TestCase
{
    public function testMonthlyStoresTheDayAsOccur(): void
    {
        $columns = RecurringSchedule::toColumns([
            'start' => new \DateTimeImmutable('2026-10-01'),
            'repeats' => 'month',
            'every' => 1,
            'day' => 15,
        ]);

        $this->assertSame([
            'dt_start' => '2026-10-01',
            'dt_end' => null,
            'auto' => 1,
            'every' => 1,
            'repeats' => 'month',
            'occur' => '15',
        ], $columns);
    }

    public function testYearlyStoresMonthDayAsOccurAndTakesAnEnd(): void
    {
        $columns = RecurringSchedule::toColumns([
            'start' => '2026-01-01',
            'end' => '2030-12-31',
            'repeats' => 'year',
            'every' => 2,
            'monthDay' => '02-29',
            'auto' => false,
        ]);

        $this->assertSame('02-29', $columns['occur']);
        $this->assertSame('2030-12-31', $columns['dt_end']);
        $this->assertSame(0, $columns['auto']);
        $this->assertSame(2, $columns['every']);
    }

    /**
     * @dataProvider invalid
     */
    public function testAnInvalidScheduleNamesItsField(array $recurrence, string $field): void
    {
        try {
            RecurringSchedule::toColumns($recurrence);
            $this->fail('accepted');
        } catch (BadInput $e) {
            $this->assertSame($field, $e->field(), $e->getMessage());
        }
    }

    public function invalid(): array
    {
        $monthly = ['start' => '2026-10-01', 'repeats' => 'month', 'every' => 1, 'day' => 1];
        $yearly = ['start' => '2026-10-01', 'repeats' => 'year', 'every' => 1, 'monthDay' => '10-01'];

        return [
            'monthly without a day' => [array_merge($monthly, ['day' => null]), 'recurring.day'],
            'day 0' => [array_merge($monthly, ['day' => 0]), 'recurring.day'],
            'day 32' => [array_merge($monthly, ['day' => 32]), 'recurring.day'],
            'monthly with monthDay' => [array_merge($monthly, ['monthDay' => '01-01']), 'recurring.monthDay'],
            'yearly without monthDay' => [array_merge($yearly, ['monthDay' => null]), 'recurring.monthDay'],
            'yearly 13-01' => [array_merge($yearly, ['monthDay' => '13-01']), 'recurring.monthDay'],
            'yearly 02-30' => [array_merge($yearly, ['monthDay' => '02-30']), 'recurring.monthDay'],
            'yearly with day' => [array_merge($yearly, ['day' => 1]), 'recurring.day'],
            'every 0' => [array_merge($monthly, ['every' => 0]), 'recurring.every'],
            'every 128 (tinyint)' => [array_merge($monthly, ['every' => 128]), 'recurring.every'],
            'unknown repeats' => [array_merge($monthly, ['repeats' => 'week']), 'recurring.repeats'],
            'no start' => [array_merge($monthly, ['start' => null]), 'recurring.start'],
            'ends before it starts' => [array_merge($monthly, ['end' => '2026-09-30']), 'recurring.end'],
        ];
    }

    public function testARowReadsBackAsARecurrence(): void
    {
        $this->assertSame([
            'start' => '2026-10-01',
            'end' => null,
            'next' => '2026-11-15',
            'repeats' => 'month',
            'every' => 1,
            'day' => 15,
            'monthDay' => null,
            'auto' => true,
        ], RecurringSchedule::fromRow([
            'id' => '7', 'trans_no' => '12', 'dt_start' => '2026-10-01', 'dt_end' => null,
            'dt_next' => '2026-11-15', 'auto' => '1', 'every' => '1', 'repeats' => 'month', 'occur' => '15',
        ]));
        $this->assertSame('03-01', RecurringSchedule::fromRow([
            'dt_start' => '2026-03-01', 'dt_end' => '0000-00-00', 'dt_next' => null,
            'auto' => '0', 'every' => '1', 'repeats' => 'year', 'occur' => '03-01',
        ])['monthDay']);
    }
}
```

`tests/Integration/SalesOrder/SalesOrderRecurrenceTest.php`:

```php
<?php

namespace FA\GraphQL\Tests\Integration\SalesOrder;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Fa\FaSession;
use FA\GraphQL\Fa\Service\RecurringSchedule;
use FA\GraphQL\Fa\Service\SalesOrderService;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Type\SalesOrder\SalesOrderType;

/**
 * Release 2 spec section 4.5. The docker stack has sgw_sales active and upgraded to
 * 1.4 (Foundation spec section 9); without it, only the last test can run.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SalesOrderRecurrenceTest extends SalesOrderTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->container->get(RecurringSchedule::class)->isAvailable()) {
            $this->markTestSkipped('sgw_sales is not active (or not at its 1.4 schema) in this stack.');
        }
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function monthly(array $overrides = []): array
    {
        return array_merge([
            'start' => new \DateTimeImmutable($this->today()),
            'repeats' => 'month',
            'every' => 1,
            'day' => 15,
        ], $overrides);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function scheduleRow(int $orderNo): ?array
    {
        $statement = $this->pdo()->prepare('SELECT * FROM 0_sales_recurring WHERE trans_no = ?');
        $statement->execute([$orderNo]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed> $patch
     */
    private function update(int $orderNo, array $patch): void
    {
        $input = array_merge(['id' => $orderNo, 'version' => (int) $this->orderRow($orderNo)['version']], $patch);
        ServiceCall::run(function () use ($input): void {
            $this->service()->update($input);
        });
    }

    public function testAnOrderCreatedWithARecurrenceHasItsSchedule(): void
    {
        $orderNo = $this->createOrder(['recurring' => $this->monthly()]);

        $row = $this->scheduleRow($orderNo);
        $this->assertSame($this->today(), $row['dt_start']);
        $this->assertNull($row['dt_end']);
        $this->assertNull($row['dt_next'], 'sgw_sales computes it');
        $this->assertSame('month', $row['repeats']);
        $this->assertSame('15', $row['occur']);
        $this->assertSame('1', (string) $row['every']);
        $this->assertSame('1', (string) $row['auto']);

        $read = $this->container->get(RecurringSchedule::class)->read($orderNo);
        $this->assertSame('month', $read['repeats']);
        $this->assertSame(15, $read['day']);
    }

    public function testAnOrderWithoutOneReadsNull(): void
    {
        $orderNo = $this->createOrder();

        $this->assertNull($this->container->get(RecurringSchedule::class)->read($orderNo));
    }

    public function testAnInvalidScheduleIsRefusedBeforeTheOrderIsWritten(): void
    {
        $marker = 'recurring-' . uniqid();
        try {
            $this->createOrder(['customerRef' => $marker, 'recurring' => $this->monthly(['day' => null])]);
            $this->fail('accepted');
        } catch (BadInput $e) {
            $this->assertSame('recurring.day', $e->field());
        }
        $count = $this->pdo()->prepare('SELECT COUNT(*) FROM 0_sales_orders WHERE customer_ref = ?');
        $count->execute([$marker]);
        $this->assertSame(0, (int) $count->fetchColumn());
    }

    public function testAScheduleRollsBackWithItsBatch(): void
    {
        $marker = 'recurring-' . uniqid();
        $schedules = (int) $this->pdo()->query('SELECT COUNT(*) FROM 0_sales_recurring')->fetchColumn();
        try {
            $this->container->get(SalesOrderType::class)->resolveCreate(null, ['input' => [
                $this->orderInput(['customerRef' => $marker, 'recurring' => $this->monthly()]),
                $this->orderInput(['customerRef' => $marker, 'lines' => []]),
            ]], $this->container);
            $this->fail('accepted');
        } catch (BadInput $e) {
            $this->assertSame(1, $e->index());
        }

        $this->assertSame($schedules, (int) $this->pdo()->query('SELECT COUNT(*) FROM 0_sales_recurring')->fetchColumn());
    }

    public function testAnUpdateSetsKeepsAndResetsTheSchedule(): void
    {
        $orderNo = $this->createOrder();
        $yearly = [
            'start' => new \DateTimeImmutable($this->today()),
            'repeats' => 'year',
            'every' => 1,
            'monthDay' => '03-01',
        ];

        $this->update($orderNo, ['recurring' => $yearly]);
        $this->assertSame('03-01', $this->scheduleRow($orderNo)['occur']);

        // sgw_sales has computed a next date; an end date leaves it alone...
        $this->pdo()->prepare("UPDATE 0_sales_recurring SET dt_next = '2030-03-01' WHERE trans_no = ?")->execute([$orderNo]);
        $this->update($orderNo, ['recurring' => array_merge($yearly, ['end' => new \DateTimeImmutable('+5 years')])]);
        $this->assertSame('2030-03-01', $this->scheduleRow($orderNo)['dt_next']);
        $this->assertNotNull($this->scheduleRow($orderNo)['dt_end']);

        // ...a new rhythm makes sgw_sales compute it again.
        $this->update($orderNo, ['recurring' => array_merge($yearly, ['every' => 2])]);
        $this->assertNull($this->scheduleRow($orderNo)['dt_next']);
        $this->assertSame('2', (string) $this->scheduleRow($orderNo)['every']);
    }

    public function testDeletingAnOrderDeletesItsSchedule(): void
    {
        $orderNo = $this->createOrder(['recurring' => $this->monthly()]);

        $outcome = ServiceCall::run(function () use ($orderNo): string {
            return $this->service()->delete($orderNo);
        });

        $this->assertSame(SalesOrderService::DELETED, $outcome);
        $this->assertNull($this->scheduleRow($orderNo));
    }

    public function testClosingAnOrderEndsItsScheduleToday(): void
    {
        $orderNo = $this->createOrder(['recurring' => $this->monthly()]);
        $this->deliver($orderNo, [$this->lineIds($orderNo)[0] => 1.0]);

        $outcome = ServiceCall::run(function () use ($orderNo): string {
            return $this->service()->delete($orderNo);
        });

        $this->assertSame(SalesOrderService::CLOSED, $outcome);
        $this->assertSame($this->today(), $this->scheduleRow($orderNo)['dt_end']);
    }

    public function testAClosedScheduleThatEndsEarlierKeepsItsEnd(): void
    {
        $orderNo = $this->createOrder(['recurring' => $this->monthly()]);
        $this->pdo()->prepare("UPDATE 0_sales_recurring SET dt_end = '2000-01-01' WHERE trans_no = ?")->execute([$orderNo]);
        $this->deliver($orderNo, [$this->lineIds($orderNo)[0] => 1.0]);

        ServiceCall::run(function () use ($orderNo): string {
            return $this->service()->delete($orderNo);
        });

        $this->assertSame('2000-01-01', $this->scheduleRow($orderNo)['dt_end']);
    }

    /**
     * sgw_sales: a recurring order's header stays editable once delivered
     * (sales_order_entry.php :827), and its quantities may drop below what was
     * delivered — every generated invoice raises qty_sent (:616-617).
     */
    public function testARecurringOrderKeepsItsHeaderEditableAndHasNoDeliveredFloor(): void
    {
        $orderNo = $this->createOrder(['recurring' => $this->monthly()]);
        [$line] = $this->lineIds($orderNo);
        $this->deliver($orderNo, [$line => 2.0]);

        $this->update($orderNo, ['salesTypeId' => 2, 'lines' => [['id' => $line, 'quantity' => 1.0]]]);

        $this->assertSame('2', $this->orderRow($orderNo)['order_type']);
        $this->assertEquals(1, $this->lineRows($orderNo)[0]['quantity']);
    }

    public function testTheTypeReadsTheScheduleAndSnapshotsItOnDelete(): void
    {
        $orderNo = $this->createOrder(['recurring' => $this->monthly()]);
        $type = $this->container->get(SalesOrderType::class);

        $rows = $type->resolveDelete(null, ['id' => [(string) $orderNo]], $this->container);

        $this->assertSame(15, $rows[0]['recurring']['day'], 'as it was before the delete');
    }

    /**
     * Release 2 spec section 4.5: without sgw_sales, recurring is BAD_INPUT and reads
     * null. Simulated in-process: install_hooks() puts an extension in $Hooks only
     * when it is active for the company, and FaSession::isActive() asks $Hooks.
     */
    public function testWithoutSgwSalesRecurringIsBadInputAndReadsNull(): void
    {
        $orderNo = $this->createOrder(['recurring' => $this->monthly()]);
        unset($GLOBALS['Hooks'][RecurringSchedule::PACKAGE]);
        $this->assertFalse($this->container->get(FaSession::class)->isActive(RecurringSchedule::PACKAGE));

        try {
            $this->createOrder(['recurring' => $this->monthly()]);
            $this->fail('accepted');
        } catch (BadInput $e) {
            $this->assertSame('recurring', $e->field());
            $this->assertSame(RecurringSchedule::NOT_ACTIVE, $e->getMessage());
        }
        $this->assertNull($this->container->get(RecurringSchedule::class)->read($orderNo));
    }
}
```

- [ ] **Step 2: Run them to see them fail**

```bash
docker/fa-graphql test --testsuite unit --filter RecurrenceColumnsTest
docker/fa-graphql test --testsuite integration --filter SalesOrderRecurrenceTest
```

Expected: FAIL — `Class "FA\GraphQL\Fa\Service\RecurringSchedule" not found`.

- [ ] **Step 3: `RecurringSchedule`**

`src/Fa/Service/RecurringSchedule.php`:

```php
<?php

namespace FA\GraphQL\Fa\Service;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\FaRejected;
use FA\GraphQL\Fa\CompanyContext;
use FA\GraphQL\Fa\DateConversion;
use FA\GraphQL\Fa\FaSession;

/**
 * An order's recurring schedule: sgw_sales' sales_recurring row (Release 2 spec
 * section 4.5). Written with FrontAccounting's db_query(), inside the order's
 * transaction — not with sgw_sales' SalesRecurringModel, which writes on its own PDO
 * connection, outside it. The columns mean what sgw_sales' page makes them mean
 * (modules/sgw_sales sales_order_entry.php :549-576): occur is "MM-DD" for a yearly
 * schedule and the day of the month for a monthly one; dt_next is sgw_sales' own state,
 * NULL until its generation computes it (includes/service/RecurrenceSchedule.php).
 */
final class RecurringSchedule
{
    public const PACKAGE = 'sgw_sales';
    public const NOT_ACTIVE = 'Recurring orders need the sgw_sales extension, which is not active for this company.';

    private FaSession $session;

    private ?bool $upgraded = null;

    public function __construct(FaSession $session)
    {
        $this->session = $session;
    }

    public function isAvailable(): bool
    {
        return $this->session->isActive(self::PACKAGE) && $this->isUpgraded();
    }

    /**
     * Refuse a schedule sgw_sales cannot hold: not active for the company is the
     * client's problem (BAD_INPUT); active but without update_1.4.sql's table shape is
     * the installation's (FA_REJECTED) — activate_extension() applies only
     * update_1.0.sql.
     */
    public function assertWritable(string $field): void
    {
        if (!$this->session->isActive(self::PACKAGE)) {
            throw new BadInput(self::NOT_ACTIVE, $field);
        }
        if (!$this->isUpgraded()) {
            $message = 'sgw_sales\' table ' . CompanyContext::prefix() . 'sales_recurring is missing or not '
                . 'upgraded: apply modules/sgw_sales/sql/update_1.4.sql.';
            throw new FaRejected($message, [$message]);
        }
    }

    /**
     * @return array<string, mixed>|null a Recurrence, or null: none, or sgw_sales not active
     */
    public function read(int $orderNo): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $row = db_fetch(db_query(
            'SELECT * FROM ' . TB_PREF . 'sales_recurring WHERE trans_no = ' . db_escape($orderNo),
            'could not read the recurring schedule'
        ));

        return $row ? self::fromRow($row) : null;
    }

    /**
     * Set or replace an order's schedule. dt_next is kept unless the rhythm changes.
     *
     * @param array<string, mixed> $recurrence a RecurrenceInput
     */
    public function write(int $orderNo, array $recurrence): void
    {
        $this->assertWritable('recurring');
        $c = self::toColumns($recurrence);
        $existing = db_fetch(db_query(
            'SELECT * FROM ' . TB_PREF . 'sales_recurring WHERE trans_no = ' . db_escape($orderNo) . ' FOR UPDATE',
            'could not read the recurring schedule'
        ));

        if (!$existing) {
            db_query(
                'INSERT INTO ' . TB_PREF . 'sales_recurring (trans_no, dt_start, dt_end, dt_next, auto, every, repeats, occur)'
                . ' VALUES (' . db_escape($orderNo) . ', ' . db_escape($c['dt_start']) . ', ' . self::sqlDate($c['dt_end'])
                . ', NULL, ' . $c['auto'] . ', ' . $c['every'] . ', ' . db_escape($c['repeats']) . ', '
                . db_escape($c['occur']) . ')',
                'could not add the recurring schedule'
            );

            return;
        }

        $rhythmChanged = $existing['dt_start'] !== $c['dt_start']
            || $existing['repeats'] !== $c['repeats']
            || (int) $existing['every'] !== $c['every']
            || (string) $existing['occur'] !== $c['occur'];
        db_query(
            'UPDATE ' . TB_PREF . 'sales_recurring SET dt_start = ' . db_escape($c['dt_start'])
            . ', dt_end = ' . self::sqlDate($c['dt_end'])
            . ', auto = ' . $c['auto'] . ', every = ' . $c['every']
            . ', repeats = ' . db_escape($c['repeats']) . ', occur = ' . db_escape($c['occur'])
            . ($rhythmChanged ? ', dt_next = NULL' : '')
            . ' WHERE trans_no = ' . db_escape($orderNo),
            'could not update the recurring schedule'
        );
    }

    /**
     * With its order, whether or not sgw_sales is still active: a deactivated
     * extension's rows would otherwise stay behind and reattach to a later order that
     * reuses the number.
     */
    public function delete(int $orderNo): void
    {
        if (!$this->isUpgraded()) {
            return;
        }
        db_query(
            'DELETE FROM ' . TB_PREF . 'sales_recurring WHERE trans_no = ' . db_escape($orderNo),
            'could not delete the recurring schedule'
        );
    }

    /**
     * End a schedule on $isoDate, unless it already ends earlier.
     */
    public function end(int $orderNo, string $isoDate): void
    {
        if (!$this->isUpgraded()) {
            return;
        }
        $date = db_escape(DateConversion::iso($isoDate));
        db_query(
            'UPDATE ' . TB_PREF . "sales_recurring SET dt_end = $date WHERE trans_no = " . db_escape($orderNo)
            . " AND (dt_end IS NULL OR dt_end > $date)",
            'could not end the recurring schedule'
        );
    }

    /**
     * A RecurrenceInput as sales_recurring columns, validated. repeats arrives as the
     * enum's value ('month' | 'year'), which is sgw_sales' own.
     *
     * @param array<string, mixed> $r
     * @return array{dt_start: string, dt_end: ?string, auto: int, every: int, repeats: string, occur: string}
     */
    public static function toColumns(array $r): array
    {
        if (!isset($r['start'])) {
            throw new BadInput('A schedule needs a start date.', 'recurring.start');
        }
        $start = DateConversion::iso($r['start'], 'recurring.start');
        $end = isset($r['end']) ? DateConversion::iso($r['end'], 'recurring.end') : null;
        if ($end !== null && $end < $start) {
            throw new BadInput('A schedule cannot end before it starts.', 'recurring.end');
        }
        $repeats = (string) ($r['repeats'] ?? '');
        if (!in_array($repeats, ['month', 'year'], true)) {
            throw new BadInput('A schedule repeats MONTH or YEAR.', 'recurring.repeats');
        }
        // every is tinyint(4) in sales_recurring.
        $every = (int) ($r['every'] ?? 0);
        if ($every < 1 || $every > 127) {
            throw new BadInput('every must be from 1 to 127.', 'recurring.every');
        }

        if ($repeats === 'month') {
            $day = $r['day'] ?? null;
            if ($day === null || (int) $day < 1 || (int) $day > 31) {
                throw new BadInput('A monthly schedule needs its day of the month, from 1 to 31.', 'recurring.day');
            }
            if (isset($r['monthDay'])) {
                throw new BadInput('monthDay is for a yearly schedule; a monthly one takes day.', 'recurring.monthDay');
            }
            $occur = (string) (int) $day;
        } else {
            $monthDay = (string) ($r['monthDay'] ?? '');
            // 2000 is a leap year: 02-29 is a valid yearly date.
            if (
                !preg_match('/^(\d{2})-(\d{2})\z/', $monthDay, $m)
                || !checkdate((int) $m[1], (int) $m[2], 2000)
            ) {
                throw new BadInput('A yearly schedule needs its date as MM-DD.', 'recurring.monthDay');
            }
            if (isset($r['day'])) {
                throw new BadInput('day is for a monthly schedule; a yearly one takes monthDay.', 'recurring.day');
            }
            $occur = $monthDay;
        }

        return [
            'dt_start' => $start,
            'dt_end' => $end,
            'auto' => ($r['auto'] ?? true) ? 1 : 0,
            'every' => $every,
            'repeats' => $repeats,
            'occur' => $occur,
        ];
    }

    /**
     * @param array<string, mixed> $row a sales_recurring row
     * @return array<string, mixed> a Recurrence
     */
    public static function fromRow(array $row): array
    {
        $monthly = $row['repeats'] === 'month';

        return [
            'start' => DateConversion::fromSql($row['dt_start']),
            'end' => DateConversion::fromSql($row['dt_end'] ?? null),
            'next' => DateConversion::fromSql($row['dt_next'] ?? null),
            'repeats' => $row['repeats'],
            'every' => (int) $row['every'],
            'day' => $monthly ? (int) $row['occur'] : null,
            'monthDay' => $monthly ? null : (string) $row['occur'],
            'auto' => (bool) $row['auto'],
        ];
    }

    private static function sqlDate(?string $date): string
    {
        return $date === null ? 'NULL' : db_escape($date);
    }

    /**
     * update_1.4.sql's shape: an AUTO_INCREMENT id and a unique trans_no (one schedule
     * per order). Asked once per request.
     */
    private function isUpgraded(): bool
    {
        if ($this->upgraded === null) {
            $table = db_escape(CompanyContext::prefix() . 'sales_recurring');
            $unique = db_fetch_row(db_query(
                'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()'
                . " AND TABLE_NAME = $table AND COLUMN_NAME = 'trans_no' AND NON_UNIQUE = 0",
                'could not inspect sales_recurring'
            ));
            $autoId = db_fetch_row(db_query(
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()'
                . " AND TABLE_NAME = $table AND COLUMN_NAME = 'id' AND EXTRA LIKE '%auto_increment%'",
                'could not inspect sales_recurring'
            ));
            $this->upgraded = (int) $unique[0] > 0 && (int) $autoId[0] > 0;
        }

        return $this->upgraded;
    }
}
```

```bash
docker/fa-graphql test --testsuite unit --filter RecurrenceColumnsTest
```

Expected: PASS (`toColumns`/`fromRow` touch no database; `DateConversion::iso`/`fromSql` are pure).

- [ ] **Step 4: The service uses it**

`src/Fa/Service/SalesOrderService.php`:

Add a property and constructor at the top of the class:

```php
    private RecurringSchedule $schedule;

    public function __construct(RecurringSchedule $schedule)
    {
        $this->schedule = $schedule;
    }
```

In `create()`, before `new \Cart(...)`:

```php
        if (self::given($input, 'recurring')) {
            // Refused before anything is written: sgw_sales absent, or a bad schedule.
            $this->schedule->assertWritable('recurring');
            RecurringSchedule::toColumns($input['recurring']);
        }
```

and after the `-1` check, before `return`:

```php
        if (self::given($input, 'recurring')) {
            $this->schedule->write((int) $orderNo, $input['recurring']);
        }
```

In `update()`, right after `FaIncludes::orders();`:

```php
        if (self::given($input, 'recurring')) {
            $this->schedule->assertWritable('recurring');
            RecurringSchedule::toColumns($input['recurring']);
        }
```

(that is, right after `FaIncludes::orders();`, before `lockVersion()`). Replace the four Task 8 hook bodies:

```php
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
```

- [ ] **Step 5: The GraphQL types and fields**

`src/Type/SalesOrder/RecurrenceRepeatsType.php`:

```php
<?php

namespace FA\GraphQL\Type\SalesOrder;

use GraphQL\Type\Definition\EnumType;

/**
 * How a recurring order repeats. The values are sgw_sales' own column values
 * (sales_recurring.repeats), so a parsed input needs no mapping.
 */
final class RecurrenceRepeatsType extends EnumType
{
    public function __construct()
    {
        parent::__construct([
            'name' => 'RecurrenceRepeats',
            'description' => 'How a recurring order repeats (sgw_sales).',
            'values' => [
                'MONTH' => ['value' => 'month'],
                'YEAR' => ['value' => 'year'],
            ],
        ]);
    }
}
```

`src/Type/SalesOrder/RecurrenceType.php`:

```php
<?php

namespace FA\GraphQL\Type\SalesOrder;

use Anorm\GraphQL\Type\DateType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * An order's recurring schedule (Release 2 spec section 4.5). Built by hand: it is
 * sgw_sales' table, nested in the order, not an entity of this API.
 */
final class RecurrenceType extends ObjectType
{
    public function __construct(RecurrenceRepeatsType $repeats)
    {
        parent::__construct([
            'name' => 'Recurrence',
            'description' => 'An order\'s recurring schedule (sgw_sales).',
            'fields' => [
                'start' => ['type' => Type::nonNull(DateType::instance())],
                'end' => ['type' => DateType::instance(), 'description' => 'None: it does not end.'],
                'next' => [
                    'type' => DateType::instance(),
                    'description' => 'The next invoice date, as sgw_sales has computed it; none until it has.',
                ],
                'repeats' => ['type' => Type::nonNull($repeats)],
                'every' => ['type' => Type::nonNull(Type::int()), 'description' => 'Every this many months or years.'],
                'day' => ['type' => Type::int(), 'description' => 'Monthly: the day of the month.'],
                'monthDay' => ['type' => Type::string(), 'description' => 'Yearly: the date, MM-DD.'],
                'auto' => ['type' => Type::nonNull(Type::boolean()), 'description' => 'Generated automatically.'],
            ],
        ]);
    }
}
```

`src/Type/SalesOrder/RecurrenceInputType.php`:

```php
<?php

namespace FA\GraphQL\Type\SalesOrder;

use Anorm\GraphQL\Type\DateType;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\Type;

/**
 * A whole recurring schedule; on an update it replaces the one there. To end one,
 * give it an end. Needs sgw_sales active for the company (Release 2 spec section 4.5).
 */
final class RecurrenceInputType extends InputObjectType
{
    public function __construct(RecurrenceRepeatsType $repeats)
    {
        parent::__construct([
            'name' => 'RecurrenceInput',
            'fields' => [
                'start' => ['type' => Type::nonNull(DateType::instance())],
                'end' => ['type' => DateType::instance()],
                'repeats' => ['type' => Type::nonNull($repeats)],
                'every' => ['type' => Type::nonNull(Type::int())],
                'day' => ['type' => Type::int(), 'description' => 'Monthly: 1 to 31.'],
                'monthDay' => ['type' => Type::string(), 'description' => 'Yearly: MM-DD.'],
                'auto' => ['type' => Type::boolean(), 'defaultValue' => true],
            ],
        ]);
    }
}
```

`src/Type/SalesOrder/SalesOrderCreateInput.php` — the constructor takes and stores the recurrence input too, and `fields()` appends it:

```php
    private RecurrenceInputType $recurrenceInput;

    public function __construct(SalesOrderLineCreateInput $lineInput, RecurrenceInputType $recurrenceInput)
    {
        // Before parent::__construct(), which builds the fields.
        $this->lineInput = $lineInput;
        $this->recurrenceInput = $recurrenceInput;
        parent::__construct();
    }
```

and, before `return $fields;`:

```php
        $fields[] = FieldBuilder::create('recurring', $this->recurrenceInput)
            ->setDescription('A recurring schedule. Needs the sgw_sales extension, active for the company.')
            ->build();
```

`src/Type/SalesOrder/SalesOrderUpdateInput.php` — the same two changes (`SalesOrderLineUpdateInput $lineInput, RecurrenceInputType $recurrenceInput`; the `recurring` field described "Set or replace the recurring schedule; to end it, give an end. Needs sgw_sales.").

`src/Type/SalesOrder/SalesOrderType.php` — add `use FA\GraphQL\Fa\Service\RecurringSchedule;`; the constructor takes `RecurrenceType $recurrenceType` after `$lineType` and stores it before `parent::__construct()`; `fields()` adds, after `lines`:

```php
            FieldBuilder::create('recurring', $this->recurrenceType)
                ->setDescription('The recurring schedule, when sgw_sales is active and the order has one.')
                ->setResolver(function (array $row, $args, $context): ?array {
                    return array_key_exists('recurring', $row)
                        ? $row['recurring']
                        : $context->get(RecurringSchedule::class)->read((int) $row['id']);
                })
                ->build(),
```

and in `resolveDelete()`'s snapshot loop, after `$row['lines'] = …`:

```php
            $row['recurring'] = $context->get(RecurringSchedule::class)->read($ids[$index]);
```

Update the constructions in `tests/Unit/Type/SalesOrderAreasTest.php` to `new SalesOrderType(new SalesOrderLineType(), new RecurrenceType(new RecurrenceRepeatsType()))`, and in `tests/Unit/Type/SalesOrderInputsTest.php` pass `new RecurrenceInputType(new RecurrenceRepeatsType())` as the Inputs' second argument; add to `SalesOrderInputsTest`:

```php
    public function testBothOrderInputsTakeARecurrence(): void
    {
        $recurrence = new RecurrenceInputType(new RecurrenceRepeatsType());
        $create = $this->fieldsOf(new SalesOrderCreateInput(new SalesOrderLineCreateInput(), $recurrence));
        $update = $this->fieldsOf(new SalesOrderUpdateInput(new SalesOrderLineUpdateInput(), $recurrence));

        $this->assertSame('RecurrenceInput', $create['recurring']);
        $this->assertSame('RecurrenceInput', $update['recurring']);
        $this->assertSame('Date!', $this->fieldsOf($recurrence)['start']);
    }
```

- [ ] **Step 6: Run the tests to see them pass**

```bash
docker/fa-graphql test --testsuite unit --filter 'RecurrenceColumnsTest|SalesOrderAreasTest|SalesOrderInputsTest'
docker/fa-graphql test --testsuite integration --filter 'SalesOrderRecurrenceTest|SalesOrderUpdateTest|SalesOrderDeleteTest|SalesOrderCreateTest'
```

Expected: PASS, none skipped (the stack has sgw_sales active and at 1.4). A skip of `SalesOrderRecurrenceTest` means the stack is not as the Foundation spec §9 says: fix the stack, not the test.

- [ ] **Step 7: Through the schema**

`tests/Generated/SalesOrderTypeTest.php` — add a test (it writes, so it follows the lifecycle's pattern and cleans up the same way):

```php
    public function testARecurringOrderThroughTheSchema(): void
    {
        if (!$this->container->get(\FA\GraphQL\Fa\Service\RecurringSchedule::class)->isAvailable()) {
            $this->markTestSkipped('sgw_sales is not active in this stack.');
        }
        $input = array_merge($this->sampleInput(), [
            'recurring' => ['start' => date('Y-m-d'), 'repeats' => 'MONTH', 'every' => 1, 'day' => 1],
        ]);
        $created = $this->run(
            'mutation ($input: [SalesOrderCreateInput!]!) { salesOrderCreate(input: $input) {'
            . ' id recurring { start repeats every day monthDay next auto } } }',
            ['input' => [$input]]
        )['salesOrderCreate'];
        $this->made[] = (int) $created[0]['id'];

        $this->assertSame(
            ['start' => date('Y-m-d'), 'repeats' => 'MONTH', 'every' => 1, 'day' => 1, 'monthDay' => null, 'next' => null, 'auto' => true],
            $created[0]['recurring']
        );
        \FA\GraphQL\Tests\Generated\SalesOrderTypeTest::purgeSchedule($this->container, (int) $created[0]['id']);
    }

    public static function purgeSchedule($container, int $orderNo): void
    {
        $container->get(\PDO::class)->prepare('DELETE FROM 0_sales_recurring WHERE trans_no = ?')->execute([$orderNo]);
    }
```

(and in `tearDown()`, call `self::purgeSchedule($this->container, $orderNo)` for each made order before the other deletes). In the lifecycle's read, also select `recurring { repeats }` and assert it is null for the plain order.

```bash
docker/fa-graphql test --testsuite integration --filter SalesOrderTypeTest
```

Expected: PASS.

- [ ] **Step 8: Run everything**

```bash
docker/fa-graphql test --testsuite unit
docker/fa-graphql test --testsuite integration
docker/fa-graphql test --testsuite http
```

Expected: PASS.

- [ ] **Step 9: Gates and commit**

```bash
docker/fa-graphql lint && docker/fa-graphql analyze
git add src/Fa/Service/RecurringSchedule.php src/Fa/Service/SalesOrderService.php src/Type/SalesOrder \
    tests/Unit/Fa/RecurrenceColumnsTest.php tests/Unit/Type/SalesOrderAreasTest.php tests/Unit/Type/SalesOrderInputsTest.php \
    tests/Integration/SalesOrder/SalesOrderRecurrenceTest.php tests/Generated/SalesOrderTypeTest.php
git commit -m "Recurring schedules on sales orders, in the order's transaction

With sgw_sales active, an order carries its sales_recurring schedule: written
with FrontAccounting's db_query() so it commits and rolls back with the order,
deleted with a deleted order, ended with a closed one. A recurring order keeps
sgw_sales' relaxations. Without sgw_sales, recurring is BAD_INPUT and reads null.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Checkpoint C — after Task 9 (sales orders and recurrence)

The document writes: the first code that creates, edits and cancels FrontAccounting documents, and the first that writes another extension's table. Reviewed once, here — not per task (the user's policy).

- [ ] **Independent review.** A reviewer who did not write Tasks 7–9 runs the equivalent of `/code-review medium` over the diff since Checkpoint B (`git diff <Checkpoint B's last commit>..HEAD`), reading the spec (`docs/superpowers/specs/2026-09-25-release-2-panel-design.md`) and the task reports. Correctness and security first:
  - **Every write goes through FrontAccounting.** `SalesOrderType` overrides `resolveCreate`, `resolveUpdate`, `resolveDelete`; `resolveUpsert` is still `FaModelType`'s `Forbidden`; `SalesOrderLineType` has no write. Nothing writes `sales_orders`, `sales_order_details` or `sales_recurring` through Anorm or the container PDO (`grep -rn "->write(\|prepare(\|exec(" src/Fa/Service src/Type/SalesOrder*` shows only reads and FrontAccounting calls).
  - **SQL.** Every value reaching `db_query()` is `db_escape()`d or cast to int; `TB_PREF` appears only inside SQL given to `db_query()`; `add_sales_order()`'s unquoted `unit_price`/`quantity`/`discount_percent` (`sales_order_db.inc:52-56`) only ever receive floats. IDs from clients pass `FaModelType::intId()`/`intIds()`.
  - **Transactions.** One `ServiceCall` per mutation call; a refusal anywhere in a batch rolls back every order and schedule of it, names the index, and leaves `$transaction_level` at 0 (`SalesOrderCreateTest::testARefusalMidBatchRollsTheWholeBatchBack`, `SalesOrderRecurrenceTest::testAScheduleRollsBackWithItsBatch`). The recurring row is written with `db_query()` on FrontAccounting's connection — the one-side rule holds without exception (spec §2.3).
  - **The version lock.** `SELECT … FOR UPDATE` inside the transaction, before the `Cart` is read; a stale version is `FA_REJECTED` and changes nothing.
  - **Rules ported, not invented.** Each check in `SalesOrderService` cites its FrontAccounting source line; compare a sample against upstream `sales/sales_order_entry.php` and `sales_order_ui.inc`. The two recurring relaxations apply only to orders with (or being given) a schedule.
  - **Errors.** `BAD_INPUT` with `field` (and `index` in a batch) for input problems; `NOT_FOUND` for an unknown order; `FA_REJECTED` for FrontAccounting's refusals, a stale version, a prepaid order that is open, a customer on hold, sgw_sales' table not upgraded; `FORBIDDEN` for `Guard` and for another user's order without `SA_EDITOTHERSTRANS` (spec §5).
  - **Read-back needs the list area.** `rowsById()` reads through `resolveList`, so a role with `SA_SALESORDER` but not `SA_SALESTRANSVIEW` would write an order and then be refused reading it back. Judge whether that is acceptable (the seeded role holds both) or whether the spec should say so.

  Fix confirmed findings (a fix round is one implementer dispatch plus a scoped re-review); commit as `Address Checkpoint C review`.

- [ ] **Spec walk — §4.4, §4.5, and §2.1/§3/§5 as they apply to orders.** For each requirement, name the test that proves it, or record the deviation in the spec marked *(revised)* with its reason:
  - §4.4 read side: `SalesOrder` scoped to `transType = 30`, key `id` = `order_no`; `lines` with `qtyDelivered`/`qtyInvoiced`; areas `SA_SALESTRANSVIEW` / `SA_SALESORDER` (`SalesOrderAreasTest`, `SalesOrderTypeTest`).
  - §4.4 create: document date explicit, never `new_doc_date()`; defaults from customer and branch; reference; price from the price list; kits expanded; every `can_process()`/`check_item_data()` rule (`SalesOrderCreateTest`).
  - §4.4 update: version required and checked; line replacement; delivered-line and frozen-header rules; recurring relaxations (`SalesOrderUpdateTest`, `SalesOrderRecurrenceTest`).
  - §4.4 delete: deleted versus closed; returns the orders as they were; no version check — confirm Task 8 wrote the *(revised)* note (`SalesOrderDeleteTest`).
  - §4.5: `recurring` in both Inputs and on the Type; `sales_recurring` mapping (`day`/`monthDay` ↔ `occur`); written in the order's transaction; deleted with a deleted order, ended with a closed one; `BAD_INPUT` without sgw_sales and null reads; `FA_REJECTED` for a table without its 1.4 shape — **this last has no automated test** (it would mean altering the stack's schema); confirm it by reading `RecurringSchedule::assertWritable()`/`isUpgraded()`, and say so in the checkpoint report.
  - Things to record in the spec if not already there: FrontAccounting 2.4 never writes `sales_orders.contact_email` (the Inputs drop `email`); on cash terms FrontAccounting ignores delivery details, and so does the API; `version` is `tinyint unsigned`, so FrontAccounting's own 256th update of one order fails; `next` is sgw_sales' state — kept unless the rhythm changes, never set from input.

- [ ] **Both FrontAccounting flavours.** `Cart` differs between them (`prepare_child()`: upstream dates a child with `new_doc_date()`, the fork with `Today()` — the tests' `deliver()` sets the date explicitly for that reason). Run the three suites on the main stack (upstream `master`), then on a throwaway fork stack, and destroy it:

```bash
docker/fa-graphql test
FA_REPO=https://github.com/cambell-prince/frontaccounting.git FA_REF=master-cp \
  COMPOSE_PROJECT_NAME=fa-graphql-fork HTTP_PORT=8105 DB_PORT=3325 PMA_PORT=8106 docker/fa-graphql up --build
FA_REPO=https://github.com/cambell-prince/frontaccounting.git FA_REF=master-cp \
  COMPOSE_PROJECT_NAME=fa-graphql-fork HTTP_PORT=8105 DB_PORT=3325 PMA_PORT=8106 docker/fa-graphql test
COMPOSE_PROJECT_NAME=fa-graphql-fork docker/fa-graphql destroy --yes
```

Expected: PASS on both, `SalesOrderRecurrenceTest` not skipped on either. Then check nothing leaked on the main stack: `docker/fa-graphql db shell` → `SELECT COUNT(*) FROM 0_sales_orders WHERE customer_ref LIKE 'batch-%' OR customer_ref LIKE 'recurring-%';` → `0`, and the count of `0_sales_orders` equals the dataset's own (`db load` fresh gives the baseline). Keep clear of ports 8110/3330/8111 (sgw_sales' own stack) and of every other container on the host.

- [ ] `docker/fa-graphql lint && docker/fa-graphql analyze` clean.

---

### Task 10: The panel flow over HTTP, and the README

The HTTP suite proves the panel's whole flow through Apache, as a client sees it. It covers the order-only role that reads lookups without FrontAccounting's setup areas. The README documents the new API surface. CI changes in content only: its `docker/fa-graphql ci` step already runs the `http` suite on all four jobs.

**Files:**
- Create: `tests/Http/PanelFlowTest.php`, `tests/Http/LookupsTest.php`
- Modify: `tests/data/seed.sql` (a `GraphQL Orders` role and the `apiorders` user), `README.md`, `docker/README.md`

**Interfaces:**
- Consumes (the generated schema of Tasks 4–9; names per the contract, and **the generated schema wins**, see Step 2):
  - lookups: `paymentTermsList`, `taxGroupList`, `salesAreaList`, `salesmanList`, `locationList`, `shipperList`, `creditStatusList`, `currencyList`, `stockItemList`, `salesTypeList`, each `(query: MangoInput): [<Entity>Type!]!`, with `id`;
  - `customerCreate(input: [CustomerCreateInput!]!)`, `customerUpdate(input: [CustomerUpdateInput!]!)`, `customerList`. `CustomerCreateInput` has `name`, `ref`, `address`, `salesTypeId`, `paymentTermsId`, `creditStatusId`, `branch: {salesmanId, salesAreaId, taxGroupId, locationCode, shipperId}` and `contact: {phone, email}`. `CustomerType` has `id`, `name`, `branches { id }` and `contacts { id }`;
  - `salesOrderCreate(input: [SalesOrderCreateInput!]!)`, `salesOrderUpdate(input: [SalesOrderUpdateInput!]!)`, `salesOrderDelete(id: [ID!]!)`, `salesOrderList`. Order fields: `id`, `version`, `customerId`, `branchId`, `orderDate`, `comments`; `lines { id stockId quantity unitPrice qtyDelivered }` (inputs: `stockId`, `quantity`, `unitPrice`, `description`, and `id` on update); `recurring { start end repeats every day }` (input `RecurrenceInput`: `start`, `repeats: MONTH|YEAR`, `every`, `day`);
  - `GraphQLClient` (`gql`, `login`), the stack's `FA_DB_*` environment (as `StackTest` uses), and `sgw_sales` active in company 0 (the stack default).
- Produces: the seed user `apiorders` / `password`, role `GraphQL Orders`. The role holds sections `3072` (`SS_SALES`) and `91136` (`SS_GRAPHQL`), and areas `3073` (`SA_SALESTRANSVIEW`), `3075` (`SA_SALESORDER`) and `91236` (`SA_GRAPHQL`), and nothing else. So it has no `SA_CUSTOMER` (`3074`) and no setup areas.
- Demo-data facts the tests rely on (`sql/en_US-demo.sql`):
  - sales type `1` Retail;
  - payment terms `1` "Due 15th Of the Following Month": neither cash-sale nor prepaid, so delivery details are required and supplied by the branch;
  - credit status `1` Good History: invoices allowed;
  - salesman `1`, area `1`, tax group `1`, location `DEF`, shipper `1`;
  - item `301` "Support" (a service, `mb_flag` `D`: no stock checks);
  - company currency `USD`, the customer's default, so no exchange rate is needed.

  FrontAccounting checks an area only when its section is also in the role (`current_user.inc`: `in_array($code & ~0xff, $role['sections'])`). That's why the role lists the sections.

- [ ] **Step 1: The order-only role and user** — append to `tests/data/seed.sql`:

```sql
-- A role that takes orders and nothing else: GraphQL access, the sales-order areas,
-- no SA_CUSTOMER and no setup areas. It proves the lookups need only SA_SALESORDER
-- (Release 2 spec §4.2). Sections must be listed too: FrontAccounting ignores an
-- area whose section the role lacks. 3072 = SS_SALES (12 << 8), 3073 = SA_SALESTRANSVIEW,
-- 3075 = SA_SALESORDER; 91136 / 91236 = SS_GRAPHQL / SA_GRAPHQL for extension 1.
INSERT INTO `0_security_roles` (`role`, `description`, `sections`, `areas`, `inactive`)
SELECT 'GraphQL Orders', 'GraphQL API access, sales orders only', '3072;91136', '3073;3075;91236', 0
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `0_security_roles` r WHERE r.`role` = 'GraphQL Orders');

-- md5('password')
INSERT INTO `0_users` (`user_id`, `password`, `real_name`, `role_id`, `email`, `language`)
SELECT 'apiorders', '5f4dcc3b5aa765d61d8327deb882cf99', 'API Orders', r.`id`, 'apiorders@example.com', 'C'
FROM `0_security_roles` r
WHERE r.`role` = 'GraphQL Orders'
  AND NOT EXISTS (SELECT 1 FROM `0_users` u WHERE u.`user_id` = 'apiorders');
```

Then reload the data. The seed is idempotent, and this drops rows left by earlier http runs:

```bash
docker/fa-graphql db load
```

Expected: the log ends with `applying tests/data/seed.sql`, and no error.

In `docker/README.md`, add a row to the seed-users table:

```markdown
| `apiorders` | `password` | `GraphQL Orders` (`SA_GRAPHQL`, `SA_SALESTRANSVIEW`, `SA_SALESORDER` only) |
```

- [ ] **Step 2: Align with the generated schema before writing the tests.** The names below follow the contract. Generation is the authority. Print the real input and output fields:

```bash
TOKEN=$(curl -s -H 'Content-Type: application/json' http://localhost:8100/modules/graphql/ \
  -d '{"query":"mutation { login(user: \"apitest\", password: \"password\") { accessToken } }"}' \
  | php -r 'echo json_decode(stream_get_contents(STDIN), true)["data"]["login"]["accessToken"];')
for T in CustomerCreateInput CustomerUpdateInput BranchDefaultsInput ContactDetailsInput \
         SalesOrderCreateInput SalesOrderUpdateInput SalesOrderLineCreateInput SalesOrderLineUpdateInput \
         RecurrenceInput CustomerType SalesOrderType SalesOrderLineType Recurrence; do
  curl -s -H 'Content-Type: application/json' -H "Authorization: Bearer $TOKEN" \
    http://localhost:8100/modules/graphql/ \
    -d "{\"query\":\"{ __type(name: \\\"$T\\\") { name inputFields { name type { kind name ofType { name } } } fields { name } } }\"}"
  echo
done
```

Wherever a name differs from Step 3's code (a field, an input type's name, `unitPrice` against `price`, or the recurring shape), change the test code to the generated name, and list each change in the report. Check one thing in particular: whether `salesOrderDelete` takes the order's `version`. The generated shape is `salesOrderDelete(id: [ID!]!)`, and Release 2 spec §4.4 says delete checks the version. Task 8 decided how, either by taking over the entry or by checking the version some other way; the test below uses the generated `id`-only shape. If Task 8 added a `version` argument, pass the order's current version in `testThePanelFlow` and `testADeliveredOrderIsClosedNotDeleted`.

- [ ] **Step 3: Write the tests**

`tests/Http/LookupsTest.php`:

```php
<?php

namespace FA\GraphQL\Tests\Http;

use PHPUnit\Framework\TestCase;

/**
 * The lookups an order needs are readable by a role that only takes orders:
 * SA_SALESORDER, not FrontAccounting's setup areas (Release 2 spec §4.2), and they
 * have no mutations.
 */
class LookupsTest extends TestCase
{
    use GraphQLClient;

    private const LOOKUPS = [
        'paymentTermsList', 'taxGroupList', 'salesAreaList', 'salesmanList', 'locationList',
        'shipperList', 'creditStatusList', 'currencyList', 'stockItemList', 'salesTypeList',
    ];

    private function code(array $response): ?string
    {
        return $response['body']['errors'][0]['extensions']['code'] ?? null;
    }

    public function testAnOrderRoleListsEveryLookup(): void
    {
        $token = $this->login('apiorders')['accessToken'];

        foreach (self::LOOKUPS as $list) {
            $response = $this->gql("{ $list { id } }", [], $token);

            $this->assertSame(200, $response['status'], $list);
            $this->assertArrayNotHasKey('errors', $response['body'], "$list: " . $response['raw']);
            $this->assertNotEmpty($response['body']['data'][$list], "$list is empty on the demo data");
        }
    }

    public function testAnOrderRoleCannotReadCustomers(): void
    {
        $response = $this->gql('{ customerList { id } }', [], $this->login('apiorders')['accessToken']);

        $this->assertSame(200, $response['status']);
        $this->assertSame('FORBIDDEN', $this->code($response), $response['raw']);
    }

    public function testLookupsHaveNoMutations(): void
    {
        $response = $this->gql('{ __schema { mutationType { fields { name } } } }', [], $this->login()['accessToken']);
        $names = array_column($response['body']['data']['__schema']['mutationType']['fields'], 'name');

        $lookup = '/^(paymentTerms|taxGroup|salesArea|salesman|location|shipper|creditStatus|currency'
            . '|stockItem|salesType|salesOrderLine)(Create|Update|Delete|Upsert)$/';
        $this->assertSame([], array_values(preg_grep($lookup, $names)));
        foreach (['customer', 'branch', 'contact', 'salesOrder'] as $entity) {
            foreach (['Create', 'Update', 'Delete'] as $verb) {
                $this->assertContains($entity . $verb, $names);
            }
            $this->assertNotContains($entity . 'Upsert', $names, 'create-update generation has no Upsert');
        }
    }
}
```

`tests/Http/PanelFlowTest.php`:

```php
<?php

namespace FA\GraphQL\Tests\Http;

use PHPUnit\Framework\TestCase;

/**
 * The hosting panel's flow end to end (Release 2 spec §7): a customer with its
 * branch and contact, an order with lines and a recurrence, read back, updated with
 * its version, a stale update refused, then deleted — or closed, once delivered.
 *
 * Rows it creates stay in the database; every run uses fresh references, and
 * `docker/fa-graphql db load` clears them.
 */
class PanelFlowTest extends TestCase
{
    use GraphQLClient;

    private const CUSTOMER_CREATE = 'mutation ($in: [CustomerCreateInput!]!) '
        . '{ customerCreate(input: $in) { id name branches { id } contacts { id } } }';

    private const ORDER_FIELDS = '{ id version customerId branchId orderDate comments '
        . 'lines { id stockId quantity unitPrice qtyDelivered } recurring { start end repeats every day } }';

    private const ORDER_CREATE = 'mutation ($in: [SalesOrderCreateInput!]!) { salesOrderCreate(input: $in) '
        . self::ORDER_FIELDS . ' }';

    private const ORDER_UPDATE = 'mutation ($in: [SalesOrderUpdateInput!]!) { salesOrderUpdate(input: $in) '
        . self::ORDER_FIELDS . ' }';

    private const ORDER_DELETE = 'mutation ($ids: [ID!]!) { salesOrderDelete(id: $ids) { id } }';

    private const ORDER_BY_ID = 'query ($q: MangoInput) { salesOrderList(query: $q) ' . self::ORDER_FIELDS . ' }';

    private string $token;

    protected function setUp(): void
    {
        $this->token = $this->login()['accessToken'];
    }

    private function code(array $response): ?string
    {
        return $response['body']['errors'][0]['extensions']['code'] ?? null;
    }

    /**
     * @return array<string, mixed> the response's data
     */
    private function ok(string $query, array $variables): array
    {
        $response = $this->gql($query, $variables, $this->token);
        $this->assertSame(200, $response['status'], $response['raw']);
        $this->assertArrayNotHasKey('errors', $response['body'], $response['raw']);

        return $response['body']['data'];
    }

    private function pdo(): \PDO
    {
        $pdo = new \PDO(
            'mysql:host=' . getenv('FA_DB_HOST') . ';dbname=' . getenv('FA_DB_NAME'),
            (string) getenv('FA_DB_USER'),
            (string) getenv('FA_DB_PASSWORD')
        );
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    private function table(string $name): string
    {
        return getenv('FA_DB_PREFIX') . $name;
    }

    private function today(): string
    {
        return gmdate('Y-m-d');
    }

    /**
     * @return array<string, mixed> the created customer
     */
    private function createCustomer(): array
    {
        $ref = 'PANEL-' . bin2hex(random_bytes(4));
        $data = $this->ok(self::CUSTOMER_CREATE, ['in' => [[
            'name' => "Panel customer $ref",
            'ref' => $ref,
            'address' => "1 Panel Street\nHosting Town",
            'salesTypeId' => '1',
            'paymentTermsId' => '1',
            'creditStatusId' => '1',
            'branch' => [
                'salesmanId' => '1', 'salesAreaId' => '1', 'taxGroupId' => '1',
                'locationCode' => 'DEF', 'shipperId' => '1',
            ],
            'contact' => ['phone' => '+60 3 1234 5678', 'email' => strtolower($ref) . '@example.com'],
        ]]]);

        $customer = $data['customerCreate'][0];
        $this->assertCount(1, $customer['branches'], 'auto_create_branch makes one branch');
        $this->assertNotEmpty($customer['contacts'], 'the page creates a CRM contact');

        return $customer;
    }

    /**
     * @return array<string, mixed> the created order
     */
    private function createRecurringOrder(array $customer): array
    {
        $data = $this->ok(self::ORDER_CREATE, ['in' => [[
            'customerId' => $customer['id'],
            'branchId' => $customer['branches'][0]['id'],
            'orderDate' => $this->today(),
            'comments' => 'hosting plan',
            'lines' => [['stockId' => '301', 'quantity' => 2, 'unitPrice' => 50, 'description' => 'Hosting']],
            'recurring' => ['start' => $this->today(), 'repeats' => 'MONTH', 'every' => 1, 'day' => 1],
        ]]]);

        return $data['salesOrderCreate'][0];
    }

    /**
     * @return array<int, array<string, mixed>> the orders with that id, as salesOrderList reads them
     */
    private function readOrder(string $id): array
    {
        return $this->ok(self::ORDER_BY_ID, ['q' => ['selector' => json_encode(['id' => (int) $id])]])['salesOrderList'];
    }

    public function testThePanelFlow(): void
    {
        $customer = $this->createCustomer();

        $renamed = $this->ok(
            'mutation ($in: [CustomerUpdateInput!]!) { customerUpdate(input: $in) { id name } }',
            ['in' => [['id' => $customer['id'], 'name' => $customer['name'] . ' (renamed)']]]
        );
        $this->assertSame($customer['name'] . ' (renamed)', $renamed['customerUpdate'][0]['name']);

        $order = $this->createRecurringOrder($customer);
        $this->assertSame($customer['id'], $order['customerId']);
        $this->assertSame($this->today(), $order['orderDate']);
        $this->assertCount(1, $order['lines']);
        $this->assertSame('301', $order['lines'][0]['stockId']);
        $this->assertEquals(2, $order['lines'][0]['quantity']);
        $this->assertEquals(50, $order['lines'][0]['unitPrice']);
        $this->assertSame(
            ['start' => $this->today(), 'end' => null, 'repeats' => 'MONTH', 'every' => 1, 'day' => 1],
            $order['recurring']
        );

        // Read back: the list sees exactly what the create returned.
        $this->assertSame([$order], $this->readOrder($order['id']));

        // Update with the version just read: quantity 2 -> 3, and a new comment.
        $updated = $this->ok(self::ORDER_UPDATE, ['in' => [[
            'id' => $order['id'],
            'version' => $order['version'],
            'comments' => 'hosting plan, three seats',
            'lines' => [[
                'id' => $order['lines'][0]['id'], 'stockId' => '301', 'quantity' => 3, 'unitPrice' => 50,
            ]],
        ]]])['salesOrderUpdate'][0];
        $this->assertGreaterThan($order['version'], $updated['version']);
        $this->assertEquals(3, $updated['lines'][0]['quantity']);
        $this->assertSame('hosting plan, three seats', $updated['comments']);

        // The same update again, with the version that is now stale: refused, nothing changed.
        $stale = $this->gql(self::ORDER_UPDATE, ['in' => [[
            'id' => $order['id'],
            'version' => $order['version'],
            'lines' => [['id' => $order['lines'][0]['id'], 'stockId' => '301', 'quantity' => 9, 'unitPrice' => 50]],
        ]]], $this->token);
        $this->assertSame('FA_REJECTED', $this->code($stale), $stale['raw']);
        $this->assertSame([$updated], $this->readOrder($order['id']));

        // Delete an order with no deliveries: it is gone, and so is its schedule.
        $this->ok(self::ORDER_DELETE, ['ids' => [$order['id']]]);
        $this->assertSame([], $this->readOrder($order['id']));
        $schedules = $this->pdo()->prepare('SELECT COUNT(*) FROM ' . $this->table('sales_recurring') . ' WHERE trans_no = ?');
        $schedules->execute([(int) $order['id']]);
        $this->assertSame(0, (int) $schedules->fetchColumn(), 'the recurring row goes with its order');
    }

    public function testADeliveredOrderIsClosedNotDeleted(): void
    {
        $order = $this->createRecurringOrder($this->createCustomer());

        // Release 2 has no deliveries; mark one delivered as a delivery note would
        // (sales_order_has_deliveries() reads qty_sent).
        $this->pdo()->prepare(
            'UPDATE ' . $this->table('sales_order_details') . ' SET qty_sent = 1 WHERE order_no = ? AND trans_type = 30'
        )->execute([(int) $order['id']]);

        $this->ok(self::ORDER_DELETE, ['ids' => [$order['id']]]);

        $closed = $this->readOrder($order['id']);
        $this->assertCount(1, $closed, 'a delivered order is closed, not deleted');
        $this->assertEquals(1, $closed[0]['lines'][0]['quantity'], 'closing sets the quantity to what was delivered');
        $this->assertEquals(1, $closed[0]['lines'][0]['qtyDelivered']);
        $this->assertSame($this->today(), $closed[0]['recurring']['end'], 'closing ends the schedule today');
    }

    public function testAStaleOrderVersionCannotDeleteEither(): void
    {
        $order = $this->createRecurringOrder($this->createCustomer());
        $this->ok(self::ORDER_UPDATE, ['in' => [['id' => $order['id'], 'version' => $order['version'], 'comments' => 'moved on']]]);

        // Only meaningful when salesOrderDelete takes a version (Step 2). Without one,
        // this test asserts the id-only delete still works on the updated order.
        $deleted = $this->ok(self::ORDER_DELETE, ['ids' => [$order['id']]]);
        $this->assertSame($order['id'], $deleted['salesOrderDelete'][0]['id']);
    }
}
```

If Step 2 found that `salesOrderDelete` takes `version`, replace `testAStaleOrderVersionCannotDeleteEither`'s body with a delete carrying `$order['version']` (now stale). Assert `FA_REJECTED`, then assert that `readOrder()` still returns the order.

- [ ] **Step 4: Run them**

```bash
docker/fa-graphql test --testsuite http --filter 'LookupsTest|PanelFlowTest'
```

Expected: PASS. These tests cover behaviour Tasks 3–9 already built, so there is no red phase of their own. A failure is a defect in the task that owns the behaviour, or a name Step 2 missed. Fix it where it belongs (the service, the Type, the seed), not by weakening the assertion. Then run the whole suite:

```bash
docker/fa-graphql test
```

Expected: PASS (every suite).

- [ ] **Step 5: README** — add after the Mango example in "Calling the API", before "Generating Types". Use the generated names Step 2 confirmed:

````markdown
### Reference lookups

Read-only lists of what an order needs, for any role holding **Sales orders
edition** (`SA_SALESORDER`). FrontAccounting's setup areas are not needed:
`paymentTermsList`, `taxGroupList`, `salesAreaList`, `salesmanList`,
`locationList`, `shipperList`, `creditStatusList`, `currencyList`,
`stockItemList`, `salesTypeList`. Each takes an optional Mango `query`. A sellable
item is one with `mbFlag` not `F`, and neither `inactive` nor `noSale`.

### Customers, branches and contacts

`customerCreate`, `customerUpdate`, `customerDelete`; `branchCreate`, …;
`contactCreate`, … (role area **Sales customer and branches changes**,
`SA_CUSTOMER`). Every mutation takes a list and runs it in one transaction:
either the whole list is written or none of it, and a refusal names the item in
`extensions.index`. Create inputs have no `id`; update inputs require it, and
fields you leave out are left unchanged.

`customerCreate` does what FrontAccounting's customer page does. With the
company's *auto create branch* setting on (the default), it needs `branch`
defaults and creates the customer's first branch. `contact` creates the CRM
contact linked to the customer and that branch. Percentages are 0–100.

### Sales orders

`salesOrderCreate`, `salesOrderUpdate`, `salesOrderDelete`, `salesOrderList`
(**Sales orders edition** to write, **Sales transactions view** to read). An
order takes its customer's and branch's defaults (price list, payment terms,
delivery address, location, shipper) unless you give them. A line's price
defaults to the price list's. Dates are `YYYY-MM-DD`.

- **Updates carry `version`**, the one you last read. If the order changed since,
  the update is refused (`FA_REJECTED`): read it again and retry. `lines`, when
  given, replaces the order's lines. A line with an `id` is updated, one without
  is added, and one left out is removed. A delivered line can't be removed or
  reduced below what was delivered. Once anything is delivered or invoiced, the
  customer, branch, price list, date and payment terms are fixed.
- **`salesOrderDelete` is FrontAccounting's *cancel order*.** An order with no
  deliveries is deleted. One with deliveries is closed instead: its quantities
  are cut to what was delivered, and it stays readable.
- FrontAccounting's warnings (for example, a price below cost) don't fail the
  mutation. They arrive in the response's top-level `extensions.warnings`.

### Recurring orders

With the `sgw_sales` extension active, an order can recur:

```graphql
recurring: { start: "2026-10-01", repeats: MONTH, every: 1, day: 1 }
```

`repeats` is `MONTH` (with `day`, 1–31) or `YEAR` (with `monthDay`, `"MM-DD"`).
To end a schedule, update the order with `recurring: { …, end: "2027-09-30" }`.
The schedule is written in the same transaction as its order: deleting the order
deletes it, and closing the order ends it. Without `sgw_sales`, `recurring` is
refused (`BAD_INPUT`) and always reads `null`. Generating the recurring invoices
comes in a later release.

### The panel's flow

```php
[, $body] = $gql(
    'mutation ($in: [CustomerCreateInput!]!) { customerCreate(input: $in) { id branches { id } } }',
    ['in' => [[
        'name' => 'Example Sdn Bhd', 'ref' => 'EXAMPLE', 'address' => "1 Jalan Contoh\nKuala Lumpur",
        'salesTypeId' => 1, 'paymentTermsId' => 1, 'creditStatusId' => 1,
        'branch' => ['salesmanId' => 1, 'salesAreaId' => 1, 'taxGroupId' => 1, 'locationCode' => 'DEF', 'shipperId' => 1],
        'contact' => ['email' => 'billing@example.com'],
    ]]],
    $pair['accessToken']
);
$customer = $body['data']['customerCreate'][0];

[, $body] = $gql(
    'mutation ($in: [SalesOrderCreateInput!]!) { salesOrderCreate(input: $in) { id version } }',
    ['in' => [[
        'customerId' => $customer['id'], 'branchId' => $customer['branches'][0]['id'],
        'orderDate' => date('Y-m-d'),
        'lines' => [['stockId' => 'HOSTING-M', 'quantity' => 1]],
        'recurring' => ['start' => date('Y-m-d'), 'repeats' => 'MONTH', 'every' => 1, 'day' => 1],
    ]]],
    $pair['accessToken']
);
$order = $body['data']['salesOrderCreate'][0];   // keep $order['version'] for updates
```
````

Also update the README's "Status" paragraph: "Release 2: lookups, customers, branches, contacts and sales orders with recurring schedules. Generating and sending recurring invoices is Release 3."

- [ ] **Step 6: Gates and commit**

```bash
docker/fa-graphql lint && docker/fa-graphql analyze && docker/fa-graphql test
git add tests/Http/LookupsTest.php tests/Http/PanelFlowTest.php tests/data/seed.sql README.md docker/README.md
git commit -m "Cover the panel's flow over HTTP; document lookups, customers and orders

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

Expected: every gate green. `.github/workflows/ci.yml` needs no change: its `docker/fa-graphql ci` step loads the seed and runs all three suites on each of the four jobs.

---

### Checkpoint D — final

- [ ] **Independent review of the whole branch.** Build a review package over `main..feature/release-2` and dispatch a reviewer on the **most capable model**, with:
  - the package;
  - both specs;
  - this plan's Global Constraints and Review Focus;
  - every earlier checkpoint's review and fix report;
  - the ledger's deferred Minors and rulings, for triage.

  `anorm-graphql`'s own diff was reviewed at Checkpoint A and is released as `v0.2.0`; don't review it again here, but do check the module uses it as released. The review focuses on:
  - FrontAccounting write paths;
  - transaction boundaries;
  - the fail-closed writes;
  - company isolation;
  - date conversion;
  - the version check;
  - the recurring row's lifecycle.

  Fix confirmed findings in one fix round, re-review the fix diff, and commit as `Address final review`.

- [ ] **Spec compliance: walk the whole Release 2 spec.** For each requirement, name the test that proves it, or record the deviation in the spec marked *(revised)*, with the reason. Pay particular attention to these:
  - **§1 scope:**
    - All four deliverables are present.
    - No invoice generation or email: `grep -rn "rep107\|RecurringInvoiceService\|generate(" src` shows nothing that generates or sends.
  - **§2 architecture:**
    - Every entity is a model in `src/Model` with generated Types.
    - `bin/generate --dry-run` on a clean tree reports nothing to change.
    - The lookups and `SalesOrderLine` are `--readonly`, `SalesOrderLine` is also `--input-only`, and the writable entities use `--mutations create-update`.
  - **§2.1 services:**
    - Each service calls only the FrontAccounting functions its row lists.
    - Page validations are ported one for one, each with a test, as the service tests' names show.
    - Numeric fields are cast.
    - Dates go through `DateConversion`.
  - **§2.2 fail-closed writes:**
    - Every writable Type overrides `resolveCreate`, `resolveUpdate` and `resolveDelete`.
    - `FaModelTypeTest` proves an unrouted write is `FORBIDDEN`.
    - `grep -rn "function resolveUpsert\|->write(" src/Type` finds no direct model write.
  - **§2.3 one side:**
    - `grep -rn "db_query\|begin_transaction" src` lists only:
      - `src/Fa/FaTransaction.php`;
      - `src/Fa/Service/*`;
      - `src/Fa/fa_errors_compat.php`'s rollback.
    - No service writes through the container's `\PDO`.
  - **§3.1 `FaTransaction`:**
    - `cancel_transaction()` runs on every throwable.
    - Review Focus 1's tests are present and green.
  - **§3.2 messages:**
    - Errors become `FA_REJECTED` with `extensions.messages`.
    - Warnings appear in the top-level `extensions.warnings`.
    - Notices are discarded.
    - `FaMessages` keeps levels.
  - **§3.3 `sgw_sales` detection:**
    - `FaSession::isActive('sgw_sales')` is used.
    - A missing or un-upgraded `sales_recurring` is `FA_REJECTED`, naming `update_1.4.sql`.
  - **§4.1 conventions:**
    - `SchemaPrinter` output has `<entity>Create/Update/Delete/List` for the four writable entities, no `<entity>Upsert`, and `List` only for the lookups.
    - Every key property is `id`.
    - Dates are `Date`.
  - **§4.2 lookups:**
    - Each table, key and area in the spec's table is covered (`LookupsTest` with `apiorders`).
    - `SalesType` is on `SA_SALESORDER`.
  - **§4.3 customers, branches and contacts:**
    - Each create, update and delete rule and guard in the spec.
    - `auto_create_branch` both ways.
    - The `branches`, `contacts` and `links` fields.
  - **§4.4 sales orders:**
    - Defaults, price defaults, kit expansion and reference.
    - The version lock (Review Focus 3).
    - The line-replacement rules and the frozen header.
    - The recurring relaxations.
    - Delete versus close.
  - **§4.5 recurrence:**
    - The mapping to `sales_recurring`.
    - One transaction with the order.
    - Deleted with a deleted order, ended with a closed one (Review Focus 4).
    - `BAD_INPUT` without `sgw_sales`, and the schema the same either way.
  - **§5 errors:** each row of the table, with the class that throws it.
  - **§6 `anorm-graphql` 0.2:**
    - `composer.lock` has `saygoweb/anorm-graphql` at `v0.2.x` from the `vcs` source: `docker/fa-graphql composer show saygoweb/anorm-graphql`, and `grep -n '"type": "path"' composer.json composer.lock` is empty.
    - `saygoweb/anorm` is at 3.2.1 or later.
  - **§7 testing:**
    - Every bullet has its tests, including the Review Focus tests.
    - Fiscal years reach the current year after `db load`.
  - **Foundation spec invariants still hold:**
    - every body is JSON (the output capture untouched);
    - one company per request;
    - `Guard` on every resolver but the three anonymous ones;
    - the upstream `session_utils` path.

- [ ] Every deviation found is either fixed or written into the spec, marked *(revised)*, with the reason.

- [ ] **All four combinations green locally.** On the main stack, which runs upstream `master` on PHP 7.4:

```bash
docker/fa-graphql ci
```

Then run three throwaway stacks, one at a time. They use the same ports, and the ports avoid `sgw_sales`' 8110/3330/8111. Destroy each one, and never touch other containers:

```bash
PHP_VERSION=8.3 COMPOSE_PROJECT_NAME=fa-graphql-83 HTTP_PORT=8105 DB_PORT=3325 PMA_PORT=8106 \
  docker/fa-graphql ci && \
  PHP_VERSION=8.3 COMPOSE_PROJECT_NAME=fa-graphql-83 HTTP_PORT=8105 DB_PORT=3325 PMA_PORT=8106 \
  docker/fa-graphql destroy --yes

FA_REPO=https://github.com/cambell-prince/frontaccounting.git FA_REF=master-cp \
  COMPOSE_PROJECT_NAME=fa-graphql-fork HTTP_PORT=8105 DB_PORT=3325 PMA_PORT=8106 \
  docker/fa-graphql ci && \
  COMPOSE_PROJECT_NAME=fa-graphql-fork HTTP_PORT=8105 DB_PORT=3325 PMA_PORT=8106 \
  docker/fa-graphql destroy --yes

FA_REPO=https://github.com/cambell-prince/frontaccounting.git FA_REF=master-cp PHP_VERSION=8.3 \
  COMPOSE_PROJECT_NAME=fa-graphql-fork-83 HTTP_PORT=8105 DB_PORT=3325 PMA_PORT=8106 \
  docker/fa-graphql ci && \
  COMPOSE_PROJECT_NAME=fa-graphql-fork-83 HTTP_PORT=8105 DB_PORT=3325 PMA_PORT=8106 \
  docker/fa-graphql destroy --yes
```

Expected for each: lint and PSR-12 clean, PHPStan `[OK] No errors`, all three suites `OK`. On upstream the only skip allowed is `CompatDriftTest`'s fork-file check, which is documented. The `Cart` difference between upstream and the fork (`prepare_child` dates) doesn't touch sales-order create, update or delete. If an order test differs between the two, that's a finding.

- [ ] GitHub Actions: the four jobs green on the branch's last push. Pushing needs the user's OK. If no push has been approved yet, report the local results and ask.

- [ ] Hand off with superpowers:finishing-a-development-branch. Tell the user what **Release 3** inherits:
  - **Generating recurring invoices.** Use `sgw_sales`' `RecurringInvoiceService::generate($orderNo, email: false)`, gated on `due()` (it invoices an order that isn't due yet).
    - It writes `dt_next` on `sgw_sales`' own PDO, so it is the one sanctioned exception to the one-side rule: never wrap it in a transaction of ours.
    - Its `due()` join doesn't filter `trans_type`.
    - Real installs need `update_1.4.sql` applied by hand, because `activate_extension` runs only `update_1.0.sql`.
    - It is untested under this module's `session.inc`-free boot: that is its first integration test.
  - **Emailing invoices needs its own design.**
    - `rep107.php` can't be included headless. It includes `session.inc`, and on upstream its functions would redeclare `fa_session_compat.php`'s; upstream also uses a relative `$path_to_root`.
    - The options are a CLI or sub-process report run, a service sub-request to FrontAccounting, or FrontAccounting's reporting classes driven directly.
    - FrontAccounting's email failures ("no email contact defined") arrive as messages, not exceptions. Return them.
  - **Invoice and delivery dates.** Upstream dates a child delivery or invoice with `new_doc_date()` and the fork with `Today()`, so set them explicitly.
  - **Invoices, deliveries, customer payments and allocations**, with `$_SESSION['App']` if `Cart`'s delivery or invoice paths need it.
  - **Quotations**, not in Release 2.
  - **Deferred Minors from the ledger:**
    - flushing the `OutputCapture` buffer would leak what it captured;
    - an `anorm-graphql` option given with no value falls back to its default silently;
    - anything Checkpoints B to D parked.
  - **`anorm-graphql` `1.0.0`** once this module stops needing it to change.
