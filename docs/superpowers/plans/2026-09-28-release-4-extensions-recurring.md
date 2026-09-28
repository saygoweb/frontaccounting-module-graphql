# Release 4 (Extensions and recurring invoices) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Other FrontAccounting extensions can add to this GraphQL API through a code-first extension contract discovered per company with FrontAccounting's hooks; `sgw_sales` becomes the pilot extension, taking over Release 2's recurrence unchanged for clients, and adds recurring invoice generation built on a hardened `RecurringInvoiceService` shared with its own page.

**Architecture:** This module defines `FA\GraphQL\Extension\` — an `Extension` interface, an `ExtensionRegistry` filled by calling each active FrontAccounting extension's `graphql_extensions` hook method after a company is opened, an `ExtensionLoader` that checks contributions and drops a bad extension with a log line, `LoadedExtensions` that the extensible core types (`SalesOrderType`, `SalesOrderCreateInput`, `SalesOrderUpdateInput`) append from, a `SchemaAssembler` that adds extension root fields around the generated `ApiSchema` without touching its literal `fields` arrays, and `SalesOrderParticipant`s that `SalesOrderService` calls inside the order's `FaTransaction`. `sgw_sales` ships `SGW_Sales\GraphQL\` in its own repository — `SgwSalesExtension`, a `RecurrenceParticipant` (port of this module's `RecurringSchedule`), the recurrence Types, and later `recurringDueList` / `recurringGenerate` over its hardened `RecurringInvoiceService`, emailing through this module's `InvoiceMailer`.

**Tech Stack:** PHP ^7.4 (CI also 8.3); webonyx/graphql-php ^15.32.3; php-di ^6; saygoweb/anorm ^3.2.1; saygoweb/anorm-graphql ^0.3 (this module only); Slim 4; FrontAccounting 2.4 (upstream `master`, fork `master-cp` optional); PHPUnit 9.6, phpcs PSR-12, PHPStan level 5; Docker (`docker/fa-graphql`, and `sgw_sales`' `docker/fa-sgw-sales`).

**Spec:** `docs/superpowers/specs/2026-09-28-release-4-extensions-recurring-design.md` — read it first, with the Release 2 spec §4.4–4.5 (recurrence as it is today) and the Release 3 spec §6 (`bin/fa-report`, `InvoiceMailer`).

## Global Constraints

- Two repositories. **graphql:** `/home/cambell/src/sgw/frontaccounting/modules/graphql`, branch `feature/release-4` (from `main` f27f275). **sgw_sales:** `/home/cambell/src/sgw/frontaccounting/modules/sgw_sales`, branch `feature/graphql-extension` (from `master` 31eb202). File paths in this plan are prefixed `graphql:` or `sgw_sales:`.
- PHP `^7.4 || ^8.0`: no enums, readonly, constructor promotion, match, named arguments, union types. Typed properties and arrow functions are fine.
- `webonyx/graphql-php ^15.32.3`; `saygoweb/anorm ^3.2.1` (both repos, never lowered); `saygoweb/anorm-graphql ^0.3` in graphql only. **sgw_sales gains no runtime composer dependency on graphql or anorm-graphql**: the contract classes come from graphql's autoloader in the same FrontAccounting process, and every use is guarded by `interface_exists(\FA\GraphQL\Extension\Extension::class)`.
- The contract version is `1.0` (`ExtensionRegistry::CONTRACT_VERSION`); an extension of another major version is refused.
- FrontAccounting's `hook_invoke_all($method, &$data, $opts = null)` (upstream `includes/hooks.inc:294`) calls `$hook->$method($data, $opts)` after `set_ext_domain($hook->path)`. The hook method is `graphql_extensions(&$registry, $opts = null)`.
- Extensible core types (spec §2.5): `SalesOrderType`, `SalesOrderCreateInput`, `SalesOrderUpdateInput`. Nothing else.
- Every write goes through FrontAccounting's functions inside one `FaTransaction` (via `ServiceCall`); document writes are wrapped by `DocumentLock` (lock → transaction → commit → unlock). Participants run inside the order's transaction; a participant's exception fails the mutation.
- `recurringGenerate`'s items are independent (spec §4.2): each item is its own `DocumentLock` + `ServiceCall`; an item's failure is reported in its result and the rest continue.
- One company per request; every response JSON; upstream FrontAccounting `master` is the stack default, the fork is optional; CI runs {upstream, fork} × {PHP 7.4, 8.3}.
- Clients see no change to `recurring` (Release 2 spec §4.5): same field names, types, nullability, behaviour — pinned by a schema snapshot in sgw_sales. The one deliberate change: with `sgw_sales` inactive for a company, `recurring` is absent from that company's schema.
- `ApiSchema.php`'s `fields` arrays stay literal arrays the anorm-graphql editor recognises: `bin/generate --dry-run` reports only `current`/`kept` after every task that touches the schema.
- **SGW_SALES_REF pinning ruling:** the stack's Dockerfile clones `sgw_sales` with `git clone --depth 1 --branch "$SGW_SALES_REF"`, which takes a branch or tag, never a commit SHA. While the sgw_sales PR is open, graphql's CI and stack pin `SGW_SALES_REF=feature/graphql-extension`; after that PR merges it goes back to `master` (spec §6 merge order).
- Integration tests boot FrontAccounting in separate processes (`@runTestsInSeparateProcesses`, `@preserveGlobalState disabled`) and leave every table at its starting row count.
- Nothing is pushed until Checkpoint D. Commit messages end with exactly:
  ```
  Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_013U8a6JAbsHc4c1KcSLiaYK
  ```

## Review policy (from the user)

No per-task reviews. An independent reviewer runs only at the four Checkpoints; each is `/code-review medium`-equivalent over the diff since the previous checkpoint, a walk of the listed spec sections, fixes for Critical/Important findings with one scoped re-review, and green suites.

- **Checkpoint A — after Task 1:** the contract is what another repository will depend on; it must be right before sgw_sales builds on it.
- **Checkpoint B — after Task 3:** recurrence has changed hands between repositories; the panel's flow must be unchanged before generation is built on top.
- **Checkpoint C — after Task 5:** generation writes money documents; double billing, validation gaps and emailing are reviewed before the end-to-end flow.
- **Checkpoint D — after Task 6:** both repositories, the whole spec, all four stack combinations; then push both branches and open the PRs.

## Review Focus

The five failure modes most likely to bite, each pinned by a test in the task named:

1. **An extension throwing — in its hook, its `contractVersion()` or any contribution method — takes the whole API down.** Expected: that extension is dropped and logged; every other query still answers. (Task 1: `ExtensionLoaderTest::testAnExtensionThrowingWhileContributingIsDroppedAndTheRestLoad`, `ExtensionDiscoveryTest::testAHookThatThrowsIsLoggedAndOthersStillRegister`.)
2. **A participant's exception leaves the order written.** Expected: the whole mutation rolls back — no `sales_orders` row, no lines, no reference used. (Task 1: `SalesOrderParticipantTest::testAParticipantFailingAfterCreateRollsTheOrderBack`.)
3. **Extension fields leak to a company where the extension is inactive.** Expected: the contributions exist only when the extension's hook object is installed for the request's company. (Task 1: `ExtensionDiscoveryTest::testAnExtensionContributesOnlyWhereItsHookIsInstalled`; Task 2: sgw_sales `RecurrenceSchemaTest::testRecurringIsAbsentWhereSgwSalesIsInactive`.)
4. **`recurringGenerate` bills twice on retry.** Expected: the second call for the same order and date finds it not due and reports an error; exactly one invoice exists. (Task 5: sgw_sales `RecurringGenerateTest::testARetriedGenerationBillsOnce`.)
5. **The schema stops being editable by anorm-graphql** (extension merging rewrites `ApiSchema`'s literals). Expected: `bin/generate --dry-run` reports only `current`/`kept`. (Task 1 Step 9 and Task 3's gates run it.)

## File map

```
graphql:
  src/Extension/Extension.php                  interface (spec §2.2)
  src/Extension/AbstractExtension.php          defaults: [] everywhere, contract 1.0
  src/Extension/SalesOrderParticipant.php      interface (spec §2.4)
  src/Extension/ExtensionRegistry.php          HOOK, CONTRACT_VERSION, register(), all()
  src/Extension/ExtensionContext.php           what an extension may use (spec §2.3)
  src/Extension/ExtensionLoader.php            the rules (spec §2.5)
  src/Extension/LoadedExtensions.php           what survived: root fields, appendTo(), participants
  src/Extension/Extensions.php                 per-request service: discovery through $Hooks, cached
  src/Extension/SchemaAssembler.php            core ApiSchema + extension root fields
  container.php                                Schema binding via SchemaAssembler; Extensions wiring
  src/Fa/Service/SalesOrderService.php         participants called (Task 1); own recurrence removed (Task 3)
  src/Type/SalesOrder/SalesOrderType.php, SalesOrderCreateInput.php, SalesOrderUpdateInput.php
                                               append extension contributions (Task 1); recurring removed (Task 3)
  src/Fa/Service/RecurringSchedule.php,
  src/Type/SalesOrder/Recurrence*Type.php      deleted (Task 3)
  bin/fa-report                                installs the target company's hooks before login (Task 5)
  docker/fa-graphql, docker/Dockerfile, docker/docker-compose*.yml, .github/workflows/ci.yml
                                               test-extension command; SGW_SALES_REF pin; CI step (Task 3)
  tests/Support/Extension/FakeExtension.php, FakeHooks.php, RecordingParticipant.php
  tests/Unit/Extension/*, tests/Integration/Extension/*
  tests/Http/RecurringBillingFlowTest.php      (Task 6)
  docs/superpowers/specs/2026-09-28-release-4-extensions-recurring-design.md  (revised notes)
  README.md, ROADMAP-2026-09.md                (Task 6)

sgw_sales:
  hooks.php                                    graphql_extensions(); activate_extension applies update_1.4.sql
  includes/GraphQL/SgwSalesExtension.php
  includes/GraphQL/RecurrenceParticipant.php
  includes/GraphQL/Type/RecurrenceType.php, RecurrenceInputType.php, RecurrenceRepeatsType.php
  includes/GraphQL/Type/RecurringDueType.php, RecurringGenerateInputType.php,
                        RecurringGenerateResultType.php, GenerateErrorType.php   (Task 5)
  includes/service/RecurringInvoiceService.php  hardened (Task 4)
  includes/service/RecurrenceNotDue.php, GenerationRefused.php                   (Task 4)
  includes/db/GenerateRecurringModel.php       trans_type = 30 (Task 4)
  includes/controller/*, generate_recurring_invoices.php                          page/cron adapted (Task 4)
  tests/GraphQL/*, phpunit-graphql.xml         run by graphql's `docker/fa-graphql test-extension sgw_sales`
  tests/…                                      service tests on sgw_sales' own stack (Task 4)
  README.md                                    GraphQL section (Task 6)
```

---

### Task 1: The extension contract (graphql)

Spec §2. The interfaces, discovery through FrontAccounting's hooks per company, the loader and its rules, schema assembly around the generated `ApiSchema`, contributions to the extensible sales order Type and inputs, and `SalesOrderParticipant`s called by `SalesOrderService` inside the order's transaction. This module's own `RecurringSchedule` keeps running unchanged in this task (Task 3 removes it); it is **not** a participant.

**Files:**
- Create: `graphql:src/Extension/Extension.php`, `AbstractExtension.php`, `SalesOrderParticipant.php`, `ExtensionRegistry.php`, `ExtensionContext.php`, `ExtensionLoader.php`, `LoadedExtensions.php`, `Extensions.php`, `SchemaAssembler.php`
- Create: `graphql:tests/Support/Extension/FakeExtension.php`, `FakeHooks.php`, `RecordingParticipant.php`
- Create: `graphql:tests/Unit/Extension/ExtensionLoaderTest.php`, `LoadedExtensionsTest.php`, `SchemaAssemblerTest.php`
- Create: `graphql:tests/Integration/Extension/ExtensionDiscoveryTest.php`, `SalesOrderParticipantTest.php`
- Modify: `graphql:container.php`, `graphql:src/Fa/Service/SalesOrderService.php`, `graphql:src/Type/SalesOrder/SalesOrderType.php`, `SalesOrderCreateInput.php`, `SalesOrderUpdateInput.php`
- Modify: `graphql:docs/superpowers/specs/2026-09-28-release-4-extensions-recurring-design.md` (two *(revised)* notes, Step 10)

**Interfaces:**
- Consumes: `FA\GraphQL\Fa\FaSession` (`user()`, `isActive()`), `FA\GraphQL\Fa\CompanyContext` (`isSet()`, `company()`), `FA\GraphQL\Fa\Bootstrap::includeFa(string)`, `FA\GraphQL\Fa\Service\InvoiceMailer`, `FA\GraphQL\ApiSchema`, `FA\GraphQL\Fa\Service\ServiceCall::run/each`, `FA\GraphQL\Error\BadInput`, FrontAccounting's global `$Hooks` (set by `install_hooks()` in `FaSession::openCompany()`) and `set_ext_domain()` (`includes/lang/gettext.inc:533`), the Release 2 test base `tests/Integration/SalesOrder/SalesOrderTestCase`.
- Produces (namespace `FA\GraphQL\Extension`, exact):
  - `interface Extension { name(): string; contractVersion(): string; queryFields(ExtensionContext $c): array; mutationFields(ExtensionContext $c): array; typeFields(ExtensionContext $c): array; inputFields(ExtensionContext $c): array; participants(ExtensionContext $c): array; }` — root fields and contributions are `name => webonyx field config` (a config may carry `'name'`, which must equal its key); `typeFields` is `['SalesOrderType' => [name => config]]`; `inputFields` is `['SalesOrderCreateInput' => [...], 'SalesOrderUpdateInput' => [...]]`.
  - `abstract class AbstractExtension implements Extension` — `contractVersion()` returns `ExtensionRegistry::CONTRACT_VERSION`, every other method but `name()` returns `[]`.
  - `interface SalesOrderParticipant { validate(array $input): void; isRelaxed(int $orderId, array $input): bool; afterCreate(int $orderId, array $input): void; afterUpdate(int $orderId, array $input): void; afterDelete(int $orderId): void; afterClose(int $orderId): void; }` — **deviation from the contract:** `validate()` takes no `$index`; `ServiceCall::each` already tags a `BadInput`/`FaRejected` thrown inside an item with the item's index (Release 2), and `SalesOrderService` does not know the index. Recorded in spec §2.4 (Step 10).
  - `final class ExtensionRegistry { const HOOK = 'graphql_extensions'; const CONTRACT_VERSION = '1.0'; register(Extension $e): void; all(): array; }`
  - `final class ExtensionContext { __construct(\DI\Container $container); container(): \DI\Container; session(): FaSession; mailer(): InvoiceMailer; includeFa(string $path): void; company(): int; login(): string; }`
  - `final class ExtensionLoader { const EXTENSIBLE_TYPES = ['SalesOrderType']; const EXTENSIBLE_INPUTS = ['SalesOrderCreateInput', 'SalesOrderUpdateInput']; __construct(?callable $log = null); load(ExtensionRegistry $r, ExtensionContext $c): LoadedExtensions; }`
  - `final class LoadedExtensions { static none(): self; names(): array; queryFields(): array; mutationFields(): array; typeFields(string $type): array; inputFields(string $input): array; salesOrderParticipants(): array; appendTo(string $target, array $coreFields): array; ownerOf(string $root, string $field): ?string; }` — `appendTo()` (an addition to the contract) merges a target's contributions into the core's list-shaped field array, dropping and logging a contribution whose name the core already has.
  - `final class Extensions { __construct(\DI\Container $container, FaSession $session, ?callable $log = null); loaded(): LoadedExtensions; }` — one per request (container singleton). With no company open it returns `LoadedExtensions::none()` without caching; once a company is open it discovers and caches.
  - `final class SchemaAssembler { static build(\GraphQL\Type\Schema $core, LoadedExtensions $x, ?callable $log = null): \GraphQL\Type\Schema; }` — returns `$core` itself when no extension adds a root field.
- **Deviation from spec §2.5 (recorded in Step 10):** a clash with a **core** root field or core type/input field drops that one contribution, not the whole extension. The core types are built while the schema is being assembled, after the extension has been accepted; dropping the extension whole would need a second schema build. Every clash that can be seen before any type is built — another extension's names, a non-extensible target, a non-null input field, a bad version, an exception — still drops the extension whole.

**Read before writing:**
- `ApiSchema` stays untouched: its `'fields' => [` arrays must remain literal for the anorm-graphql editor. `SchemaAssembler` builds new `Query`/`Mutation` `ObjectType`s from the core types' `config['fields']` plus the extension fields, sharing every other type instance. webonyx accepts a mixed field array — list entries carrying `'name'` (FieldBuilder output) and keyed entries (`'apiVersion' => [...]`).
- `SalesOrderType` and the two inputs build their field lists in their constructors (anorm-graphql's base classes call `fields()` from `__construct`). Their `Extensions` parameter is **optional and last** so the existing unit tests (`new SalesOrderType($lineType, $recurrenceType)`) keep working; the container must therefore wire it explicitly — PHP-DI autowiring gives an optional parameter its default (`null`), as `container.php` already notes for `Authenticator`.
- The schema is built by `GraphQLAction` after `FaSessionMiddleware` has entered the token's company, so `Extensions::loaded()` sees the company's `$Hooks`. Anonymous requests (and `login`, which opens its company during execution) get no extension fields — spec §2.1.
- `hook_invoke_all()` would let one throwing hook abort the rest. `Extensions` walks `$GLOBALS['Hooks']` itself with the same `set_ext_domain($hook->path)` / `set_ext_domain()` bracketing (upstream `includes/hooks.inc:294-315`), catching per hook.

- [ ] **Step 1: Test support** — `graphql:tests/Support/Extension/FakeExtension.php`:

```php
<?php

namespace FA\GraphQL\Tests\Support\Extension;

use FA\GraphQL\Extension\Extension;
use FA\GraphQL\Extension\ExtensionContext;

/**
 * An extension a test configures field by field. `$throwIn` names one method that
 * throws instead of answering.
 */
final class FakeExtension implements Extension
{
    public string $name;
    public string $version = '1.0';
    /** @var array<string, mixed> */
    public array $query = [];
    /** @var array<string, mixed> */
    public array $mutation = [];
    /** @var array<string, array<string, mixed>> */
    public array $types = [];
    /** @var array<string, array<string, mixed>> */
    public array $inputs = [];
    /** @var array<int, object> */
    public array $participants = [];
    public ?string $throwIn = null;

    /**
     * @param array<string, mixed> $options property => value
     */
    public function __construct(string $name, array $options = [])
    {
        $this->name = $name;
        foreach ($options as $property => $value) {
            $this->$property = $value;
        }
    }

    public function name(): string
    {
        $this->maybeThrow('name');

        return $this->name;
    }

    public function contractVersion(): string
    {
        $this->maybeThrow('contractVersion');

        return $this->version;
    }

    public function queryFields(ExtensionContext $c): array
    {
        $this->maybeThrow('queryFields');

        return $this->query;
    }

    public function mutationFields(ExtensionContext $c): array
    {
        $this->maybeThrow('mutationFields');

        return $this->mutation;
    }

    public function typeFields(ExtensionContext $c): array
    {
        $this->maybeThrow('typeFields');

        return $this->types;
    }

    public function inputFields(ExtensionContext $c): array
    {
        $this->maybeThrow('inputFields');

        return $this->inputs;
    }

    public function participants(ExtensionContext $c): array
    {
        $this->maybeThrow('participants');

        return $this->participants;
    }

    private function maybeThrow(string $method): void
    {
        if ($this->throwIn === $method) {
            throw new \RuntimeException("boom in $method");
        }
    }
}
```

`graphql:tests/Support/Extension/FakeHooks.php`:

```php
<?php

namespace FA\GraphQL\Tests\Support\Extension;

use FA\GraphQL\Extension\Extension;

/**
 * Stands in for an installed FrontAccounting extension's hooks object: what
 * install_hooks() puts in $Hooks for a company where the extension is active.
 */
class FakeHooks extends \hooks
{
    /** @var Extension[] */
    private array $extensions;
    private bool $throws;

    /**
     * @param Extension[] $extensions
     */
    public function __construct(array $extensions, bool $throws = false)
    {
        $this->extensions = $extensions;
        $this->throws = $throws;
        $this->module_name = 'fake';
        $this->path = 'modules/fake';
    }

    /**
     * @param mixed $registry the ExtensionRegistry
     * @param mixed $opts
     */
    public function graphql_extensions(&$registry, $opts = null): void // phpcs:ignore PSR1.Methods.CamelCapsMethodName
    {
        if ($this->throws) {
            throw new \RuntimeException('hook boom');
        }
        foreach ($this->extensions as $extension) {
            $registry->register($extension);
        }
    }
}
```

`graphql:tests/Support/Extension/RecordingParticipant.php`:

```php
<?php

namespace FA\GraphQL\Tests\Support\Extension;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Extension\SalesOrderParticipant;

/**
 * Records every call. `$failIn` names one method that throws; `$relaxed` is what
 * isRelaxed() answers.
 */
final class RecordingParticipant implements SalesOrderParticipant
{
    /** @var array<int, array<int, mixed>> */
    public array $calls = [];
    public ?string $failIn = null;
    public bool $relaxed = false;

    public function validate(array $input): void
    {
        $this->calls[] = ['validate'];
        if ($this->failIn === 'validate') {
            throw new BadInput('The fake participant refused this order.', 'fake');
        }
    }

    public function isRelaxed(int $orderId, array $input): bool
    {
        $this->calls[] = ['isRelaxed', $orderId];

        return $this->relaxed;
    }

    public function afterCreate(int $orderId, array $input): void
    {
        $this->record('afterCreate', $orderId);
    }

    public function afterUpdate(int $orderId, array $input): void
    {
        $this->record('afterUpdate', $orderId);
    }

    public function afterDelete(int $orderId): void
    {
        $this->record('afterDelete', $orderId);
    }

    public function afterClose(int $orderId): void
    {
        $this->record('afterClose', $orderId);
    }

    private function record(string $method, int $orderId): void
    {
        $this->calls[] = [$method, $orderId];
        if ($this->failIn === $method) {
            throw new \RuntimeException("participant failed in $method");
        }
    }
}
```

`FakeHooks` extends FrontAccounting's `\hooks`, which exists only once FrontAccounting is booted: it is used from integration tests only. Unit tests use `ExtensionRegistry` directly.

- [ ] **Step 2: Write the failing unit tests** — `graphql:tests/Unit/Extension/ExtensionLoaderTest.php`:

```php
<?php

namespace FA\GraphQL\Tests\Unit\Extension;

use DI\ContainerBuilder;
use FA\GraphQL\Extension\ExtensionContext;
use FA\GraphQL\Extension\ExtensionLoader;
use FA\GraphQL\Extension\ExtensionRegistry;
use FA\GraphQL\Extension\LoadedExtensions;
use FA\GraphQL\Tests\Support\Extension\FakeExtension;
use FA\GraphQL\Tests\Support\Extension\RecordingParticipant;
use GraphQL\Type\Definition\Type;
use PHPUnit\Framework\TestCase;

class ExtensionLoaderTest extends TestCase
{
    /** @var string[] */
    private array $log = [];

    private function load(FakeExtension ...$extensions): LoadedExtensions
    {
        $registry = new ExtensionRegistry();
        foreach ($extensions as $extension) {
            $registry->register($extension);
        }
        $loader = new ExtensionLoader(function (string $line): void {
            $this->log[] = $line;
        });

        return $loader->load($registry, new ExtensionContext((new ContainerBuilder())->build()));
    }

    private static function field(): array
    {
        return ['type' => Type::string(), 'resolve' => static function (): string {
            return 'x';
        }];
    }

    public function testContributionsOfAGoodExtensionAreLoaded(): void
    {
        $participant = new RecordingParticipant();
        $loaded = $this->load(new FakeExtension('good', [
            'query' => ['goodList' => self::field()],
            'mutation' => ['goodDo' => self::field()],
            'types' => ['SalesOrderType' => ['goodField' => self::field()]],
            'inputs' => ['SalesOrderCreateInput' => ['goodInput' => ['type' => Type::string()]]],
            'participants' => [$participant],
        ]));

        $this->assertSame(['good'], $loaded->names());
        $this->assertSame(['goodList'], array_keys($loaded->queryFields()));
        $this->assertSame(['goodDo'], array_keys($loaded->mutationFields()));
        $this->assertSame(['goodField'], array_keys($loaded->typeFields('SalesOrderType')));
        $this->assertSame(['goodInput'], array_keys($loaded->inputFields('SalesOrderCreateInput')));
        $this->assertSame([], $loaded->inputFields('SalesOrderUpdateInput'));
        $this->assertSame([$participant], $loaded->salesOrderParticipants());
        $this->assertSame('good', $loaded->ownerOf('query', 'goodList'));
        $this->assertSame([], $this->log);
    }

    public function testAnotherMajorContractVersionIsDropped(): void
    {
        $loaded = $this->load(new FakeExtension('future', ['version' => '2.0', 'query' => ['f' => self::field()]]));

        $this->assertSame([], $loaded->names());
        $this->assertStringContainsString('graphql extension future:', $this->log[0]);
        $this->assertStringContainsString('2.0', $this->log[0]);
    }

    public function testAMinorContractVersionIsAccepted(): void
    {
        $loaded = $this->load(new FakeExtension('minor', ['version' => '1.3']));

        $this->assertSame(['minor'], $loaded->names());
    }

    /**
     * @dataProvider throwingMethods
     */
    public function testAnExtensionThrowingWhileContributingIsDroppedAndTheRestLoad(string $method): void
    {
        $loaded = $this->load(
            new FakeExtension('broken', ['throwIn' => $method, 'query' => ['brokenList' => self::field()]]),
            new FakeExtension('fine', ['query' => ['fineList' => self::field()]])
        );

        $this->assertSame(['fine'], $loaded->names());
        $this->assertSame(['fineList'], array_keys($loaded->queryFields()));
        $this->assertCount(1, $this->log);
        $this->assertStringContainsString("boom in $method", $this->log[0]);
    }

    public function throwingMethods(): array
    {
        return array_map(static function (string $m): array {
            return [$m];
        }, ['name', 'contractVersion', 'queryFields', 'mutationFields', 'typeFields', 'inputFields', 'participants']);
    }

    public function testATargetTheCoreDoesNotMarkExtensibleDropsTheExtension(): void
    {
        $loaded = $this->load(new FakeExtension('greedy', [
            'types' => ['CustomerType' => ['extra' => self::field()]],
        ]));

        $this->assertSame([], $loaded->names());
        $this->assertStringContainsString('CustomerType', $this->log[0]);
    }

    public function testAnInputContributedAsATypeFieldIsRefused(): void
    {
        $loaded = $this->load(new FakeExtension('mixed', [
            'types' => ['SalesOrderCreateInput' => ['extra' => self::field()]],
        ]));

        $this->assertSame([], $loaded->names());
    }

    public function testANonNullInputFieldDropsTheExtension(): void
    {
        $loaded = $this->load(new FakeExtension('strict', [
            'inputs' => ['SalesOrderUpdateInput' => ['must' => ['type' => Type::nonNull(Type::string())]]],
        ]));

        $this->assertSame([], $loaded->names());
        $this->assertStringContainsString('nullable', $this->log[0]);
    }

    public function testTheLaterOfTwoClashingExtensionsIsDroppedWhole(): void
    {
        $loaded = $this->load(
            new FakeExtension('first', ['query' => ['sharedList' => self::field()]]),
            new FakeExtension('second', [
                'query' => ['sharedList' => self::field()],
                'mutation' => ['secondOnly' => self::field()],
            ])
        );

        $this->assertSame(['first'], $loaded->names());
        $this->assertSame([], $loaded->mutationFields());
        $this->assertStringContainsString('graphql extension second:', $this->log[0]);
    }

    public function testTypeFieldClashesBetweenExtensionsDropTheLaterOne(): void
    {
        $loaded = $this->load(
            new FakeExtension('first', ['types' => ['SalesOrderType' => ['same' => self::field()]]]),
            new FakeExtension('second', ['types' => ['SalesOrderType' => ['same' => self::field()]]])
        );

        $this->assertSame(['first'], $loaded->names());
    }

    public function testADuplicateExtensionNameIsDropped(): void
    {
        $loaded = $this->load(new FakeExtension('twin'), new FakeExtension('twin'));

        $this->assertSame(['twin'], $loaded->names());
        $this->assertCount(1, $this->log);
    }

    public function testAnInvalidFieldNameDropsTheExtension(): void
    {
        $loaded = $this->load(new FakeExtension('odd', ['query' => ['bad-name' => self::field()]]));

        $this->assertSame([], $loaded->names());
    }

    public function testAConfigWhoseNameDiffersFromItsKeyDropsTheExtension(): void
    {
        $loaded = $this->load(new FakeExtension('liar', [
            'query' => ['one' => ['name' => 'other'] + self::field()],
        ]));

        $this->assertSame([], $loaded->names());
    }

    public function testAParticipantOfNoKnownKindDropsTheExtension(): void
    {
        $loaded = $this->load(new FakeExtension('stranger', ['participants' => [new \stdClass()]]));

        $this->assertSame([], $loaded->names());
        $this->assertStringContainsString('participant', $this->log[0]);
    }

    public function testNoneLoadsNothing(): void
    {
        $none = LoadedExtensions::none();

        $this->assertSame([], $none->names());
        $this->assertSame([], $none->queryFields());
        $this->assertSame([], $none->salesOrderParticipants());
    }
}
```

`graphql:tests/Unit/Extension/LoadedExtensionsTest.php`:

```php
<?php

namespace FA\GraphQL\Tests\Unit\Extension;

use DI\ContainerBuilder;
use FA\GraphQL\Extension\ExtensionContext;
use FA\GraphQL\Extension\ExtensionLoader;
use FA\GraphQL\Extension\ExtensionRegistry;
use FA\GraphQL\Tests\Support\Extension\FakeExtension;
use GraphQL\Type\Definition\Type;
use PHPUnit\Framework\TestCase;

class LoadedExtensionsTest extends TestCase
{
    public function testAppendToAddsContributionsInListShapeAndDropsCoreClashes(): void
    {
        $log = [];
        $registry = new ExtensionRegistry();
        $registry->register(new FakeExtension('ext', ['types' => ['SalesOrderType' => [
            'extra' => ['type' => Type::string()],
            'id' => ['type' => Type::string()],
        ]]]));
        $loaded = (new ExtensionLoader(function (string $line) use (&$log): void {
            $log[] = $line;
        }))->load($registry, new ExtensionContext((new ContainerBuilder())->build()));

        $core = [['name' => 'id', 'type' => Type::id()], ['name' => 'reference', 'type' => Type::string()]];
        $merged = $loaded->appendTo('SalesOrderType', $core);

        $this->assertSame(['id', 'reference', 'extra'], array_column($merged, 'name'));
        $this->assertSame(Type::id(), $merged[0]['type']);
        $this->assertCount(1, $log);
        $this->assertStringContainsString('SalesOrderType.id', $log[0]);
    }

    public function testAppendToWithNothingContributedReturnsTheCoreUnchanged(): void
    {
        $core = [['name' => 'id', 'type' => Type::id()]];

        $none = \FA\GraphQL\Extension\LoadedExtensions::none();
        $this->assertSame($core, $none->appendTo('SalesOrderType', $core));
    }
}
```

`graphql:tests/Unit/Extension/SchemaAssemblerTest.php`:

```php
<?php

namespace FA\GraphQL\Tests\Unit\Extension;

use DI\ContainerBuilder;
use FA\GraphQL\ApiSchema;
use FA\GraphQL\Extension\ExtensionContext;
use FA\GraphQL\Extension\ExtensionLoader;
use FA\GraphQL\Extension\ExtensionRegistry;
use FA\GraphQL\Extension\LoadedExtensions;
use FA\GraphQL\Extension\SchemaAssembler;
use FA\GraphQL\Tests\Support\Extension\FakeExtension;
use GraphQL\GraphQL;
use GraphQL\Type\Definition\Type;
use PHPUnit\Framework\TestCase;

class SchemaAssemblerTest extends TestCase
{
    /** @var string[] */
    private array $log = [];

    private function core(): ApiSchema
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);

        return new ApiSchema($builder->build());
    }

    private function loaded(FakeExtension ...$extensions): LoadedExtensions
    {
        $registry = new ExtensionRegistry();
        foreach ($extensions as $extension) {
            $registry->register($extension);
        }

        return (new ExtensionLoader())->load($registry, new ExtensionContext((new ContainerBuilder())->build()));
    }

    public function testWithoutExtensionRootFieldsTheCoreSchemaIsReturnedAsItIs(): void
    {
        $core = $this->core();

        $this->assertSame($core, SchemaAssembler::build($core, LoadedExtensions::none()));
    }

    public function testExtensionRootFieldsAreServedBesideTheCore(): void
    {
        $loaded = $this->loaded(new FakeExtension('ext', [
            'query' => ['extHello' => ['type' => Type::nonNull(Type::string()), 'resolve' => static function (): string {
                return 'hello';
            }]],
            'mutation' => ['extDo' => ['type' => Type::boolean(), 'resolve' => static function (): bool {
                return true;
            }]],
        ]));

        $schema = SchemaAssembler::build($this->core(), $loaded);
        $schema->assertValid();

        $this->assertTrue($schema->getQueryType()->hasField('extHello'));
        $this->assertTrue($schema->getQueryType()->hasField('apiVersion'));
        $this->assertTrue($schema->getMutationType()->hasField('extDo'));
        $this->assertTrue($schema->getMutationType()->hasField('login'));

        $result = GraphQL::executeQuery($schema, '{ extHello apiVersion }')->toArray();
        $this->assertSame('hello', $result['data']['extHello']);
        $this->assertSame(ApiSchema::VERSION, $result['data']['apiVersion']);
    }

    public function testARootFieldClashingWithTheCoreIsDroppedAndLogged(): void
    {
        $loaded = $this->loaded(new FakeExtension('ext', [
            'query' => [
                'apiVersion' => ['type' => Type::string(), 'resolve' => static function (): string {
                    return 'hijacked';
                }],
                'extOther' => ['type' => Type::string()],
            ],
        ]));

        $schema = SchemaAssembler::build($this->core(), $loaded, function (string $line): void {
            $this->log[] = $line;
        });

        $result = GraphQL::executeQuery($schema, '{ apiVersion }')->toArray();
        $this->assertSame(ApiSchema::VERSION, $result['data']['apiVersion']);
        $this->assertTrue($schema->getQueryType()->hasField('extOther'));
        $this->assertStringContainsString('graphql extension ext: Query.apiVersion', $this->log[0]);
    }
}
```

- [ ] **Step 3: Write the failing integration tests** — `graphql:tests/Integration/Extension/ExtensionDiscoveryTest.php`:

```php
<?php

namespace FA\GraphQL\Tests\Integration\Extension;

use FA\GraphQL\Auth\Claims;
use FA\GraphQL\Config;
use FA\GraphQL\Extension\Extensions;
use FA\GraphQL\Fa\Bootstrap;
use FA\GraphQL\RequestInfo;
use FA\GraphQL\SessionGate;
use FA\GraphQL\Tests\Integration\FaTestCase;
use FA\GraphQL\Tests\Support\Extension\FakeExtension;
use FA\GraphQL\Tests\Support\Extension\FakeHooks;
use GraphQL\GraphQL;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ExtensionDiscoveryTest extends FaTestCase
{
    private \DI\Container $container;

    protected function setUp(): void
    {
        parent::setUp();
        $config = Config::fromArray([
            'secret' => '0123456789abcdef0123456789abcdef',
            'fa_root' => Bootstrap::defaultRoot(),
        ]);
        $factory = require dirname(__DIR__, 3) . '/container.php';
        $this->container = $factory($config, new RequestInfo(false, 'phpunit 127.0.0.1'));
    }

    private function enter(): void
    {
        $gate = $this->container->get(SessionGate::class);
        $gate->boot();
        $gate->enter(new Claims(0, 'apitest', 'extension-test', new \DateTimeImmutable('+5 minutes')));
    }

    private static function hello(): FakeExtension
    {
        return new FakeExtension('fake_hello', ['query' => ['fakeHello' => [
            'type' => Type::string(),
            'resolve' => static function (): string {
                return 'hi';
            },
        ]]]);
    }

    public function testAnExtensionContributesOnlyWhereItsHookIsInstalled(): void
    {
        $this->enter();
        // install_hooks() ran for company 0 in enter(); fake_hello is not active there.
        $this->assertNotContains('fake_hello', $this->container->get(Extensions::class)->loaded()->names());

        // A second request's container, where the company's hooks include it.
        $this->setUp();
        $this->enter();
        $GLOBALS['Hooks']['fake_hello'] = new FakeHooks([self::hello()]);
        $schema = $this->container->get(Schema::class);

        $result = GraphQL::executeQuery($schema, '{ fakeHello }', null, $this->container)->toArray();
        $this->assertSame('hi', $result['data']['fakeHello']);
    }

    public function testWithNoCompanyOpenNothingIsRegisteredAndNothingIsCached(): void
    {
        $this->container->get(SessionGate::class)->boot();
        $extensions = $this->container->get(Extensions::class);
        $this->assertSame([], $extensions->loaded()->names());

        $gate = $this->container->get(SessionGate::class);
        $gate->enter(new Claims(0, 'apitest', 'extension-test', new \DateTimeImmutable('+5 minutes')));
        $GLOBALS['Hooks']['fake_hello'] = new FakeHooks([self::hello()]);

        $this->assertContains('fake_hello', $extensions->loaded()->names());
    }

    public function testAHookThatThrowsIsLoggedAndOthersStillRegister(): void
    {
        $this->enter();
        $GLOBALS['Hooks']['fake_broken'] = new FakeHooks([], true);
        $GLOBALS['Hooks']['fake_hello'] = new FakeHooks([self::hello()]);
        $log = [];
        $extensions = new Extensions(
            $this->container,
            $this->container->get(\FA\GraphQL\Fa\FaSession::class),
            function (string $line) use (&$log): void {
                $log[] = $line;
            }
        );

        $this->assertSame(['fake_hello'], array_values(array_filter(
            $extensions->loaded()->names(),
            static function (string $n): bool {
                return strpos($n, 'fake_') === 0;
            }
        )));
        $this->assertStringContainsString('fake_broken', implode("\n", $log));
        $this->assertStringContainsString('hook boom', implode("\n", $log));
    }

    public function testTheCoreSchemaIsUntouchedWhenNoExtensionAddsRootFields(): void
    {
        $this->enter();
        // Only the fakes are removed: a real extension (sgw_sales, from Task 5) may add
        // root fields, and then the assembled schema is not the ApiSchema instance.
        $loaded = $this->container->get(Extensions::class)->loaded();
        if ($loaded->queryFields() !== [] || $loaded->mutationFields() !== []) {
            $this->assertNotInstanceOf(\FA\GraphQL\ApiSchema::class, $this->container->get(Schema::class));

            return;
        }

        $this->assertInstanceOf(\FA\GraphQL\ApiSchema::class, $this->container->get(Schema::class));
    }
}
```

`graphql:tests/Integration/Extension/SalesOrderParticipantTest.php`:

```php
<?php

namespace FA\GraphQL\Tests\Integration\Extension;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Tests\Integration\SalesOrder\SalesOrderTestCase;
use FA\GraphQL\Tests\Support\Extension\FakeExtension;
use FA\GraphQL\Tests\Support\Extension\FakeHooks;
use FA\GraphQL\Tests\Support\Extension\RecordingParticipant;
use FA\GraphQL\Tests\Support\FaTestRows;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SalesOrderParticipantTest extends SalesOrderTestCase
{
    private RecordingParticipant $participant;

    protected function setUp(): void
    {
        parent::setUp();
        // Before anything asks Extensions (the service does, lazily, on its first write).
        $this->participant = new RecordingParticipant();
        $GLOBALS['Hooks']['fake_participant'] = new FakeHooks([
            new FakeExtension('fake_participant', ['participants' => [$this->participant]]),
        ]);
    }

    private static function orderCount(): int
    {
        return (int) FaTestRows::connect()->query('SELECT COUNT(*) FROM 0_sales_orders')->fetchColumn();
    }

    private function methods(): array
    {
        return array_column($this->participant->calls, 0);
    }

    public function testParticipantsSeeAWholeCreate(): void
    {
        $orderNo = $this->createOrder();

        $this->assertSame(['validate', 'afterCreate'], $this->methods());
        $this->assertSame($orderNo, $this->participant->calls[1][1]);
    }

    public function testAParticipantRefusingInValidateStopsTheOrderBeforeAnyWrite(): void
    {
        $this->participant->failIn = 'validate';
        $before = self::orderCount();
        $input = $this->orderInput();

        try {
            ServiceCall::run(function () use ($input): int {
                return $this->service()->create($input);
            });
            $this->fail('Expected the participant to refuse.');
        } catch (BadInput $e) {
            $this->assertSame('fake', $e->field());
        }
        $this->assertSame($before, self::orderCount());
    }

    public function testAParticipantFailingAfterCreateRollsTheOrderBack(): void
    {
        $this->participant->failIn = 'afterCreate';
        $before = self::orderCount();
        $input = $this->orderInput();

        try {
            ServiceCall::run(function () use ($input): int {
                return $this->service()->create($input);
            });
            $this->fail('Expected the participant failure to fail the mutation.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('participant failed in afterCreate', $e->getMessage());
        }
        $this->assertSame($before, self::orderCount());
    }

    public function testParticipantsSeeUpdateDeleteAndClose(): void
    {
        $updated = $this->createOrder();
        $version = (int) $this->orderRow($updated)['version'];
        ServiceCall::run(function () use ($updated, $version): void {
            $this->service()->update(['id' => $updated, 'version' => $version, 'comments' => 'changed']);
        });
        $this->assertContains(['afterUpdate', $updated], $this->participant->calls);

        $deleted = $this->createOrder();
        ServiceCall::run(function () use ($deleted): string {
            return $this->service()->delete($deleted);
        });
        $this->assertContains(['afterDelete', $deleted], $this->participant->calls);

        $closed = $this->createOrder();
        $this->deliver($closed, [$this->lineIds($closed)[0] => 1]);
        ServiceCall::run(function () use ($closed): string {
            return $this->service()->delete($closed);
        });
        $this->assertContains(['afterClose', $closed], $this->participant->calls);
    }

    public function testARelaxingParticipantKeepsADeliveredOrdersHeaderEditable(): void
    {
        $orderNo = $this->createOrder();
        $this->deliver($orderNo, [$this->lineIds($orderNo)[0] => 1]);
        $this->participant->relaxed = true;
        $version = (int) $this->orderRow($orderNo)['version'];
        $nextDay = date('Y-m-d', strtotime($this->today() . ' +1 day'));

        ServiceCall::run(function () use ($orderNo, $version, $nextDay): void {
            $this->service()->update(['id' => $orderNo, 'version' => $version, 'orderDate' => $nextDay]);
        });

        $this->assertSame($nextDay, $this->orderRow($orderNo)['ord_date']);
    }
}
```

Before writing this test, confirm the helper names against `tests/Integration/SalesOrder/SalesOrderTestCase.php` (`createOrder()`, `orderInput()`, `orderRow()`, `lineIds()`, `deliver()`, `service()`, `today()`) and the column name `ord_date` in `orderRow()`'s result; adjust the test to the real names and record any difference under "Deviations". A relaxed order may still fail another check (e.g. the next day outside the fiscal year near year end): if so, keep the date inside the fiscal year.

- [ ] **Step 4: Run to see them fail**

Run: `docker/fa-graphql test --testsuite unit --filter 'ExtensionLoaderTest|LoadedExtensionsTest|SchemaAssemblerTest'`
Expected: FAIL — `Class "FA\GraphQL\Extension\ExtensionRegistry" not found`.

Run: `docker/fa-graphql test --testsuite integration --filter 'ExtensionDiscoveryTest|SalesOrderParticipantTest'`
Expected: FAIL — `Class "FA\GraphQL\Extension\Extensions" not found` (and `FakeHooks`' parent loads only after boot, so these error rather than skip).

- [ ] **Step 5: Implement the contract** — `graphql:src/Extension/Extension.php`:

```php
<?php

namespace FA\GraphQL\Extension;

/**
 * What another FrontAccounting extension implements to add to this API (Release 4
 * spec §2.2). Registered from its hooks class:
 *
 *     function graphql_extensions(&$registry, $opts = null)
 *     {
 *         if (interface_exists(\FA\GraphQL\Extension\Extension::class)) {
 *             $registry->register(new MyExtension());
 *         }
 *     }
 *
 * Field arrays are `name => webonyx field config`. A config may carry 'name'; it must
 * then equal its key.
 */
interface Extension
{
    /** Unique among extensions, e.g. 'sgw_sales'. */
    public function name(): string;

    /** The contract version it was built for; another major version is refused. */
    public function contractVersion(): string;

    /** @return array<string, array<string, mixed>> root query fields */
    public function queryFields(ExtensionContext $c): array;

    /** @return array<string, array<string, mixed>> root mutation fields */
    public function mutationFields(ExtensionContext $c): array;

    /** @return array<string, array<string, array<string, mixed>>> ['SalesOrderType' => [name => config]] */
    public function typeFields(ExtensionContext $c): array;

    /** @return array<string, array<string, array<string, mixed>>> ['SalesOrderCreateInput' => [...], ...] */
    public function inputFields(ExtensionContext $c): array;

    /** @return object[] objects implementing a participant interface, e.g. SalesOrderParticipant */
    public function participants(ExtensionContext $c): array;
}
```

`graphql:src/Extension/AbstractExtension.php`:

```php
<?php

namespace FA\GraphQL\Extension;

/**
 * Contributes nothing until a subclass says otherwise.
 */
abstract class AbstractExtension implements Extension
{
    public function contractVersion(): string
    {
        return ExtensionRegistry::CONTRACT_VERSION;
    }

    public function queryFields(ExtensionContext $c): array
    {
        return [];
    }

    public function mutationFields(ExtensionContext $c): array
    {
        return [];
    }

    public function typeFields(ExtensionContext $c): array
    {
        return [];
    }

    public function inputFields(ExtensionContext $c): array
    {
        return [];
    }

    public function participants(ExtensionContext $c): array
    {
        return [];
    }
}
```

`graphql:src/Extension/SalesOrderParticipant.php`:

```php
<?php

namespace FA\GraphQL\Extension;

/**
 * Takes part in sales order writes (Release 4 spec §2.4). SalesOrderService calls it
 * inside the order's FaTransaction, in registration order. An exception from any
 * method fails the mutation and rolls the order back; that is the point.
 */
interface SalesOrderParticipant
{
    /**
     * Before anything is written. Throw BadInput (field set; ServiceCall adds the
     * batch index) to refuse.
     *
     * @param array<string, mixed> $input the Create or Update input
     */
    public function validate(array $input): void;

    /**
     * True when the order's header must stay editable after delivery and the
     * delivered-quantity floor does not apply. The core ORs every participant.
     *
     * @param array<string, mixed> $input
     */
    public function isRelaxed(int $orderId, array $input): bool;

    /** @param array<string, mixed> $input */
    public function afterCreate(int $orderId, array $input): void;

    /** @param array<string, mixed> $input */
    public function afterUpdate(int $orderId, array $input): void;

    public function afterDelete(int $orderId): void;

    public function afterClose(int $orderId): void;
}
```

`graphql:src/Extension/ExtensionRegistry.php`:

```php
<?php

namespace FA\GraphQL\Extension;

/**
 * Filled by the FrontAccounting extensions active for the request's company, through
 * their `graphql_extensions(&$registry, $opts = null)` hook method.
 */
final class ExtensionRegistry
{
    public const HOOK = 'graphql_extensions';
    public const CONTRACT_VERSION = '1.0';

    /** @var Extension[] */
    private array $extensions = [];

    public function register(Extension $extension): void
    {
        $this->extensions[] = $extension;
    }

    /**
     * @return Extension[] in registration order
     */
    public function all(): array
    {
        return $this->extensions;
    }
}
```

`graphql:src/Extension/ExtensionContext.php`:

```php
<?php

namespace FA\GraphQL\Extension;

use DI\Container;
use FA\GraphQL\Fa\Bootstrap;
use FA\GraphQL\Fa\CompanyContext;
use FA\GraphQL\Fa\FaSession;
use FA\GraphQL\Fa\Service\InvoiceMailer;

/**
 * What an extension uses to follow this module's rules (Release 4 spec §2.3). Guard,
 * ServiceCall, FaTransaction, DocumentLock, DateConversion, BadInput and FaRejected
 * are static or plain classes an extension uses directly. Extensions are trusted
 * code: this makes the right thing easy; it does not sandbox.
 */
final class ExtensionContext
{
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /** The request's container: resolve Types through it so each is one instance per schema. */
    public function container(): Container
    {
        return $this->container;
    }

    public function session(): FaSession
    {
        return $this->container->get(FaSession::class);
    }

    public function mailer(): InvoiceMailer
    {
        return $this->container->get(InvoiceMailer::class);
    }

    /** Include a FrontAccounting file, relative to FrontAccounting's root, after boot. */
    public function includeFa(string $path): void
    {
        Bootstrap::includeFa($path);
    }

    public function company(): int
    {
        return CompanyContext::company();
    }

    public function login(): string
    {
        return (string) $this->session()->user()->loginname;
    }
}
```

`graphql:src/Extension/ExtensionLoader.php`:

```php
<?php

namespace FA\GraphQL\Extension;

use GraphQL\Type\Definition\NonNull;

/**
 * The rules an extension's contributions must meet (Release 4 spec §2.5, revised).
 * Everything that can be judged before a type is built drops the extension whole:
 * another major contract version, an exception from any of its methods, a target the
 * core does not mark extensible, a non-null input field, a bad field name, a name
 * another extension already took, a participant of no known kind. A clash with a
 * core field is judged later, when the core type is built (LoadedExtensions::appendTo,
 * SchemaAssembler), and drops only that contribution.
 */
final class ExtensionLoader
{
    public const EXTENSIBLE_TYPES = ['SalesOrderType'];
    public const EXTENSIBLE_INPUTS = ['SalesOrderCreateInput', 'SalesOrderUpdateInput'];

    /** Participant interfaces the core calls. */
    private const PARTICIPANT_KINDS = [SalesOrderParticipant::class];

    /** @var callable(string): void */
    private $log;

    public function __construct(?callable $log = null)
    {
        $this->log = $log ?? static function (string $line): void {
            error_log($line);
        };
    }

    public function load(ExtensionRegistry $registry, ExtensionContext $context): LoadedExtensions
    {
        $kept = [];
        $taken = ['name' => [], 'query' => [], 'mutation' => [], 'target' => []];

        foreach ($registry->all() as $extension) {
            try {
                $name = $extension->name();
                $version = $extension->contractVersion();
                $contribution = [
                    'query' => $extension->queryFields($context),
                    'mutation' => $extension->mutationFields($context),
                    'types' => $extension->typeFields($context),
                    'inputs' => $extension->inputFields($context),
                    'participants' => $extension->participants($context),
                ];
            } catch (\Throwable $e) {
                $who = isset($name) ? $name : get_class($extension);
                $this->drop($who, get_class($e) . ': ' . $e->getMessage());
                unset($name);
                continue;
            }

            $problem = $this->problem($name, $version, $contribution, $taken);
            if ($problem !== null) {
                $this->drop($name, $problem);
                continue;
            }

            $taken['name'][$name] = true;
            foreach (['query', 'mutation'] as $root) {
                foreach (array_keys($contribution[$root]) as $field) {
                    $taken[$root][$field] = $name;
                }
            }
            $targets = $contribution['types'] + $contribution['inputs'];
            foreach ($targets as $target => $fields) {
                foreach (array_keys($fields) as $field) {
                    $taken['target'][$target][$field] = $name;
                }
            }
            $kept[] = [
                'name' => $name,
                'query' => self::named($contribution['query']),
                'mutation' => self::named($contribution['mutation']),
                'targets' => array_map([self::class, 'named'], $targets),
                'participants' => array_values($contribution['participants']),
            ];
            unset($name);
        }

        return new LoadedExtensions($kept, $this->log);
    }

    /**
     * @param array<string, mixed> $c
     * @param array<string, array<string, mixed>> $taken
     */
    private function problem(string $name, string $version, array $c, array $taken): ?string
    {
        if (isset($taken['name'][$name])) {
            return 'another extension already has this name';
        }
        if (self::major($version) !== self::major(ExtensionRegistry::CONTRACT_VERSION)) {
            return "built for contract version $version; this module speaks "
                . ExtensionRegistry::CONTRACT_VERSION;
        }
        foreach (['query', 'mutation', 'types', 'inputs', 'participants'] as $key) {
            if (!is_array($c[$key])) {
                return "$key must be an array";
            }
        }
        foreach (['query', 'mutation'] as $root) {
            foreach ($c[$root] as $field => $config) {
                $bad = self::badField($field, $config);
                if ($bad !== null) {
                    return ucfirst($root) . ".$field: $bad";
                }
                if (isset($taken[$root][$field])) {
                    return ucfirst($root) . ".$field is already served by extension " . $taken[$root][$field];
                }
            }
        }
        foreach (['types' => self::EXTENSIBLE_TYPES, 'inputs' => self::EXTENSIBLE_INPUTS] as $key => $allowed) {
            foreach ($c[$key] as $target => $fields) {
                if (!in_array($target, $allowed, true)) {
                    return "$target is not a " . ($key === 'types' ? 'type' : 'input')
                        . ' the core lets extensions add to';
                }
                if (!is_array($fields)) {
                    return "$target's fields must be an array";
                }
                foreach ($fields as $field => $config) {
                    $bad = self::badField($field, $config);
                    if ($bad !== null) {
                        return "$target.$field: $bad";
                    }
                    if ($key === 'inputs' && ($config['type'] ?? null) instanceof NonNull) {
                        return "$target.$field must be nullable: an extension cannot make a core input stricter";
                    }
                    if (isset($taken['target'][$target][$field])) {
                        return "$target.$field is already added by extension " . $taken['target'][$target][$field];
                    }
                }
            }
        }
        foreach ($c['participants'] as $participant) {
            if (!is_object($participant) || !self::knownParticipant($participant)) {
                return 'a participant implements no participant interface this module calls';
            }
        }

        return null;
    }

    /**
     * @param mixed $field
     * @param mixed $config
     */
    private static function badField($field, $config): ?string
    {
        if (!is_string($field) || preg_match('/^[_A-Za-z][_0-9A-Za-z]*$/', $field) !== 1) {
            return 'not a GraphQL field name';
        }
        if (!is_array($config) || !array_key_exists('type', $config)) {
            return 'a field config needs a type';
        }
        if (isset($config['name']) && $config['name'] !== $field) {
            return "its config names it '" . $config['name'] . "'";
        }

        return null;
    }

    private static function knownParticipant(object $participant): bool
    {
        foreach (self::PARTICIPANT_KINDS as $kind) {
            if ($participant instanceof $kind) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, array<string, mixed>> $fields
     * @return array<string, array<string, mixed>> each config carrying its name
     */
    private static function named(array $fields): array
    {
        $named = [];
        foreach ($fields as $field => $config) {
            $named[$field] = ['name' => $field] + $config;
        }

        return $named;
    }

    private static function major(string $version): string
    {
        return explode('.', $version)[0];
    }

    private function drop(string $name, string $reason): void
    {
        ($this->log)("graphql extension $name: $reason; dropped");
    }
}
```

`graphql:src/Extension/LoadedExtensions.php`:

```php
<?php

namespace FA\GraphQL\Extension;

/**
 * The extensions that passed ExtensionLoader for this request, and what they add.
 */
final class LoadedExtensions
{
    /** @var array<int, array<string, mixed>> */
    private array $kept;

    /** @var callable(string): void */
    private $log;

    /**
     * @param array<int, array<string, mixed>> $kept name, query, mutation, targets, participants
     */
    public function __construct(array $kept, callable $log)
    {
        $this->kept = $kept;
        $this->log = $log;
    }

    public static function none(): self
    {
        return new self([], static function (string $line): void {
        });
    }

    /** @return string[] */
    public function names(): array
    {
        return array_column($this->kept, 'name');
    }

    /** @return array<string, array<string, mixed>> */
    public function queryFields(): array
    {
        return $this->merged('query');
    }

    /** @return array<string, array<string, mixed>> */
    public function mutationFields(): array
    {
        return $this->merged('mutation');
    }

    /** @return array<string, array<string, mixed>> */
    public function typeFields(string $type): array
    {
        return $this->forTarget($type);
    }

    /** @return array<string, array<string, mixed>> */
    public function inputFields(string $input): array
    {
        return $this->forTarget($input);
    }

    /** @return SalesOrderParticipant[] */
    public function salesOrderParticipants(): array
    {
        $participants = [];
        foreach ($this->kept as $extension) {
            foreach ($extension['participants'] as $participant) {
                if ($participant instanceof SalesOrderParticipant) {
                    $participants[] = $participant;
                }
            }
        }

        return $participants;
    }

    /** Which extension serves a root field ('query' or 'mutation'). */
    public function ownerOf(string $root, string $field): ?string
    {
        foreach ($this->kept as $extension) {
            if (isset($extension[$root][$field])) {
                return $extension['name'];
            }
        }

        return null;
    }

    /**
     * The core's list-shaped fields (as anorm-graphql's builders produce them) with
     * this target's contributions appended. A contribution whose name the core has is
     * dropped and logged: the core's field wins.
     *
     * @param array<int|string, array<string, mixed>> $coreFields
     * @return array<int|string, array<string, mixed>>
     */
    public function appendTo(string $target, array $coreFields): array
    {
        $coreNames = [];
        foreach ($coreFields as $key => $field) {
            $coreNames[is_int($key) ? (string) ($field['name'] ?? '') : $key] = true;
        }
        foreach ($this->kept as $extension) {
            foreach ($extension['targets'][$target] ?? [] as $field => $config) {
                if (isset($coreNames[$field])) {
                    ($this->log)("graphql extension {$extension['name']}: $target.$field clashes with the core; dropped");
                    continue;
                }
                $coreFields[] = $config;
            }
        }

        return $coreFields;
    }

    /** @return array<string, array<string, mixed>> */
    private function merged(string $root): array
    {
        $fields = [];
        foreach ($this->kept as $extension) {
            $fields += $extension[$root];
        }

        return $fields;
    }

    /** @return array<string, array<string, mixed>> */
    private function forTarget(string $target): array
    {
        $fields = [];
        foreach ($this->kept as $extension) {
            $fields += $extension['targets'][$target] ?? [];
        }

        return $fields;
    }
}
```

`graphql:src/Extension/Extensions.php`:

```php
<?php

namespace FA\GraphQL\Extension;

use DI\Container;
use FA\GraphQL\Fa\CompanyContext;
use FA\GraphQL\Fa\FaSession;

/**
 * The request's extensions (Release 4 spec §2.1). After a company is open, every
 * hooks object FrontAccounting installed for it (install_hooks(), in
 * FaSession::openCompany()) is asked, through its graphql_extensions() method, to
 * register; the registry then goes through ExtensionLoader once and the result is
 * kept for the request. Before a company is open there is nothing to ask, and nothing
 * is cached, so a later call after the company opens discovers.
 *
 * The hooks are walked here rather than through hook_invoke_all() so one throwing
 * hook is logged and skipped instead of aborting the others; the gettext domain is
 * bracketed as hook_invoke_all() brackets it (includes/hooks.inc:294-315).
 */
final class Extensions
{
    private Container $container;
    private FaSession $session;
    private ?LoadedExtensions $loaded = null;

    /** @var callable(string): void */
    private $log;

    public function __construct(Container $container, FaSession $session, ?callable $log = null)
    {
        $this->container = $container;
        $this->session = $session;
        $this->log = $log ?? static function (string $line): void {
            error_log($line);
        };
    }

    public function loaded(): LoadedExtensions
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }
        if (!CompanyContext::isSet() || !isset($GLOBALS['Hooks']) || !is_array($GLOBALS['Hooks'])) {
            return LoadedExtensions::none();
        }

        $registry = new ExtensionRegistry();
        foreach ($GLOBALS['Hooks'] as $package => $hook) {
            if (!is_object($hook) || !method_exists($hook, ExtensionRegistry::HOOK)) {
                continue;
            }
            try {
                set_ext_domain($hook->path ?? '');
                $method = ExtensionRegistry::HOOK;
                $hook->$method($registry, null);
            } catch (\Throwable $e) {
                ($this->log)("graphql extension hook $package: " . get_class($e) . ': ' . $e->getMessage()
                    . '; its extensions were not registered');
            }
        }
        set_ext_domain();

        $this->loaded = (new ExtensionLoader($this->log))->load($registry, new ExtensionContext($this->container));

        return $this->loaded;
    }
}
```

A hook that throws after registering some extensions keeps those it registered — the registry is shared. That matches imscp-graphql's behaviour ("whatever registered before it is kept") and is what the spec's "an exception while registering … drops that extension" means for the extension that failed to register.

`graphql:src/Extension/SchemaAssembler.php`:

```php
<?php

namespace FA\GraphQL\Extension;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Schema;

/**
 * The served schema: the generated ApiSchema, untouched — its literal fields arrays
 * stay editable by anorm-graphql — with the extensions' root fields added to new
 * Query and Mutation types (Release 4 spec §2.6). Every other type is the core's own
 * instance. With no extension root fields, the core schema is served as it is.
 */
final class SchemaAssembler
{
    public static function build(Schema $core, LoadedExtensions $extensions, ?callable $log = null): Schema
    {
        $query = $extensions->queryFields();
        $mutation = $extensions->mutationFields();
        if ($query === [] && $mutation === []) {
            return $core;
        }
        $log = $log ?? static function (string $line): void {
            error_log($line);
        };

        return new Schema([
            'query' => self::extend($core->getQueryType(), $query, 'query', $extensions, $log),
            'mutation' => self::extend($core->getMutationType(), $mutation, 'mutation', $extensions, $log),
        ]);
    }

    /**
     * @param array<string, array<string, mixed>> $extra
     */
    private static function extend(
        ?ObjectType $type,
        array $extra,
        string $root,
        LoadedExtensions $extensions,
        callable $log
    ): ?ObjectType {
        if ($type === null || $extra === []) {
            return $type;
        }
        $fields = $type->config['fields'];
        if (is_callable($fields)) {
            $fields = $fields();
        }
        $coreNames = array_flip($type->getFieldNames());
        foreach ($extra as $name => $config) {
            if (isset($coreNames[$name])) {
                $log('graphql extension ' . $extensions->ownerOf($root, $name)
                    . ": {$type->name}.$name clashes with the core; dropped");
                continue;
            }
            $fields[] = $config;
        }

        return new ObjectType([
            'name' => $type->name,
            'description' => $type->description,
            'fields' => $fields,
        ]);
    }
}
```

- [ ] **Step 6: Wire the container** — in `graphql:container.php` add the uses

```php
use FA\GraphQL\Extension\Extensions;
use FA\GraphQL\Extension\SchemaAssembler;
use FA\GraphQL\Fa\Service\SalesOrderService;
use FA\GraphQL\Type\SalesOrder\SalesOrderCreateInput;
use FA\GraphQL\Type\SalesOrder\SalesOrderType;
use FA\GraphQL\Type\SalesOrder\SalesOrderUpdateInput;
use Psr\Container\ContainerInterface;
```

and replace the `Schema::class => DI\get(ApiSchema::class),` line with

```php
        // The served schema: ApiSchema plus the extensions active for the company
        // (Release 4 spec §2.6). Built by GraphQLAction after the session middleware
        // has entered the token's company.
        Schema::class => static function (ContainerInterface $c): Schema {
            return SchemaAssembler::build($c->get(ApiSchema::class), $c->get(Extensions::class)->loaded());
        },
        // One per request. Named for the types and the service below: each takes
        // Extensions as an optional last parameter (so unit tests can build them
        // without one), and autowiring would give it null.
        Extensions::class => DI\autowire(),
        SalesOrderService::class => DI\autowire()->constructorParameter('extensions', DI\get(Extensions::class)),
        SalesOrderType::class => DI\autowire()->constructorParameter('extensions', DI\get(Extensions::class)),
        SalesOrderCreateInput::class => DI\autowire()->constructorParameter('extensions', DI\get(Extensions::class)),
        SalesOrderUpdateInput::class => DI\autowire()->constructorParameter('extensions', DI\get(Extensions::class)),
```

- [ ] **Step 7: Contributions on the extensible types** — `graphql:src/Type/SalesOrder/SalesOrderType.php`: add `use FA\GraphQL\Extension\Extensions;`, a property `private ?Extensions $extensions;`, and change the constructor and the end of `fields()`:

```php
    public function __construct(
        SalesOrderLineType $lineType,
        RecurrenceType $recurrenceType,
        ?Extensions $extensions = null
    ) {
        // Before parent::__construct(), which calls fields().
        $this->lineType = $lineType;
        $this->recurrenceType = $recurrenceType;
        $this->extensions = $extensions;
        parent::__construct();
    }
```

```php
    protected function fields(): array
    {
        $fields = array_merge(parent::fields(), [
            // ... the existing 'lines' and 'recurring' FieldBuilder entries, unchanged ...
        ]);

        // Fields the company's extensions add (Release 4 spec §2.2); a clash with a
        // field above is dropped and logged.
        return $this->extensions === null ? $fields : $this->extensions->loaded()->appendTo('SalesOrderType', $fields);
    }
```

`graphql:src/Type/SalesOrder/SalesOrderCreateInput.php`: `use FA\GraphQL\Extension\Extensions;`, a property `private ?Extensions $extensions;`, constructor

```php
    public function __construct(
        SalesOrderLineCreateInput $lineInput,
        RecurrenceInputType $recurrenceInput,
        ?Extensions $extensions = null
    ) {
        // Before parent::__construct(), which builds the fields.
        $this->lineInput = $lineInput;
        $this->recurrenceInput = $recurrenceInput;
        $this->extensions = $extensions;
        parent::__construct();
    }
```

and replace `return $fields;` at the end of `fields()` with

```php
        return $this->extensions === null
            ? $fields
            : $this->extensions->loaded()->appendTo('SalesOrderCreateInput', $fields);
```

`graphql:src/Type/SalesOrder/SalesOrderUpdateInput.php`: the same three changes, with `SalesOrderLineUpdateInput $lineInput` and the target `'SalesOrderUpdateInput'`.

- [ ] **Step 8: Participants in `SalesOrderService`** — `graphql:src/Fa/Service/SalesOrderService.php`: add `use FA\GraphQL\Extension\Extensions;` and `use FA\GraphQL\Extension\SalesOrderParticipant;`, then:

```php
    private RecurringSchedule $schedule;

    private ?Extensions $extensions;

    public function __construct(RecurringSchedule $schedule, ?Extensions $extensions = null)
    {
        $this->schedule = $schedule;
        $this->extensions = $extensions;
    }

    /**
     * The company's extensions that take part in order writes (Release 4 spec §2.4).
     *
     * @return SalesOrderParticipant[]
     */
    private function participants(): array
    {
        return $this->extensions === null ? [] : $this->extensions->loaded()->salesOrderParticipants();
    }
```

In `create()`, after the existing `if (self::given($input, 'recurring')) { ... toColumns ... }` block and before `$cart = new \Cart(ST_SALESORDER, 0);`:

```php
        foreach ($this->participants() as $participant) {
            $participant->validate($input);
        }
```

and after the existing `if (self::given($input, 'recurring')) { $this->schedule->write(...); }` at the end, before `return (int) $orderNo;`:

```php
        foreach ($this->participants() as $participant) {
            $participant->afterCreate((int) $orderNo, $input);
        }
```

In `update()`, after the existing recurring pre-check block (before `$id = (int) $input['id'];`):

```php
        foreach ($this->participants() as $participant) {
            $participant->validate($input);
        }
```

Replace `isRecurringOrder()` so it ORs the participants in:

```php
    protected function isRecurringOrder(int $id, array $input): bool
    {
        if (self::given($input, 'recurring') || $this->schedule->read($id) !== null) {
            return true;
        }
        foreach ($this->participants() as $participant) {
            if ($participant->isRelaxed($id, $input)) {
                return true;
            }
        }

        return false;
    }
```

and append the participant calls to the three hooks:

```php
    protected function afterUpdate(int $id, array $input): void
    {
        if (self::given($input, 'recurring')) {
            $this->schedule->write($id, $input['recurring']);
        }
        foreach ($this->participants() as $participant) {
            $participant->afterUpdate($id, $input);
        }
    }

    protected function afterDelete(int $id): void
    {
        $this->schedule->delete($id);
        foreach ($this->participants() as $participant) {
            $participant->afterDelete($id);
        }
    }

    protected function afterClose(int $id): void
    {
        $this->schedule->end($id, DateConversion::fromFa(\Today()));
        foreach ($this->participants() as $participant) {
            $participant->afterClose($id);
        }
    }
```

- [ ] **Step 9: Run to see them pass, and prove the schema is still the generator's**

Run: `docker/fa-graphql test --testsuite unit --filter 'ExtensionLoaderTest|LoadedExtensionsTest|SchemaAssemblerTest|ApiSchemaTest|SalesOrder'`
Expected: PASS.

Run: `docker/fa-graphql test --testsuite integration --filter 'ExtensionDiscoveryTest|SalesOrderParticipantTest|SalesOrder'`
Expected: PASS.

Run on the host: `bin/generate --dry-run`
Expected: only `current` and `kept` lines (no `updated`, no `written`, no diff for `src/ApiSchema.php`).

Run: `docker/fa-graphql test`
Expected: PASS (the whole suite; the known upstream `CompatDriftTest` skip only). Record the table row counts before and after (`docker/fa-graphql db shell`) — unchanged.

- [ ] **Step 10: Spec notes** — in `graphql:docs/superpowers/specs/2026-09-28-release-4-extensions-recurring-design.md`:
  - §2.4: change `validate(array $input, int $index): void` to `validate(array $input): void — before any write; throws BadInput (ServiceCall adds the batch index)` and append *(revised: SalesOrderService does not know the batch index; ServiceCall::each already tags errors with it)*.
  - §2.5: replace the first bullet with "An extension may not add a root field, type field or input field that an earlier extension already has; the later extension is dropped whole. A contribution whose name the **core** already has is dropped on its own, and the core field wins *(revised: core types are built while the schema is assembled, after the extension was accepted; dropping it whole would need a second build)*." and change "`E_USER_WARNING`" to "the PHP error log (`graphql extension <name>: <reason>; dropped`)".
  - §2.1: append "*(revised)* The hooks are walked by `Extensions` itself with `hook_invoke_all()`'s gettext bracketing, so one throwing hook is logged and does not stop the others."

- [ ] **Step 11: Gates and commit** (graphql, `feature/release-4`)

```bash
docker/fa-graphql lint && docker/fa-graphql analyze
git add src/Extension container.php src/Fa/Service/SalesOrderService.php src/Type/SalesOrder \
    tests/Support/Extension tests/Unit/Extension tests/Integration/Extension \
    docs/superpowers/specs/2026-09-28-release-4-extensions-recurring-design.md
git commit -m "Extension contract: other FrontAccounting extensions can add to the API

An Extension registers from its hooks class's graphql_extensions() for
the companies where it is active. ExtensionLoader drops a bad one with
a log line; LoadedExtensions feeds root fields to SchemaAssembler, which
leaves ApiSchema's generated literals alone, and contributions to the
sales order Type and inputs. SalesOrderParticipants run inside the
order's transaction. This module's own recurrence still runs.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013U8a6JAbsHc4c1KcSLiaYK"
```

If PHPStan cannot see `set_ext_domain` or `\hooks`, add `../../includes/lang/gettext.inc` and `../../includes/hooks.inc` to `phpstan.neon`'s `scanFiles` (as earlier releases did for other FrontAccounting files) and commit that with the rest.

---

### Checkpoint A — after Task 1 (the extension contract)

The contract is what `sgw_sales` — and any later extension — will be built against; changing it after Task 2 costs two repositories. Run in graphql, on `feature/release-4`.

- [ ] **Independent review.** A reviewer who did not write Task 1 (most capable model) reviews `git diff <plan commit>..HEAD` at medium effort (`/code-review medium`-equivalent), correctness first:
  - an extension cannot break the API: every method of `Extension` and every hook is called inside a `try`, and a failure drops only that extension (`ExtensionLoader`, `Extensions`);
  - no extension field reaches a company where its hook object is not installed (`Extensions::loaded()` uses only `$GLOBALS['Hooks']` after `FaSession::openCompany()`; nothing is cached before a company is open);
  - `SchemaAssembler` never mutates `ApiSchema` or its types' configs (it builds new `Query`/`Mutation` types from copies) and returns the core instance when there is nothing to add;
  - participants run inside the order's `FaTransaction` (via the caller's `ServiceCall`) and their exceptions are not caught anywhere in `SalesOrderService`;
  - `isRecurringOrder()` still honours this module's own schedule and now ORs the participants;
  - the container wires `Extensions` into `SalesOrderService` and the three extensible types explicitly (an autowired optional parameter would be `null`);
  - PHP 7.4 syntax; PSR-12; no test that asserts nothing.
  Findings go to `.superpowers/sdd/<plan>/checkpoint-A-review.md` with severity, file:line, scenario and fix.
- [ ] **Spec walk — §2.** For each requirement, name the test or the code that meets it, or record the deviation in the spec *(revised)*:
  - §2.1 discovery per company through the hook method; no runtime composer dependency; `interface_exists` guard documented on `Extension`.
  - §2.2 the interface's seven methods and their shapes; `AbstractExtension` defaults.
  - §2.3 `ExtensionContext`'s services.
  - §2.4 `SalesOrderParticipant` (with Task 1's revised `validate(array $input)`), called in order, inside the transaction; rollback proven by `SalesOrderParticipantTest`.
  - §2.5 every loader rule has an `ExtensionLoaderTest` case; the revised core-clash rule has `LoadedExtensionsTest` and `SchemaAssemblerTest` cases; log lines name the extension.
  - §2.6 assembly around the unedited `ApiSchema`; introspection shows the extension fields for the company (`ExtensionDiscoveryTest`).
- [ ] **Generator:** on the host, `bin/generate --dry-run` prints only `current`/`kept`.
- [ ] **Suites:** `docker/fa-graphql test`, `lint`, `analyze` green on the main stack (upstream, PHP 7.4); every table at its starting row count.
- [ ] **Fix and re-review:** Critical and Important findings are fixed in one round (commit `Address Release 4 Checkpoint A review`, with the two trailer lines), followed by one scoped re-review of that fix diff. Minors are ledgered.

---

### Task 2: sgw_sales — the GraphQL extension takes over recurrence

Spec §3. Work in `sgw_sales` on a new branch `feature/graphql-extension` from `master` (31eb202). The module (`graphql`) is on `feature/release-4` with Task 1 committed: the extension contract exists, and the module's **own** recurrence (`RecurringSchedule`, the three recurrence types, `recurring` on `SalesOrderType` and the order inputs) is still in place.

**Why the end-to-end tests skip in this task.** While the module still serves `recurring`, `ExtensionLoader` (spec §2.5) drops `sgw_sales` whole: its `recurring` field and its `Recurrence*` type names clash with the core's. That is the merge order of spec §6 working as designed — at every step exactly one side serves `recurring`. So this task proves the extension's parts directly (the columns mapping, the participant against FrontAccounting in-process, registration through the hook) and writes the end-to-end tests guarded by "the extension is loaded"; they skip with a named reason until Task 3 removes the module's own recurrence, and Task 3's gate requires them to run with no skips.

**Files:**
- Create: `sgw_sales:includes/GraphQL/SgwSalesExtension.php`
- Create: `sgw_sales:includes/GraphQL/RecurrenceParticipant.php`
- Create: `sgw_sales:includes/GraphQL/Type/RecurrenceRepeatsType.php`, `sgw_sales:includes/GraphQL/Type/RecurrenceType.php`, `sgw_sales:includes/GraphQL/Type/RecurrenceInputType.php`
- Modify: `sgw_sales:hooks.php` (the `graphql_extensions` hook; `activate_extension` applies `update_1.4.sql`)
- Modify: `sgw_sales:sql/update_1.4.sql` (the upgrade-helper queries move out)
- Create: `sgw_sales:sql/helpers/update_1.4-duplicates.sql`
- Create: `sgw_sales:phpunit-graphql.xml`, `sgw_sales:tests/GraphQL/bootstrap.php`
- Create: `sgw_sales:tests/GraphQL/ExtensionTestCase.php`
- Create: `sgw_sales:tests/GraphQL/Unit/RecurrenceColumnsTest.php` (port of `graphql:tests/Unit/Fa/RecurrenceColumnsTest.php`)
- Create: `sgw_sales:tests/GraphQL/Integration/RecurrenceParticipantTest.php`
- Create: `sgw_sales:tests/GraphQL/Integration/SalesOrderRecurrenceTest.php` (port of `graphql:tests/Integration/SalesOrder/SalesOrderRecurrenceTest.php`)
- Create: `sgw_sales:tests/GraphQL/Integration/RegistrationTest.php`
- Create: `sgw_sales:tests/GraphQL/Integration/RecurrenceSchemaSnapshotTest.php`, `sgw_sales:tests/GraphQL/fixtures/recurrence-schema.json` (captured from the module's current schema)
- Create: `sgw_sales:tests/Unit/ActivateExtensionTest.php`
- Modify: `sgw_sales:.github/workflows/ci.yml` (a job running the extension tests inside the module's stack)
- Modify: `sgw_sales:README.md`, `sgw_sales:includes/db/SalesRecurringModel.php` (docblock: the second write path)
- Test: `sgw_sales:tests/GraphQL/**`, `sgw_sales:tests/Unit/ActivateExtensionTest.php`

**Interfaces:**
- Contract rulings this task relies on (CONTRACT.md "Rulings after writing"): a clash drops the whole extension; `validate()` has no index; `Extensions` walks `$GLOBALS['Hooks']` itself (a hook that throws does not stop the others) — `hooks_sgw_sales::graphql_extensions()` is still the method it calls, so `hook_invoke_all()` in the registration test reaches it the same way.
- Consumes (module, Task 1): `FA\GraphQL\Extension\AbstractExtension`, `Extension`, `ExtensionContext` (`container()`), `SalesOrderParticipant` (`validate(array $input): void` — no index: `ServiceCall::each` tags a refusal with its item's index (contract ruling), `isRelaxed(int $orderId, array $input): bool`, `afterCreate(int $orderId, array $input): void`, `afterUpdate(int $orderId, array $input): void`, `afterDelete(int $orderId): void`, `afterClose(int $orderId): void`), `ExtensionRegistry` (`register()`, `all()`, `HOOK = 'graphql_extensions'`), `Extensions::loaded(): LoadedExtensions` (`names(): array`). Also `FA\GraphQL\Error\BadInput`, `FaRejected`, `FA\GraphQL\Fa\CompanyContext`, `DateConversion`, `ServiceCall`, `\Anorm\GraphQL\Type\DateType::instance()`, `\Anorm\GraphQL\Builder\FieldBuilder`. Tests extend `FA\GraphQL\Tests\Integration\SalesOrder\SalesOrderTestCase` (the module's autoload-dev, present in the module's stack).
- Produces:
  - `SGW_Sales\GraphQL\SgwSalesExtension extends AbstractExtension` — `name(): 'sgw_sales'`, `contractVersion(): '1.0'`, `typeFields()` → `['SalesOrderType' => ['recurring' => …]]`, `inputFields()` → `['SalesOrderCreateInput' => ['recurring' => …], 'SalesOrderUpdateInput' => ['recurring' => …]]`, `participants()` → `[RecurrenceParticipant]`.
  - `SGW_Sales\GraphQL\RecurrenceParticipant implements SalesOrderParticipant` — plus `isAvailable(): bool`, `assertWritable(string $field): void`, `read(int $orderNo): ?array`, `write(int $orderNo, array $recurrence): void`, `delete(int $orderNo): void`, `end(int $orderNo, string $isoDate): void`, `snapshot(int $orderNo): ?array` (the schedule as it was before this request deleted or closed its order, else `read()`), `static toColumns(array $r): array`, `static fromRow(array $row): array`. One instance per request: always obtained from the container (`$c->container()->get(RecurrenceParticipant::class)`).
  - GraphQL names unchanged from Release 2: `Recurrence`, `RecurrenceInput`, `RecurrenceRepeats` (`MONTH` → `'month'`, `YEAR` → `'year'`), field `recurring` on `SalesOrderType`, `SalesOrderCreateInput`, `SalesOrderUpdateInput`.
  - `hooks_sgw_sales::graphql_extensions(&$registry, $opts = null)`.
  - `sgw_sales:phpunit-graphql.xml` with suites `graphql-unit` and `graphql-integration`, run from the module's directory with the module's phpunit (Task 3's `docker/fa-graphql test-extension sgw_sales`).

- [ ] **Step 1: Branch, and a stack that sees this checkout**

```bash
cd /home/cambell/src/sgw/frontaccounting/modules/sgw_sales
git checkout master && git pull --ff-only
git checkout -b feature/graphql-extension
cd ../graphql
git checkout feature/release-4
# The module's stack bind-mounts the host sgw_sales over the clone in the image
# (docker/docker-compose.sgw-sales.yml). It needs its own vendor/ with dev
# dependencies for its own suites; the extension suites use the module's phpunit.
SGW_SALES_PATH=../sgw_sales docker/fa-graphql up --recreate
docker/fa-graphql exec ls /var/www/html/modules/sgw_sales/hooks.php
```

Expected: the file is listed. Every `docker/fa-graphql` command in this task is run with `SGW_SALES_PATH=../sgw_sales` in the environment (export it in the shell), from the module directory.

Check two things before writing code:

```bash
# 1. The module's Task 1 contract is there.
ls src/Extension/Extension.php src/Extension/SalesOrderParticipant.php src/Extension/Extensions.php
# 2. The module's test bases autoload in the stack (autoload-dev installed).
docker/fa-graphql exec php -r 'require "vendor/autoload.php"; var_dump(class_exists("FA\\GraphQL\\Tests\\Integration\\SalesOrder\\SalesOrderTestCase"));'
```

Expected: the three files exist; `bool(true)`. If Task 1 named a method differently from the Interfaces block above, use Task 1's name everywhere below and record it under Deviations.

- [ ] **Step 2: The extension suites' runner config and bootstrap**

`sgw_sales:phpunit-graphql.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!--
	The GraphQL extension's suites. They need the FrontAccounting GraphQL module
	(modules/graphql) beside this module, its vendor/ with dev dependencies, and its
	docker stack: run them from the module with
	`docker/fa-graphql test-extension sgw_sales`. This module's own suites are
	phpunit.xml's and run on its own stack.
-->
<phpunit
	xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/9.6/phpunit.xsd"
	bootstrap="tests/GraphQL/bootstrap.php"
	colors="true"
	convertDeprecationsToExceptions="false"
>
	<testsuites>
		<testsuite name="graphql-unit">
			<directory>tests/GraphQL/Unit</directory>
		</testsuite>
		<testsuite name="graphql-integration">
			<directory>tests/GraphQL/Integration</directory>
		</testsuite>
	</testsuites>
</phpunit>
```

`sgw_sales:tests/GraphQL/bootstrap.php`:

```php
<?php

/**
 * The GraphQL extension's suites run inside the FrontAccounting GraphQL module's
 * stack, with the module's autoloader (its classes, its test bases, phpunit) and
 * this module's own. This module's autoload-dev is not relied on: the image
 * installs sgw_sales with --no-dev, so SGW_Sales\Tests\GraphQL\ is mapped here.
 */

$graphql = dirname(__DIR__, 3) . '/graphql/vendor/autoload.php';
if (!is_file($graphql)) {
    fwrite(STDERR, "The GraphQL module's vendor/ is not at $graphql. Run these suites from the module:\n"
        . "  docker/fa-graphql test-extension sgw_sales\n");
    exit(1);
}
require_once $graphql;
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

spl_autoload_register(function (string $class): void {
    $prefix = 'SGW_Sales\\Tests\\GraphQL\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});
```

Run (it will find no tests yet):

```bash
docker/fa-graphql exec php vendor/bin/phpunit -c /var/www/html/modules/sgw_sales/phpunit-graphql.xml
```

Expected: `No tests executed!` (exit 0) — the bootstrap loads.

- [ ] **Step 3: Capture the Release 2 recurrence schema, from the module as it is now**

The snapshot the extension must match is what the module serves today. Capture it by introspection through the module's own schema, before Task 3 removes it.

`sgw_sales:tests/GraphQL/Integration/RecurrenceSchemaSnapshotTest.php`:

```php
<?php

namespace SGW_Sales\Tests\GraphQL\Integration;

use GraphQL\GraphQL;
use GraphQL\Type\Schema;
use SGW_Sales\Tests\GraphQL\ExtensionTestCase;

/**
 * Spec §3.3: with sgw_sales active, `recurring` is exactly Release 2's — names,
 * types, nullability, defaults, descriptions. The fixture was captured from the
 * GraphQL module's own schema before recurrence moved here
 * (CAPTURE_RECURRENCE_SNAPSHOT=1 while the module still served it).
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RecurrenceSchemaSnapshotTest extends ExtensionTestCase
{
    private const FIXTURE = __DIR__ . '/../fixtures/recurrence-schema.json';

    private const QUERY = <<<'GQL'
fragment T on __Type { kind name ofType { kind name ofType { kind name ofType { kind name } } } }
{
  recurrence: __type(name: "Recurrence") { name description fields { name description type { ...T } } }
  recurrenceInput: __type(name: "RecurrenceInput") {
    name description inputFields { name description defaultValue type { ...T } }
  }
  repeats: __type(name: "RecurrenceRepeats") { name description enumValues { name description } }
  order: __type(name: "SalesOrderType") { fields { name description type { ...T } } }
  create: __type(name: "SalesOrderCreateInput") { inputFields { name description defaultValue type { ...T } } }
  update: __type(name: "SalesOrderUpdateInput") { inputFields { name description defaultValue type { ...T } } }
}
GQL;

    public function testTheRecurrenceSchemaIsRelease2s(): void
    {
        if (getenv('CAPTURE_RECURRENCE_SNAPSHOT') === '1') {
            $this->capture();

            return;
        }
        $this->requireExtensionServesRecurrence();

        $this->assertSame(
            json_decode((string) file_get_contents(self::FIXTURE), true),
            $this->recurrenceSchema()
        );
    }

    /**
     * Once, before Task 3: writes the fixture from the module's own recurrence.
     */
    private function capture(): void
    {
        if ($this->extensionLoaded()) {
            $this->fail('Capture from the module as it was: the sgw_sales extension already serves recurring.');
        }
        if (!is_dir(dirname(self::FIXTURE))) {
            mkdir(dirname(self::FIXTURE), 0777, true);
        }
        file_put_contents(
            self::FIXTURE,
            json_encode($this->recurrenceSchema(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, mixed>
     */
    private function recurrenceSchema(): array
    {
        $result = GraphQL::executeQuery($this->container->get(Schema::class), self::QUERY)->toArray();
        $this->assertArrayNotHasKey('errors', $result);
        $data = $result['data'];
        $pick = function (?array $fields): array {
            $picked = array_values(array_filter($fields ?? [], function (array $field): bool {
                return $field['name'] === 'recurring';
            }));

            return $picked;
        };

        return [
            'Recurrence' => $data['recurrence'],
            'RecurrenceInput' => $data['recurrenceInput'],
            'RecurrenceRepeats' => $data['repeats'],
            'SalesOrderType.recurring' => $pick($data['order']['fields'] ?? null),
            'SalesOrderCreateInput.recurring' => $pick($data['create']['inputFields'] ?? null),
            'SalesOrderUpdateInput.recurring' => $pick($data['update']['inputFields'] ?? null),
        ];
    }
}
```

`sgw_sales:tests/GraphQL/ExtensionTestCase.php`:

```php
<?php

namespace SGW_Sales\Tests\GraphQL;

use FA\GraphQL\Extension\Extensions;
use FA\GraphQL\Tests\Integration\SalesOrder\SalesOrderTestCase;
use SGW_Sales\GraphQL\RecurrenceParticipant;

/**
 * The GraphQL module's sales-order test base (FrontAccounting in-process, signed
 * in as apitest, every order it makes purged in tearDown), plus what an extension
 * test needs to know: whether this extension is loaded for the company.
 *
 * Every subclass must carry
 *
 *     @runTestsInSeparateProcesses
 *     @preserveGlobalState disabled
 */
abstract class ExtensionTestCase extends SalesOrderTestCase
{
    protected function extensionLoaded(): bool
    {
        return in_array('sgw_sales', $this->container->get(Extensions::class)->loaded()->names(), true);
    }

    /**
     * Spec §6: while the GraphQL module still serves `recurring` itself, its loader
     * drops this extension (the field and the Recurrence* type names clash). The
     * module's Task 3 removes its own; from then on these tests must run, not skip.
     */
    protected function requireExtensionServesRecurrence(): void
    {
        if (!$this->extensionLoaded()) {
            $this->markTestSkipped(
                'The GraphQL module still serves recurring itself, so its loader drops the sgw_sales extension '
                . '(Release 4 plan, Task 3 removes it).'
            );
        }
        if (!$this->participant()->isAvailable()) {
            $this->markTestSkipped('sales_recurring is not at its update_1.4.sql shape in this stack.');
        }
    }

    protected function participant(): RecurrenceParticipant
    {
        return $this->container->get(RecurrenceParticipant::class);
    }
}
```

Run the capture (the module still serves `recurring`; `RecurrenceParticipant` does not exist yet, but `capture()` never touches it — only `extensionLoaded()`, which returns false because nothing registers `sgw_sales` yet):

```bash
docker/fa-graphql exec env CAPTURE_RECURRENCE_SNAPSHOT=1 php vendor/bin/phpunit \
  -c /var/www/html/modules/sgw_sales/phpunit-graphql.xml --filter RecurrenceSchemaSnapshotTest
cat ../sgw_sales/tests/GraphQL/fixtures/recurrence-schema.json | head -40
```

Expected: `OK (1 test, …)`; the fixture holds `Recurrence` (8 fields: `start`, `end`, `next`, `repeats`, `every`, `day`, `monthDay`, `auto`), `RecurrenceInput` (7 input fields, `auto` with `defaultValue` `"true"`), `RecurrenceRepeats` (`MONTH`, `YEAR`), and one `recurring` entry for each of the three sales-order types. If any of the six keys is null or an empty list, the capture ran against the wrong module state — stop and check Task 1 left recurrence in place.

Run it without the variable:

```bash
docker/fa-graphql exec php vendor/bin/phpunit -c /var/www/html/modules/sgw_sales/phpunit-graphql.xml --filter RecurrenceSchemaSnapshotTest
```

Expected: `OK, but incomplete, skipped, or risky tests!` — skipped, "The GraphQL module still serves recurring itself…". Commit the fixture now so the capture is recorded against the module commit it came from:

```bash
cd ../sgw_sales
git add phpunit-graphql.xml tests/GraphQL/bootstrap.php tests/GraphQL/ExtensionTestCase.php \
  tests/GraphQL/Integration/RecurrenceSchemaSnapshotTest.php tests/GraphQL/fixtures/recurrence-schema.json
git commit -m "GraphQL extension suites; capture the Release 2 recurrence schema

The fixture is the GraphQL module's own recurrence, introspected before it
moves here (graphql feature/release-4 at Task 1). The snapshot test skips until
the module stops serving recurring itself.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013U8a6JAbsHc4c1KcSLiaYK"
cd ../graphql
```

- [ ] **Step 4: Write the failing unit test — the columns mapping, ported**

`sgw_sales:tests/GraphQL/Unit/RecurrenceColumnsTest.php` is `graphql:tests/Unit/Fa/RecurrenceColumnsTest.php` with exactly these changes:

```bash
sed -e 's/^namespace FA\\GraphQL\\Tests\\Unit\\Fa;/namespace SGW_Sales\\Tests\\GraphQL\\Unit;/' \
    -e 's/use FA\\GraphQL\\Fa\\Service\\RecurringSchedule;/use SGW_Sales\\GraphQL\\RecurrenceParticipant;/' \
    -e 's/RecurringSchedule::/RecurrenceParticipant::/g' \
    tests/Unit/Fa/RecurrenceColumnsTest.php > ../sgw_sales/tests/GraphQL/Unit/RecurrenceColumnsTest.php
grep -n 'RecurringSchedule\|namespace' ../sgw_sales/tests/GraphQL/Unit/RecurrenceColumnsTest.php
```

Expected: one `namespace SGW_Sales\Tests\GraphQL\Unit;` line and no `RecurringSchedule`. Every other line — the four tests, their data provider and assertions — is unchanged: the mapping moves, its behaviour does not.

```bash
docker/fa-graphql exec php vendor/bin/phpunit -c /var/www/html/modules/sgw_sales/phpunit-graphql.xml --testsuite graphql-unit
```

Expected: FAIL — `Class "SGW_Sales\GraphQL\RecurrenceParticipant" not found`.

- [ ] **Step 5: The three types, ported**

`sgw_sales:includes/GraphQL/Type/RecurrenceRepeatsType.php`:

```php
<?php

namespace SGW_Sales\GraphQL\Type;

use GraphQL\Type\Definition\EnumType;

/**
 * How a recurring order repeats. The values are this module's own column values
 * (sales_recurring.repeats), so a parsed input needs no mapping. Moved from the
 * FrontAccounting GraphQL module unchanged (Release 4 spec §3.3).
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

`sgw_sales:includes/GraphQL/Type/RecurrenceType.php`:

```php
<?php

namespace SGW_Sales\GraphQL\Type;

use Anorm\GraphQL\Type\DateType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * An order's recurring schedule, nested in the order (Release 2 spec §4.5;
 * Release 4 spec §3). Moved from the FrontAccounting GraphQL module unchanged.
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

`sgw_sales:includes/GraphQL/Type/RecurrenceInputType.php`:

```php
<?php

namespace SGW_Sales\GraphQL\Type;

use Anorm\GraphQL\Type\DateType;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\Type;

/**
 * A whole recurring schedule; on an update it replaces the one there. To end one,
 * give it an end. Moved from the FrontAccounting GraphQL module unchanged.
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

The three are resolved through the request's container (PHP-DI autowiring gives one instance per request, so one `RecurrenceRepeats` per schema).

- [ ] **Step 6: The participant — a port of `RecurringSchedule`**

`sgw_sales:includes/GraphQL/RecurrenceParticipant.php`:

```php
<?php

namespace SGW_Sales\GraphQL;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\FaRejected;
use FA\GraphQL\Extension\SalesOrderParticipant;
use FA\GraphQL\Fa\CompanyContext;
use FA\GraphQL\Fa\DateConversion;

/**
 * An order's recurring schedule — this module's sales_recurring row — kept in step
 * with the order by the FrontAccounting GraphQL module's SalesOrderService, inside
 * the order's transaction (Release 4 spec §2.4, §3.2).
 *
 * Written with FrontAccounting's db_query(), on FrontAccounting's connection — not
 * with SalesRecurringModel, which writes on Anorm's own PDO connection, outside the
 * order's transaction. That is this module's second write path to the table; the
 * page keeps using the model. The columns mean what sales_order_entry.php makes them
 * mean (:549-576): occur is "MM-DD" for a yearly schedule and the day of the month
 * for a monthly one; dt_next is the generation service's own state, NULL until it
 * computes it (includes/service/RecurrenceSchedule.php).
 *
 * Moved from the GraphQL module's RecurringSchedule (Release 2) with two changes:
 * it no longer asks whether sgw_sales is active — the module only loads this
 * extension for a company where it is — and it keeps what a delete or a close
 * replaced, so the mutation returns the schedule as it was (snapshot()).
 *
 * One per request: always from the request's container.
 */
final class RecurrenceParticipant implements SalesOrderParticipant
{
    private ?bool $upgraded = null;

    /** @var array<int, array<string, mixed>|null> the schedule before this request deleted or closed its order */
    private array $before = [];

    public function isAvailable(): bool
    {
        return $this->isUpgraded();
    }

    /**
     * Active but without update_1.4.sql's table shape is the installation's problem
     * (FA_REJECTED) — activate_extension() applies it from Release 4 on; a company
     * activated earlier must be re-activated.
     */
    public function assertWritable(string $field): void
    {
        if (!$this->isUpgraded()) {
            $message = 'sgw_sales\' table ' . CompanyContext::prefix() . 'sales_recurring is missing or not '
                . 'upgraded: apply modules/sgw_sales/sql/update_1.4.sql.';
            throw new FaRejected($message, [$message]);
        }
    }

    public function validate(array $input): void
    {
        if (!array_key_exists('recurring', $input) || $input['recurring'] === null) {
            return;
        }
        // Refused before anything is written: a table not upgraded, or a bad schedule.
        $this->assertWritable('recurring');
        self::toColumns($input['recurring']);
    }

    /**
     * This module keys its relaxations to its "Recurring Order" box: an order that has
     * a schedule, or is being given one now, keeps its header editable once delivered
     * and has no delivered-quantity floor (sales_order_entry.php :616-617, :827).
     */
    public function isRelaxed(int $orderId, array $input): bool
    {
        return (array_key_exists('recurring', $input) && $input['recurring'] !== null)
            || $this->read($orderId) !== null;
    }

    public function afterCreate(int $orderId, array $input): void
    {
        if (array_key_exists('recurring', $input) && $input['recurring'] !== null) {
            $this->write($orderId, $input['recurring']);
        }
    }

    public function afterUpdate(int $orderId, array $input): void
    {
        if (array_key_exists('recurring', $input) && $input['recurring'] !== null) {
            $this->write($orderId, $input['recurring']);
        }
    }

    public function afterDelete(int $orderId): void
    {
        $this->before[$orderId] = $this->read($orderId);
        $this->delete($orderId);
    }

    public function afterClose(int $orderId): void
    {
        $this->before[$orderId] = $this->read($orderId);
        $this->end($orderId, DateConversion::fromFa(\Today()));
    }

    /**
     * What the `recurring` field shows: the schedule as it was before this request
     * deleted or closed the order (a delete returns the order as it was), otherwise
     * the schedule now.
     *
     * @return array<string, mixed>|null
     */
    public function snapshot(int $orderNo): ?array
    {
        if (array_key_exists($orderNo, $this->before)) {
            return $this->before[$orderNo];
        }

        return $this->read($orderNo);
    }

    /**
     * @return array<string, mixed>|null a Recurrence, or null: none, or the table not upgraded
     */
    public function read(int $orderNo): ?array
    {
        if (!$this->isUpgraded()) {
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
                'INSERT INTO ' . TB_PREF . 'sales_recurring'
                . ' (trans_no, dt_start, dt_end, dt_next, auto, every, repeats, occur)'
                . ' VALUES (' . db_escape($orderNo) . ', ' . db_escape($c['dt_start'])
                . ', ' . self::sqlDate($c['dt_end']) . ', NULL, ' . $c['auto'] . ', ' . $c['every']
                . ', ' . db_escape($c['repeats']) . ', ' . db_escape($c['occur']) . ')',
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
     * enum's value ('month' | 'year'), which is this module's own.
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

`validate()` takes no index (contract ruling): `ServiceCall::each` tags a `BadInput` or `FaRejected` thrown inside an item with that item's index (Release 2 spec §3.1, Release 3 Checkpoint B I-1).

```bash
docker/fa-graphql exec php vendor/bin/phpunit -c /var/www/html/modules/sgw_sales/phpunit-graphql.xml --testsuite graphql-unit
```

Expected: PASS — the four ported tests, unchanged.

- [ ] **Step 7: Write the failing integration tests — the participant against FrontAccounting**

These call the participant directly, on orders the module's own `SalesOrderService` writes without a schedule — the extension does not need to be loaded.

`sgw_sales:tests/GraphQL/Integration/RecurrenceParticipantTest.php`:

```php
<?php

namespace SGW_Sales\Tests\GraphQL\Integration;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\FaRejected;
use FA\GraphQL\Fa\CompanyContext;
use FA\GraphQL\Fa\Service\ServiceCall;
use SGW_Sales\GraphQL\RecurrenceParticipant;
use SGW_Sales\Tests\GraphQL\ExtensionTestCase;

/**
 * The participant on its own, against FrontAccounting in-process: what the module's
 * SalesOrderService will call inside an order's transaction (Release 4 spec §2.4).
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RecurrenceParticipantTest extends ExtensionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->participant()->isAvailable()) {
            $this->markTestSkipped('sales_recurring is not at its update_1.4.sql shape in this stack.');
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

    public function testTheContainerGivesOneParticipantPerRequest(): void
    {
        $this->assertSame(
            $this->container->get(RecurrenceParticipant::class),
            $this->container->get(RecurrenceParticipant::class)
        );
    }

    public function testAfterCreateWritesTheScheduleAndReadGivesItBack(): void
    {
        $orderNo = $this->createOrder();
        ServiceCall::run(function () use ($orderNo): void {
            $this->participant()->afterCreate($orderNo, ['recurring' => $this->monthly()]);
        });

        $row = $this->scheduleRow($orderNo);
        $this->assertSame($this->today(), $row['dt_start']);
        $this->assertNull($row['dt_next'], 'the generation service computes it');
        $this->assertSame('15', $row['occur']);
        $read = $this->participant()->read($orderNo);
        $this->assertSame('month', $read['repeats']);
        $this->assertSame(15, $read['day']);
    }

    public function testNoRecurringInTheInputWritesNothing(): void
    {
        $orderNo = $this->createOrder();
        ServiceCall::run(function () use ($orderNo): void {
            $this->participant()->afterCreate($orderNo, ['recurring' => null]);
            $this->participant()->afterUpdate($orderNo, []);
        });

        $this->assertNull($this->scheduleRow($orderNo));
        $this->assertFalse($this->participant()->isRelaxed($orderNo, []));
        $this->assertTrue($this->participant()->isRelaxed($orderNo, ['recurring' => $this->monthly()]));
    }

    public function testValidateRefusesABadScheduleNamingItsField(): void
    {
        try {
            $this->participant()->validate(['recurring' => $this->monthly(['day' => null])]);
            $this->fail('accepted');
        } catch (BadInput $e) {
            $this->assertSame('recurring.day', $e->field());
        }
        $this->participant()->validate(['recurring' => null]);
        $this->addToAssertionCount(1);
    }

    public function testAnExistingScheduleRelaxesTheOrder(): void
    {
        $orderNo = $this->createOrder();
        ServiceCall::run(function () use ($orderNo): void {
            $this->participant()->afterCreate($orderNo, ['recurring' => $this->monthly()]);
        });

        $this->assertTrue($this->container->get(RecurrenceParticipant::class)->isRelaxed($orderNo, []));
    }

    public function testDeleteRemovesTheRowButTheSnapshotKeepsItForThisRequest(): void
    {
        $orderNo = $this->createOrder();
        ServiceCall::run(function () use ($orderNo): void {
            $this->participant()->afterCreate($orderNo, ['recurring' => $this->monthly()]);
            $this->participant()->afterDelete($orderNo);
        });

        $this->assertNull($this->scheduleRow($orderNo));
        $this->assertNull($this->participant()->read($orderNo));
        $this->assertSame(15, $this->participant()->snapshot($orderNo)['day'], 'as it was before the delete');
    }

    public function testCloseEndsTheScheduleTodayUnlessItEndsEarlier(): void
    {
        $first = $this->createOrder();
        $second = $this->createOrder();
        ServiceCall::run(function () use ($first, $second): void {
            $this->participant()->afterCreate($first, ['recurring' => $this->monthly()]);
            $this->participant()->afterCreate($second, ['recurring' => $this->monthly()]);
        });
        $this->pdo()->prepare("UPDATE 0_sales_recurring SET dt_end = '2000-01-01' WHERE trans_no = ?")
            ->execute([$second]);

        ServiceCall::run(function () use ($first, $second): void {
            $this->participant()->afterClose($first);
            $this->participant()->afterClose($second);
        });

        $this->assertSame($this->today(), $this->scheduleRow($first)['dt_end']);
        $this->assertSame('2000-01-01', $this->scheduleRow($second)['dt_end']);
        $this->assertNull($this->participant()->snapshot($first)['end'], 'the snapshot is before the close');
    }

    public function testATransactionThatFailsTakesTheScheduleWithIt(): void
    {
        $orderNo = $this->createOrder();
        try {
            ServiceCall::run(function () use ($orderNo): void {
                $this->participant()->afterCreate($orderNo, ['recurring' => $this->monthly()]);
                throw new BadInput('the order after it was refused', 'lines');
            });
            $this->fail('accepted');
        } catch (BadInput $e) {
            $this->assertSame('lines', $e->field());
        }

        $this->assertNull($this->scheduleRow($orderNo), 'rolled back with the transaction');
    }

    /**
     * The table-shape check reads information_schema under the company's table
     * prefix; pointing the company at a prefix with no sales_recurring gives the
     * missing-table case without DDL (which would commit).
     */
    public function testATableNotAtThe14ShapeIsRejectedNamingTheScript(): void
    {
        $connection = ['tbpref' => 'gqlt_none_'] + $GLOBALS['db_connections'][0];
        CompanyContext::set(0, $connection);
        $participant = new RecurrenceParticipant();

        $this->assertFalse($participant->isAvailable());
        try {
            $participant->validate(['recurring' => $this->monthly()]);
            $this->fail('accepted');
        } catch (FaRejected $e) {
            $this->assertStringContainsString('gqlt_none_sales_recurring', $e->getMessage());
            $this->assertStringContainsString('update_1.4.sql', $e->getMessage());
            $this->assertSame([$e->getMessage()], $e->getExtensions()['messages'] ?? null);
        }
        $this->assertNull($participant->read(1), 'and a schedule reads as none');
    }
}
```

```bash
docker/fa-graphql exec php vendor/bin/phpunit -c /var/www/html/modules/sgw_sales/phpunit-graphql.xml --filter RecurrenceParticipantTest
```

Expected: PASS — the participant exists (Step 6). If a test fails, fix `RecurrenceParticipant`, not the test: its behaviour is Release 2's `RecurringSchedule`'s, which these assertions restate. The rollback test must pass: if it does not, `ServiceCall::run` is not wrapping `FaTransaction` the way Release 2's plumbing does — stop and check the module.

- [ ] **Step 8: Write the failing registration test**

`sgw_sales:tests/GraphQL/Integration/RegistrationTest.php`:

```php
<?php

namespace SGW_Sales\Tests\GraphQL\Integration;

use FA\GraphQL\Extension\ExtensionContext;
use FA\GraphQL\Extension\ExtensionRegistry;
use SGW_Sales\GraphQL\RecurrenceParticipant;
use SGW_Sales\GraphQL\SgwSalesExtension;
use SGW_Sales\GraphQL\Type\RecurrenceInputType;
use SGW_Sales\GraphQL\Type\RecurrenceType;
use SGW_Sales\Tests\GraphQL\ExtensionTestCase;

/**
 * Release 4 spec §2.1, §3.1: the module finds this extension through
 * FrontAccounting's hooks, per company.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RegistrationTest extends ExtensionTestCase
{
    public function testTheHookRegistersTheExtension(): void
    {
        $registry = new ExtensionRegistry();
        hook_invoke_all(ExtensionRegistry::HOOK, $registry);

        $names = array_map(function ($extension): string {
            return $extension->name();
        }, $registry->all());
        $this->assertContains('sgw_sales', $names);
    }

    public function testNotActiveForTheCompanyItIsNotRegistered(): void
    {
        // install_hooks() puts an extension in $Hooks only when it is active for the
        // company; removing it is what an inactive company looks like.
        unset($GLOBALS['Hooks']['sgw_sales']);
        $registry = new ExtensionRegistry();
        hook_invoke_all(ExtensionRegistry::HOOK, $registry);

        foreach ($registry->all() as $extension) {
            $this->assertNotSame('sgw_sales', $extension->name());
        }
    }

    public function testItContributesRecurringAndItsParticipant(): void
    {
        $extension = new SgwSalesExtension();
        $context = new ExtensionContext($this->container);

        $this->assertSame('sgw_sales', $extension->name());
        $this->assertSame('1.0', $extension->contractVersion());
        $this->assertSame([], $extension->queryFields($context));
        $this->assertSame([], $extension->mutationFields($context));

        $types = $extension->typeFields($context);
        $this->assertSame(['SalesOrderType'], array_keys($types));
        $this->assertSame(['recurring'], array_keys($types['SalesOrderType']));
        $this->assertSame($this->container->get(RecurrenceType::class), $types['SalesOrderType']['recurring']['type']);

        $inputs = $extension->inputFields($context);
        $this->assertSame(['SalesOrderCreateInput', 'SalesOrderUpdateInput'], array_keys($inputs));
        foreach ($inputs as $fields) {
            $this->assertSame(['recurring'], array_keys($fields));
            // Nullable (spec §2.5): the type is the input object itself, not NonNull.
            $this->assertSame($this->container->get(RecurrenceInputType::class), $fields['recurring']['type']);
        }

        $participants = $extension->participants($context);
        $this->assertCount(1, $participants);
        $this->assertSame($this->container->get(RecurrenceParticipant::class), $participants[0]);
    }

    public function testTheRecurringFieldReadsThroughTheParticipantsSnapshot(): void
    {
        $extension = new SgwSalesExtension();
        $field = $extension->typeFields(new ExtensionContext($this->container))['SalesOrderType']['recurring'];
        $orderNo = $this->createOrder();

        $resolve = $field['resolve'];
        $this->assertNull($resolve(['id' => (string) $orderNo], [], $this->container));
        $this->assertSame(['day' => 3], $resolve(['id' => (string) $orderNo, 'recurring' => ['day' => 3]], [], $this->container));
    }
}
```

```bash
docker/fa-graphql exec php vendor/bin/phpunit -c /var/www/html/modules/sgw_sales/phpunit-graphql.xml --filter RegistrationTest
```

Expected: FAIL — `Class "SGW_Sales\GraphQL\SgwSalesExtension" not found` (and `testTheHookRegistersTheExtension` fails: nothing registers `sgw_sales`).

- [ ] **Step 9: The extension and the hook**

`sgw_sales:includes/GraphQL/SgwSalesExtension.php`:

```php
<?php

namespace SGW_Sales\GraphQL;

use Anorm\GraphQL\Builder\FieldBuilder;
use FA\GraphQL\Extension\AbstractExtension;
use FA\GraphQL\Extension\ExtensionContext;
use SGW_Sales\GraphQL\Type\RecurrenceInputType;
use SGW_Sales\GraphQL\Type\RecurrenceType;

/**
 * This module's part of the FrontAccounting GraphQL API (Release 4 spec §3): an
 * order's recurring schedule, as the `recurring` field on sales orders and their
 * inputs, kept in step with the order by RecurrenceParticipant. Registered by
 * hooks_sgw_sales::graphql_extensions() — only where the GraphQL module is
 * installed, and only for a company where this module is active.
 *
 * Types and the participant come from the request's container: one of each per
 * request, so the schema holds one Recurrence, RecurrenceInput and
 * RecurrenceRepeats, and the field reads the participant that wrote.
 */
final class SgwSalesExtension extends AbstractExtension
{
    public function name(): string
    {
        return 'sgw_sales';
    }

    public function contractVersion(): string
    {
        return '1.0';
    }

    public function typeFields(ExtensionContext $c): array
    {
        $container = $c->container();

        return [
            'SalesOrderType' => [
                'recurring' => FieldBuilder::create('recurring', $container->get(RecurrenceType::class))
                    ->setDescription('The recurring schedule, when sgw_sales is active and the order has one.')
                    ->setResolver(function (array $row, $args, $context): ?array {
                        // A row that already carries its schedule (a snapshot) wins.
                        return array_key_exists('recurring', $row)
                            ? $row['recurring']
                            : $context->get(RecurrenceParticipant::class)->snapshot((int) $row['id']);
                    })
                    ->build(),
            ],
        ];
    }

    public function inputFields(ExtensionContext $c): array
    {
        $input = $c->container()->get(RecurrenceInputType::class);

        return [
            'SalesOrderCreateInput' => [
                'recurring' => FieldBuilder::create('recurring', $input)
                    ->setDescription('A recurring schedule. Needs the sgw_sales extension, active for the company.')
                    ->build(),
            ],
            'SalesOrderUpdateInput' => [
                'recurring' => FieldBuilder::create('recurring', $input)
                    ->setDescription('Set or replace the recurring schedule; to end it, give an end. Needs sgw_sales.')
                    ->build(),
            ],
        ];
    }

    public function participants(ExtensionContext $c): array
    {
        return [$c->container()->get(RecurrenceParticipant::class)];
    }
}
```

The two descriptions are Release 2's own (`graphql:src/Type/SalesOrder/SalesOrderCreateInput.php` :44, `SalesOrderUpdateInput.php` :55, `SalesOrderType.php` :68), so the snapshot test compares equal. Check `FieldBuilder::build()`'s output shape before relying on it — the registration test reads `['type']` and `['resolve']`:

```bash
docker/fa-graphql exec php -r 'require "vendor/autoload.php"; var_export(array_keys(\Anorm\GraphQL\Builder\FieldBuilder::create("x", \GraphQL\Type\Definition\Type::int())->setResolver(function () {})->build()));'
```

Expected: the keys include `name`, `type`, `resolve`. If Task 1's contract takes `name => config` arrays and `build()`'s array also carries `name`, that is fine (webonyx accepts it). If the resolver key is not `resolve`, adjust the registration test's `$field['resolve']` to the real key and record it.

`sgw_sales:hooks.php` — add the method after `activate_extension()` (FrontAccounting style: tabs):

```php
	/*
		The FrontAccounting GraphQL module's extension hook (its Release 4 spec
		§2.1): it calls hook_invoke_all('graphql_extensions', $registry) for the
		company it serves, so this runs only where that module is installed and this
		one is active for the company. The interface check keeps this module working
		without it: nothing of the GraphQL module is loaded otherwise.
	*/
	function graphql_extensions(&$registry, $opts = null)
	{
		if (interface_exists('FA\GraphQL\Extension\Extension')) {
			$registry->register(new \SGW_Sales\GraphQL\SgwSalesExtension());
		}
	}
```

```bash
docker/fa-graphql exec php vendor/bin/phpunit -c /var/www/html/modules/sgw_sales/phpunit-graphql.xml --filter RegistrationTest
```

Expected: PASS.

- [ ] **Step 10: The end-to-end tests, ported — skipped until Task 3**

`sgw_sales:tests/GraphQL/Integration/SalesOrderRecurrenceTest.php` is `graphql:tests/Integration/SalesOrder/SalesOrderRecurrenceTest.php` with these changes, and no others:

1. `namespace SGW_Sales\Tests\GraphQL\Integration;`, `class SalesOrderRecurrenceTest extends ExtensionTestCase` (`use SGW_Sales\Tests\GraphQL\ExtensionTestCase;`), and `use SGW_Sales\GraphQL\RecurrenceParticipant;` in place of `use FA\GraphQL\Fa\Service\RecurringSchedule;`.
2. `setUp()` becomes:

```php
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireExtensionServesRecurrence();
    }
```

3. `$this->container->get(RecurringSchedule::class)->read(…)` becomes `$this->participant()->read(…)` (two places).
4. `testWithoutSgwSalesRecurringIsBadInputAndReadsNull` is replaced — spec §3.3's one deliberate change: an inactive company's schema has no `recurring` at all:

```php
    /**
     * Release 4 spec §3.3: with sgw_sales not active for the company, `recurring`
     * is not in that company's schema — not BAD_INPUT and null as in Release 2.
     * Simulated in-process: install_hooks() puts an extension in $Hooks only when it
     * is active; a fresh container asks the hooks again.
     */
    public function testWithoutSgwSalesTheSchemaHasNoRecurring(): void
    {
        unset($GLOBALS['Hooks']['sgw_sales']);
        $factory = require dirname(__DIR__, 5) . '/graphql/container.php';
        $container = $factory(
            \FA\GraphQL\Config::fromArray([
                'secret' => '0123456789abcdef0123456789abcdef',
                'fa_root' => \FA\GraphQL\Fa\Bootstrap::defaultRoot(),
            ]),
            new \FA\GraphQL\RequestInfo(false, 'phpunit 127.0.0.1')
        );

        $this->assertNotContains(
            'sgw_sales',
            $container->get(\FA\GraphQL\Extension\Extensions::class)->loaded()->names()
        );
        $schema = $container->get(\GraphQL\Type\Schema::class);
        $this->assertArrayNotHasKey('recurring', $schema->getType('SalesOrderType')->getFields());
        $this->assertArrayNotHasKey('recurring', $schema->getType('SalesOrderCreateInput')->getFields());
        $this->assertNull($schema->getType('Recurrence'));
    }
```

   `dirname(__DIR__, 5)`: `sgw_sales/tests/GraphQL/Integration` → up four is `modules/`; adjust the count if the checkout layout differs (`ls` it) — the path must reach `modules/graphql/container.php`.
5. `testAScheduleTableNotAtThe14ShapeIsRejectedNamingTheScript` builds `new RecurrenceParticipant()` and puts it in the container with `$this->container->set(RecurrenceParticipant::class, $schedule)`; drop its `isActive` assertion (the participant no longer asks).

Run everything in the extension suites:

```bash
docker/fa-graphql exec php vendor/bin/phpunit -c /var/www/html/modules/sgw_sales/phpunit-graphql.xml
```

Expected: `OK, but incomplete, skipped, or risky tests!` — every `SalesOrderRecurrenceTest` test and `RecurrenceSchemaSnapshotTest` skipped with "The GraphQL module still serves recurring itself…"; `RecurrenceColumnsTest`, `RecurrenceParticipantTest`, `RegistrationTest` pass. Confirm the loader is what drops it, not an error:

```bash
docker/fa-graphql logs errors | grep -i 'graphql extension sgw_sales' | tail -3
```

Expected: a line naming the clash (`recurring` on `SalesOrderType`, or the `Recurrence` type name). The contract's ruling: any clash drops the **whole** extension, participants included — so while the module still has its own recurrence, `RecurrenceParticipant` is never called by `SalesOrderService` and nothing writes `sales_recurring` twice. If instead the line names an exception from `SgwSalesExtension`, fix that now.

- [ ] **Step 11: Activation applies update_1.4.sql**

FrontAccounting's `update_databases()` (`includes/hooks.inc`) imports a file when `check_table($pref, $table, $field, $properties)` is non-zero; `check_table()` (`admin/db/maintenance_db.inc:974`) returns 3 when a column's properties differ. After update_1.4.sql, `dt_next` is nullable (`Null` = `YES`); before it, `NO`. And `db_import()` treats `select` as a command (`maintenance_db.inc:284`): update_1.4.sql's trailing helper queries — no semicolons — would run as one malformed query and fail the import. They move to a file nothing imports.

`sgw_sales:tests/Unit/ActivateExtensionTest.php`:

```php
<?php

namespace SGW_Sales\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Release 4 spec §3.4. activate_extension() lists both scripts in order, 1.4 checked
 * by dt_next's nullability; and update_1.4.sql holds only statements
 * FrontAccounting's db_import() can run — no helper queries after it.
 */
class ActivateExtensionTest extends TestCase
{
    public function testActivationListsBothScriptsIn14Order(): void
    {
        $hooks = (string) file_get_contents(dirname(__DIR__, 2) . '/hooks.php');
        $this->assertMatchesRegularExpression(
            "/'update_1\\.0\\.sql' => array\\('sales_recurring'\\),\\s*'update_1\\.4\\.sql' => "
            . "array\\('sales_recurring', 'dt_next', array\\('Null' => 'YES'\\)\\)/",
            $hooks
        );
    }

    public function testUpdate14HasNoHelperQueries(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/sql/update_1.4.sql');
        $this->assertStringNotContainsString('Upgrade helpers', $sql);
        foreach (preg_split('/\R/', trim($sql)) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $this->assertStringStartsNotWith('SELECT', strtoupper($line), $line);
            $this->assertStringEndsWith(';', $line, 'every statement ends in a semicolon: ' . $line);
        }
    }
}
```

Run on sgw_sales' own stack (its unit suite needs no database):

```bash
cd ../sgw_sales
docker/fa-sgw-sales up
docker/fa-sgw-sales test --testsuite unit --filter ActivateExtensionTest
```

Expected: FAIL — the regular expression does not match; `Upgrade helpers` is present. (Check `docker/fa-sgw-sales --help` for its exact `test` syntax first; use what it documents.)

`sgw_sales:sql/update_1.4.sql` — delete the last three lines (the blank line is kept):

```sql
ALTER TABLE `0_sales_recurring` CHANGE `dt_end` `dt_end` DATE NULL;
ALTER TABLE `0_sales_recurring` CHANGE `dt_next` `dt_next` DATE NULL;
ALTER TABLE `0_sales_recurring` DROP INDEX `order_no`, ADD UNIQUE `order_no` (`trans_no`) USING BTREE;
UPDATE `0_sales_recurring` SET dt_end=NULL WHERE dt_end='0000-00-00';
UPDATE `0_sales_recurring` SET dt_next=NULL WHERE dt_next='0000-00-00';
```

`sgw_sales:sql/helpers/update_1.4-duplicates.sql`:

```sql
# Before update_1.4.sql on a company with data: it makes trans_no unique, and fails
# if an order has two schedules. Find them with the first query, look at one with
# the second (replace 720), and remove the extra rows by hand. Not imported by
# activate_extension() — FrontAccounting's db_import() would run these.
SELECT trans_no, COUNT(trans_no) AS c FROM `0_sales_recurring` GROUP BY trans_no HAVING c > 1;
SELECT * FROM `0_sales_recurring` WHERE trans_no = 720;
```

`sgw_sales:hooks.php` — `activate_extension()`:

```php
	/* This method is called on extension activation for company.   */
	function activate_extension($company, $check_only = true)
	{
		global $db_connections;

		// In order: 1.0 creates the table (checked by its existence), 1.4 makes
		// dt_end/dt_next nullable and trans_no unique (checked by dt_next's
		// nullability: check_table() returns 3 while it is NOT NULL). Before 1.4 on a
		// company with data, see sql/helpers/update_1.4-duplicates.sql.
		$updates = array(
			'update_1.0.sql' => array('sales_recurring'),
			'update_1.4.sql' => array('sales_recurring', 'dt_next', array('Null' => 'YES'))
		);

		return $this->update_databases($company, $updates, $check_only);
	}
```

```bash
docker/fa-sgw-sales test --testsuite unit --filter ActivateExtensionTest
```

Expected: PASS. Then prove it against FrontAccounting, on sgw_sales' own stack (its `db load` applies both scripts already; this checks the activation path itself):

```bash
docker/fa-sgw-sales exec php -r '
  $path_to_root = "/var/www/html"; chdir($path_to_root);
  // A scratch prefix: activation creates and upgrades a fresh table there.
  require "config_db.php"; $db_connections[0]["tbpref"] = "gqlt_act_";
  ' 2>&1 | head -5
```

A full in-process activation needs FrontAccounting's session; if that one-liner cannot reach `update_databases()`, instead activate through the web UI of sgw_sales' stack on a second company (Setup → Install/Activate Extensions), then check `SHOW COLUMNS FROM <prefix>sales_recurring LIKE 'dt_next'` shows `Null: YES` and `SHOW INDEX` shows a unique `order_no`. Record which you did in the report. Drop any scratch tables you created.

- [ ] **Step 12: The second write path, documented; the README section**

`sgw_sales:includes/db/SalesRecurringModel.php` — add to the class docblock (create one if absent):

```php
/**
 * sales_recurring, through Anorm on this module's own PDO connection: the page and
 * the generation service use it.
 *
 * It is not the only writer. The FrontAccounting GraphQL module's API writes the
 * table through SGW_Sales\GraphQL\RecurrenceParticipant with FrontAccounting's
 * db_query(), on FrontAccounting's connection, so a schedule commits and rolls back
 * with its order (a different connection could not share that transaction). Both
 * write the same columns with the same meanings; change them together.
 */
```

`sgw_sales:README.md` — add a section:

```markdown
## GraphQL API

When the FrontAccounting GraphQL module (`modules/graphql`) is installed, this
module adds to its API, for every company where it is active: the `recurring`
schedule on sales orders (`salesOrderList`, `salesOrderCreate`, `salesOrderUpdate`),
written in the order's own transaction. The code is `includes/GraphQL/`, registered
by `hooks_sgw_sales::graphql_extensions()`. Without that module nothing of it loads.

Its tests run inside the GraphQL module's docker stack, against this checkout:

    cd ../graphql
    SGW_SALES_PATH=../sgw_sales docker/fa-graphql up --recreate
    SGW_SALES_PATH=../sgw_sales docker/fa-graphql test-extension sgw_sales

Activating this module now applies `sql/update_1.4.sql` as well as
`update_1.0.sql`. A company activated before must be re-activated (Setup →
Install/Activate Extensions) for the GraphQL API to write schedules; first check
it for duplicate schedules with `sql/helpers/update_1.4-duplicates.sql`.
```

(`test-extension` arrives with the module's Task 3; until then the README's command is `docker/fa-graphql exec php vendor/bin/phpunit -c /var/www/html/modules/sgw_sales/phpunit-graphql.xml`.)

- [ ] **Step 13: sgw_sales' CI runs the extension suites in the module's stack**

Read `sgw_sales:.github/workflows/ci.yml` first; keep its existing job exactly as it is and add a second job:

```yaml
  graphql-extension:
    name: GraphQL extension / FA ${{ matrix.fa.name }} / PHP ${{ matrix.php }}
    runs-on: ubuntu-latest
    strategy:
      fail-fast: false
      matrix:
        php: ['7.4', '8.3']
        fa:
          - name: upstream
            repo: https://github.com/FrontAccountingERP/FA.git
            ref: master
          - name: fork
            repo: https://github.com/cambell-prince/frontaccounting.git
            ref: master-cp
    env:
      PHP_VERSION: ${{ matrix.php }}
      FA_REPO: ${{ matrix.fa.repo }}
      FA_REF: ${{ matrix.fa.ref }}
      COMPOSE_PROJECT_NAME: sgw-gql-${{ matrix.fa.name }}-${{ matrix.php }}
      # The GraphQL module's branch that has the extension contract. Its Release 4
      # PR is open; switch to main once it merges.
      GRAPHQL_REF: feature/release-4
    steps:
      - uses: actions/checkout@v4
        with:
          path: sgw_sales
      - uses: actions/checkout@v4
        with:
          repository: saygoweb/frontaccounting-module-graphql
          ref: ${{ env.GRAPHQL_REF }}
          path: graphql
      - name: Boot the GraphQL module's stack with this checkout mounted
        working-directory: graphql
        run: SGW_SALES_PATH=${{ github.workspace }}/sgw_sales docker/fa-graphql up --build
      - name: This checkout's own dependencies
        working-directory: sgw_sales
        run: docker run --rm -v "$PWD":/app -w /app composer:2 install --no-interaction --no-progress --ignore-platform-reqs
      - name: The extension suites
        working-directory: graphql
        run: SGW_SALES_PATH=${{ github.workspace }}/sgw_sales docker/fa-graphql exec php vendor/bin/phpunit -c /var/www/html/modules/sgw_sales/phpunit-graphql.xml
```

(Once the module's Task 3 adds `test-extension`, replace the last step's command with `docker/fa-graphql test-extension sgw_sales`; Task 3 Step 8 does that.) Validate the YAML:

```bash
python3 -c 'import yaml,sys; yaml.safe_load(open("../sgw_sales/.github/workflows/ci.yml")); print("ok")'
```

Expected: `ok`. The `composer:2` step is there because the bind mount replaces the image's clone and its `vendor/`; if sgw_sales' own workflow already installs dependencies another way, copy that instead.

- [ ] **Step 14: Gates and commit**

On the module's stack (the extension suites):

```bash
cd /home/cambell/src/sgw/frontaccounting/modules/graphql
SGW_SALES_PATH=../sgw_sales docker/fa-graphql exec php vendor/bin/phpunit -c /var/www/html/modules/sgw_sales/phpunit-graphql.xml
SGW_SALES_PATH=../sgw_sales docker/fa-graphql test
```

Expected: the extension suites OK with only the skips of Step 10 (`SalesOrderRecurrenceTest`, `RecurrenceSchemaSnapshotTest`); the module's own full suite still OK — its own recurrence is untouched, and the loader dropping `sgw_sales` changes nothing it tests.

On sgw_sales' own stack (its suites and quality gates must be unaffected):

```bash
cd ../sgw_sales
docker/fa-sgw-sales ci
```

Expected: green. If `phpcs`/`phpstan` scan `includes/` and fail on `includes/GraphQL/` because the module's classes are not in their scan paths, add `../graphql/vendor/autoload.php` (when present) to phpstan's `bootstrapFiles`, or exclude `includes/GraphQL` from phpstan with a comment that it is analysed in the module's stack — record which.

Commit on sgw_sales `feature/graphql-extension`:

```bash
git add includes/GraphQL hooks.php sql/update_1.4.sql sql/helpers/update_1.4-duplicates.sql \
  includes/db/SalesRecurringModel.php tests/GraphQL tests/Unit/ActivateExtensionTest.php \
  .github/workflows/ci.yml README.md phpunit-graphql.xml
git commit -m "GraphQL extension: recurring schedules on sales orders

The FrontAccounting GraphQL module's recurrence moves here (its Release 4 spec
section 3): SgwSalesExtension contributes recurring to sales orders and their
inputs, RecurrenceParticipant writes sales_recurring with FrontAccounting's
db_query() inside the order's transaction. GraphQL names are Release 2's,
pinned by a schema snapshot. activate_extension() now applies update_1.4.sql;
its helper queries move to sql/helpers so db_import() can run it.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013U8a6JAbsHc4c1KcSLiaYK"
```

Do not push (Checkpoint D pushes both repositories).

---

### Task 3: graphql — the module's own recurrence removed; extension suites in the stack and in CI

Spec §3.2, §5, §6. Module branch `feature/release-4`, after Task 1 (contract) and with sgw_sales at Task 2 (`feature/graphql-extension`, committed) mounted into the stack. From this task on, `recurring` is served by the sgw_sales extension alone: the module stops serving it, so its loader stops dropping `sgw_sales` (the clash is gone) and Task 2's skipped tests run.

**Files:**
- Delete: `graphql:src/Fa/Service/RecurringSchedule.php`, `graphql:src/Type/SalesOrder/RecurrenceType.php`, `graphql:src/Type/SalesOrder/RecurrenceInputType.php`, `graphql:src/Type/SalesOrder/RecurrenceRepeatsType.php`
- Delete (moved to sgw_sales in Task 2): `graphql:tests/Integration/SalesOrder/SalesOrderRecurrenceTest.php`, `graphql:tests/Unit/Fa/RecurrenceColumnsTest.php`
- Modify: `graphql:src/Fa/Service/SalesOrderService.php` (no `RecurringSchedule`; recurrence only through participants)
- Modify: `graphql:src/Type/SalesOrder/SalesOrderType.php`, `SalesOrderCreateInput.php`, `SalesOrderUpdateInput.php` (no `recurring` of their own)
- Modify: `graphql:src/Fa/Service/FaIncludes.php` (docblock only)
- Modify: `graphql:tests/Generated/SalesOrderTypeTest.php` (recurring comes from the extension)
- Modify: `graphql:docker/fa-graphql` (`test-extension`; `ci` runs every installed extension's suites)
- Modify: `graphql:.github/workflows/ci.yml` (`SGW_SALES_REF: feature/graphql-extension`)
- Modify: `graphql:README.md`, `graphql:docker/README.md`
- Modify: `sgw_sales:.github/workflows/ci.yml`, `sgw_sales:README.md` (use `test-extension`)
- Create: `graphql:tests/Unit/CoreKnowsNoExtensionTest.php`, `graphql:tests/Integration/Extension/CoreServesNoRecurrenceTest.php`
- Unchanged, on purpose: `graphql:tests/Http/PanelFlowTest.php` — the panel's flow, recurring parts included, is the end-to-end proof that clients see no change (spec §3.3); it now runs through the extension. `graphql:tests/Support/FaOrderRows.php` keeps purging `sales_recurring` rows its tests' orders leave (test cleanup, not module source).

**Interfaces:**
- Consumes: Task 1's `FA\GraphQL\Extension\Extensions` (`loaded(): LoadedExtensions`, `names()`, `salesOrderParticipants()`), and whatever accessor Task 1 gave `SalesOrderService` for its participants (read it: `grep -n 'Participant' src/Fa/Service/SalesOrderService.php`). Task 2's `sgw_sales:phpunit-graphql.xml`.
- Produces:
  - `docker/fa-graphql test-extension <name> [phpunit args]` — runs `modules/<name>/phpunit-graphql.xml` in this stack with this module's phpunit; `<name>` matches `^[a-z0-9_]+$`.
  - `docker/fa-graphql ci` also runs `test-extension` for every installed extension that ships a `phpunit-graphql.xml`, with `--fail-on-skipped`.
  - The module's `src/`, `container.php`, `app.php` contain no `sgw_sales`, `sales_recurring`, `Recurrence` or `RecurringSchedule` (pinned by `CoreKnowsNoExtensionTest`).

- [ ] **Step 1: Before — the stack runs sgw_sales' branch**

```bash
cd /home/cambell/src/sgw/frontaccounting/modules/graphql
git checkout feature/release-4
export SGW_SALES_PATH=../sgw_sales
(cd ../sgw_sales && git checkout feature/graphql-extension && git log --oneline -1)
docker/fa-graphql up --recreate
docker/fa-graphql exec php vendor/bin/phpunit -c /var/www/html/modules/sgw_sales/phpunit-graphql.xml
```

Expected: sgw_sales at Task 2's commit; the extension suites OK with `SalesOrderRecurrenceTest` and `RecurrenceSchemaSnapshotTest` skipped ("The GraphQL module still serves recurring itself…"). Keep `SGW_SALES_PATH` exported for every command in this task.

- [ ] **Step 2: Write the failing tests — the core serves no recurrence and names no extension**

`graphql:tests/Unit/CoreKnowsNoExtensionTest.php`:

```php
<?php

namespace FA\GraphQL\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Release 4 spec §1 "Success": the module's source knows no extension. Recurrence is
 * sgw_sales' (its GraphQL extension); nothing here may name it.
 */
class CoreKnowsNoExtensionTest extends TestCase
{
    private const FORBIDDEN = ['sgw_sales', 'sales_recurring', 'Recurrence', 'RecurringSchedule'];

    public function testTheModuleSourceNamesNoExtension(): void
    {
        $root = dirname(__DIR__, 2);
        $files = [$root . '/container.php', $root . '/app.php'];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src'));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        $found = [];
        foreach ($files as $path) {
            $source = (string) file_get_contents($path);
            foreach (self::FORBIDDEN as $word) {
                if (stripos($source, $word) !== false) {
                    $found[] = substr($path, strlen($root) + 1) . ": $word";
                }
            }
        }
        $this->assertSame([], $found);
    }
}
```

`graphql:tests/Integration/Extension/CoreServesNoRecurrenceTest.php`:

```php
<?php

namespace FA\GraphQL\Tests\Integration\Extension;

use FA\GraphQL\Extension\Extensions;
use FA\GraphQL\Tests\Integration\SalesOrder\SalesOrderTestCase;
use GraphQL\Type\Schema;

/**
 * Release 4 spec §3.3: with no extension active for the company — install_hooks()
 * puts an extension in $Hooks only when it is — the core schema has no recurring and
 * no Recurrence types. Only extensions add them.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class CoreServesNoRecurrenceTest extends SalesOrderTestCase
{
    public function testWithNoExtensionTheSchemaHasNoRecurrence(): void
    {
        foreach (array_keys($GLOBALS['Hooks'] ?? []) as $name) {
            if ($name !== 'graphql') {
                unset($GLOBALS['Hooks'][$name]);
            }
        }

        $this->assertSame([], $this->container->get(Extensions::class)->loaded()->names());
        $schema = $this->container->get(Schema::class);
        $this->assertArrayNotHasKey('recurring', $schema->getType('SalesOrderType')->getFields());
        $this->assertArrayNotHasKey('recurring', $schema->getType('SalesOrderCreateInput')->getFields());
        $this->assertArrayNotHasKey('recurring', $schema->getType('SalesOrderUpdateInput')->getFields());
        $this->assertNull($schema->getType('Recurrence'));
        $this->assertNull($schema->getType('RecurrenceInput'));
    }
}
```

`SalesOrderTestCase::setUp()` builds the container and enters the session but builds no schema; `Extensions::loaded()` is lazy (Task 1), so unsetting `$Hooks` before the first call is what the test needs. If Task 1 made `loaded()` eager (computed when the session is entered), build a fresh container after the unset, as sgw_sales' `testWithoutSgwSalesTheSchemaHasNoRecurring` does, and record it.

```bash
docker/fa-graphql test --filter 'CoreKnowsNoExtensionTest|CoreServesNoRecurrenceTest'
```

Expected: FAIL — `CoreKnowsNoExtensionTest` lists `src/Fa/Service/RecurringSchedule.php: sgw_sales`, the `Recurrence*` types, `SalesOrderService.php`, `SalesOrderType.php`, both inputs, `FaIncludes.php`; `CoreServesNoRecurrenceTest` finds `recurring` on `SalesOrderType`.

- [ ] **Step 3: Remove the module's own recurrence**

```bash
git rm src/Fa/Service/RecurringSchedule.php \
  src/Type/SalesOrder/RecurrenceType.php src/Type/SalesOrder/RecurrenceInputType.php \
  src/Type/SalesOrder/RecurrenceRepeatsType.php \
  tests/Integration/SalesOrder/SalesOrderRecurrenceTest.php tests/Unit/Fa/RecurrenceColumnsTest.php
```

(sgw_sales Task 2 holds their ports: `includes/GraphQL/RecurrenceParticipant.php`, `includes/GraphQL/Type/*`, `tests/GraphQL/Unit/RecurrenceColumnsTest.php`, `tests/GraphQL/Integration/SalesOrderRecurrenceTest.php`.)

`graphql:src/Type/SalesOrder/SalesOrderType.php`:
- drop `use FA\GraphQL\Fa\Service\RecurringSchedule;`, the `private RecurrenceType $recurrenceType;` property, and the constructor's `RecurrenceType $recurrenceType` parameter and assignment — the constructor becomes:

```php
    public function __construct(SalesOrderLineType $lineType)
    {
        // Before parent::__construct(), which calls fields().
        $this->lineType = $lineType;
        parent::__construct();
    }
```

- in `fields()`, delete the whole `FieldBuilder::create('recurring', …)` entry (the `lines` entry stays). Task 1's code in `fields()` that appends `LoadedExtensions::typeFields('SalesOrderType')` stays — that is where sgw_sales' `recurring` now comes from.
- in `resolveDelete()`, delete the line `$row['recurring'] = $context->get(RecurringSchedule::class)->read($ids[$index]);`. The extension's field reads the participant's `snapshot()`, which keeps the schedule as it was before the delete or close (sgw_sales Task 2).

`graphql:src/Type/SalesOrder/SalesOrderCreateInput.php` — the constructor takes only the line input, and `fields()` loses its `recurring` entry (Task 1's `inputFields('SalesOrderCreateInput')` append stays):

```php
    private SalesOrderLineCreateInput $lineInput;

    public function __construct(SalesOrderLineCreateInput $lineInput)
    {
        // Before parent::__construct(), which builds the fields.
        $this->lineInput = $lineInput;
        parent::__construct();
    }
```

Delete the `private RecurrenceInputType $recurrenceInput;` property and the three lines building the `recurring` field. `graphql:src/Type/SalesOrder/SalesOrderUpdateInput.php` — the same: constructor `(SalesOrderLineUpdateInput $lineInput)`, no `$recurrenceInput`, no `recurring` field.

`graphql:src/Fa/Service/SalesOrderService.php`:
- delete `private RecurringSchedule $schedule;` and the constructor that takes it (keep whatever Task 1 injected — its participants' source; if the constructor is left with only Task 1's parameter, keep that one).
- in `create()`, delete the `if (self::given($input, 'recurring')) { … assertWritable … toColumns … }` block before the cart, and the `if (self::given($input, 'recurring')) { $this->schedule->write(…); }` block after `$cart->write(1)`. Task 1's participant calls (`validate()` before the cart, `afterCreate()` after the write) do both jobs now.
- in `update()`, delete the same `assertWritable`/`toColumns` block (`:500-503` today).
- `isRecurringOrder()`, `afterUpdate()`, `afterDelete()`, `afterClose()`: replace their bodies so they only ask the participants — the core knows no schedule. Using Task 1's accessor for the loaded participants (called `participants()` below; use its real name):

```php
    /**
     * An extension may keep an order's header editable once delivered and lift the
     * delivered-quantity floor (a recurring order, for sgw_sales): true when any
     * participant says so (Release 4 spec §2.4).
     *
     * @param array<string, mixed> $input
     */
    protected function isRelaxed(int $id, array $input): bool
    {
        foreach ($this->participants() as $participant) {
            if ($participant->isRelaxed($id, $input)) {
                return true;
            }
        }

        return false;
    }
```

  Rename the call site in `update()` (`$recurring = $this->isRecurringOrder($id, $input);`) to `$relaxed = $this->isRelaxed($id, $input);` and pass `$relaxed` where `$recurring` went (`is_started()` check, `replaceLines()`), renaming `replaceLines()`'s and `checkLine()`'s `$recurring` parameter to `$relaxed` (and the docblock at `:270`). If Task 1 already routes `afterUpdate`/`afterDelete`/`afterClose` through participants with the own-schedule calls alongside, delete only the own-schedule lines (`$this->schedule->…`); if Task 1 left these protected hooks as the own-schedule versions and calls participants elsewhere, delete the hooks entirely and their call sites, keeping Task 1's participant calls. Either way the result is: every participant's `afterCreate`/`afterUpdate`/`afterDelete`/`afterClose` is called once, inside the transaction, and nothing else touches a schedule.
- the class docblock's and methods' comments: nothing mentions sgw_sales or its schedule (the test in Step 2 checks the words).

`graphql:src/Fa/Service/FaIncludes.php` :24-29 — the comment becomes:

```php
    /**
     * What a Cart needs beyond boot. includes/ui.inc is taken for granted by
     * FrontAccounting's sales code (count_array() in sales_db.inc, for one); other
     * extensions include it for the same reason. sales_order_ui.inc is for
     * get_customer_details_to_order(); its display functions are never called.
     */
```

Check nothing else still names the removed classes:

```bash
grep -rn 'RecurringSchedule\|RecurrenceType\|RecurrenceInputType\|RecurrenceRepeatsType' src container.php app.php tests
```

Expected: only `tests/Generated/SalesOrderTypeTest.php` (next step) — and nothing in `src/`, `container.php`, `app.php`.

- [ ] **Step 4: The generated sales-order test takes recurring from the extension**

`graphql:tests/Generated/SalesOrderTypeTest.php` (`:130-180`):
- `testInputMirrorsTheType()`: the two `assertSame('RecurrenceInput', …)` lines and the `'recurring'` in the two `array_diff(…, ['lines', 'recurring'])` calls become conditional on the extension, so the core test holds with or without sgw_sales:

```php
        $extensions = $this->container->get(\FA\GraphQL\Extension\Extensions::class)->loaded()->names();
        $withRecurring = in_array('sgw_sales', $extensions, true);
        $extra = $withRecurring ? ['lines', 'recurring'] : ['lines'];
        $this->assertSame('[SalesOrderLineCreateInput!]!', $create['lines'] ?? null);
        $this->assertSame($withRecurring ? 'RecurrenceInput' : null, $create['recurring'] ?? null);
        $this->assertSame(
            [],
            array_diff(array_keys($create), array_keys($this->expectedFieldTypes()), $extra),
            'nothing else on the create Input'
        );
        $this->assertSame('[SalesOrderLineUpdateInput!]', $update['lines'] ?? null);
        $this->assertSame($withRecurring ? 'RecurrenceInput' : null, $update['recurring'] ?? null);
        $this->assertSame(
            [],
            array_diff(array_keys($update), array_keys($this->expectedFieldTypes()), $extra),
            'nothing else on the update Input'
        );
```

  and the docblock's last sentence becomes "Both take the optional recurring schedule when the sgw_sales extension is active (Release 4 spec §3)."
- `testARecurringOrderThroughTheSchema()`: its guard becomes

```php
        if (!in_array('sgw_sales', $this->container->get(\FA\GraphQL\Extension\Extensions::class)->loaded()->names(), true)) {
            $this->markTestSkipped('The sgw_sales extension is not active in this stack.');
        }
```

  The rest of the test is unchanged: it is a client's-eye proof that a recurring order still works through the schema.

```bash
docker/fa-graphql test --filter 'CoreKnowsNoExtensionTest|CoreServesNoRecurrenceTest|SalesOrderTypeTest'
```

Expected: PASS — `testARecurringOrderThroughTheSchema` runs (not skipped): the extension is loaded now that nothing clashes.

- [ ] **Step 5: The extension's suites run, with no skips**

```bash
docker/fa-graphql exec php vendor/bin/phpunit --fail-on-skipped -c /var/www/html/modules/sgw_sales/phpunit-graphql.xml
docker/fa-graphql logs errors | grep -i 'graphql extension' | tail -5
```

Expected: OK — every sgw_sales test runs, `SalesOrderRecurrenceTest` and `RecurrenceSchemaSnapshotTest` included, no skips; the snapshot equals the fixture captured from the module in Task 2 Step 3. No new `graphql extension sgw_sales:` line in the error log since this step started. If the snapshot differs, the diff shows which of Release 2's names, types, defaults or descriptions the extension changed — fix the extension (sgw_sales), never the fixture.

- [ ] **Step 6: `docker/fa-graphql test-extension`**

`graphql:docker/fa-graphql` — after `cmd_test()`:

```bash
# An extension's GraphQL suites (Release 4 spec §5): modules/<name>/phpunit-graphql.xml,
# run here, with this module's phpunit and autoloader, against the extension as it
# is installed in this stack (the image's clone, or a host checkout bind-mounted by
# SGW_SALES_PATH and the like). Arguments after the name reach phpunit.
cmd_test_extension() {
    local name="${1:-}"
    [ -n "$name" ] || die "usage: docker/fa-graphql test-extension <name> [phpunit args]"
    [[ "$name" =~ ^[a-z0-9_]+$ ]] || die "extension name '$name': lower-case letters, digits and _ only"
    shift
    ensure_running
    require_phpunit
    local config="$FA_DIR/modules/$name/phpunit-graphql.xml"
    c_exec test -f "$config" || die "modules/$name has no phpunit-graphql.xml in this stack"
    info "extension $name: $config"
    c_exec_user php vendor/bin/phpunit -c "$config" "$@"
}

# Every installed extension that ships GraphQL suites, one name per line.
extensions_with_suites() {
    c_exec sh -c "cd '$FA_DIR/modules' && for f in */phpunit-graphql.xml; do [ -f \"\$f\" ] && dirname \"\$f\"; done" \
        | grep -v '^graphql$' || true
}
```

`cmd_ci()` becomes:

```bash
cmd_ci() {
    cmd_up --build
    cmd_lint
    cmd_analyze
    cmd_test "$@"
    # The extensions installed in the stack, with no skips: a skipped extension test
    # is a test that did not prove its extension still works with this module.
    local name
    for name in $(extensions_with_suites); do
        cmd_test_extension "$name" --fail-on-skipped
    done
}
```

The dispatch `case` gains `test-extension) cmd_test_extension "$@" ;;` after `test)`; `cmd_help` gains, under "Testing and development" after `test [args]`:

```
  test-extension <name> [args]
                     Run an installed extension's GraphQL suites
                     (modules/<name>/phpunit-graphql.xml) in this stack
```

Check `c_exec_user`'s working directory is this module's checkout in the container (`-w "$APP_DIR"`, `docker/fa-graphql` :174-183) — `vendor/bin/phpunit` is relative to it:

```bash
docker/fa-graphql test-extension sgw_sales --fail-on-skipped
docker/fa-graphql test-extension ../graphql; echo "exit=$?"
docker/fa-graphql test-extension nosuch; echo "exit=$?"
```

Expected: the first OK with no skips; the second refused (`extension name '../graphql'…`, exit non-zero); the third refused (`modules/nosuch has no phpunit-graphql.xml`, exit non-zero).

- [ ] **Step 7: CI builds sgw_sales' branch**

`graphql:.github/workflows/ci.yml` — in the job's `env:` add:

```yaml
      # sgw_sales' GraphQL extension (Release 4 spec §6). Its PR is open on this
      # branch; the image clones by branch or tag (a shallow clone cannot take a
      # commit SHA). Switch to master once sgw_sales' PR merges.
      SGW_SALES_REF: feature/graphql-extension
```

`docker/fa-graphql` exports `SGW_SALES_REF` into the build (`:81`) and the Dockerfile clones it (`:97`); `ci` then runs `test-extension sgw_sales --fail-on-skipped` (Step 6). Validate:

```bash
python3 -c 'import yaml; d=yaml.safe_load(open(".github/workflows/ci.yml")); print(d["jobs"]["test"]["env"]["SGW_SALES_REF"])'
```

Expected: `feature/graphql-extension`. Prove the pinned build locally, without the host mount (what CI sees), on a throwaway stack:

```bash
env -u SGW_SALES_PATH SGW_SALES_REF=feature/graphql-extension COMPOSE_PROJECT_NAME=fa-graphql-pin \
  HTTP_PORT=8105 DB_PORT=3325 PMA_PORT=8106 docker/fa-graphql ci
env -u SGW_SALES_PATH COMPOSE_PROJECT_NAME=fa-graphql-pin HTTP_PORT=8105 DB_PORT=3325 PMA_PORT=8106 \
  docker/fa-graphql destroy --yes
```

This needs sgw_sales' `feature/graphql-extension` on GitHub. It is not pushed until Checkpoint D; if the clone fails with "Remote branch feature/graphql-extension not found", skip this local proof now, record it, and run it at Checkpoint D after the push (Checkpoint D's four-combination run covers it). Never touch other containers.

- [ ] **Step 8: sgw_sales' CI and README use `test-extension`**

`sgw_sales:.github/workflows/ci.yml` — the `graphql-extension` job's last step (Task 2 Step 13):

```yaml
      - name: The extension suites
        working-directory: graphql
        run: SGW_SALES_PATH=${{ github.workspace }}/sgw_sales docker/fa-graphql test-extension sgw_sales --fail-on-skipped
```

`sgw_sales:README.md` — drop the parenthetical "(`test-extension` arrives with the module's Task 3 …)" sentence; the command in the section is already `test-extension`.

```bash
cd ../sgw_sales
python3 -c 'import yaml; yaml.safe_load(open(".github/workflows/ci.yml")); print("ok")'
git add .github/workflows/ci.yml README.md
git commit -m "CI: run the GraphQL extension suites with test-extension, no skips

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013U8a6JAbsHc4c1KcSLiaYK"
cd ../graphql
```

- [ ] **Step 9: README and docker README**

`graphql:README.md` — in "What it covers", the "Recurring schedules" row becomes:

```markdown
| Recurring schedules | `recurring` on sales orders — from the `sgw_sales` extension, when it is active for the company | nested `recurring` input on the order mutations (the same extension) |
```

and add a section after "What it covers":

```markdown
## Extensions

Other FrontAccounting extensions can add to this API without this module knowing
them (Release 4 spec §2): an extension answers FrontAccounting's
`graphql_extensions` hook with an `FA\GraphQL\Extension\Extension`, and contributes
root fields, fields on the sales order Type and inputs, and participants that write
in the order's own transaction. Contributions are checked on every request; a
clashing or broken extension is dropped and logged, never the whole API.
`sgw_sales` is the first: it serves `recurring`.

An extension's GraphQL tests run in this stack:
`docker/fa-graphql test-extension <name>` runs `modules/<name>/phpunit-graphql.xml`;
`docker/fa-graphql ci` runs every installed extension's suites.
```

`graphql:docker/README.md` — beside the existing `SGW_SALES_PATH` text, a line: "`docker/fa-graphql test-extension sgw_sales` runs sgw_sales' GraphQL suites against whatever is mounted or cloned; CI clones `SGW_SALES_REF` (`feature/graphql-extension` until sgw_sales' Release 4 PR merges)."

- [ ] **Step 10: Gates and commit**

```bash
docker/fa-graphql lint
docker/fa-graphql analyze
docker/fa-graphql test
docker/fa-graphql test-extension sgw_sales --fail-on-skipped
bin/generate --dry-run
```

Expected: lint and analyze clean; the module's full suite OK (one known upstream skip) — `PanelFlowTest` included, its recurring parts now served by the extension, unchanged; the extension suites OK with no skips; `bin/generate --dry-run` writes nothing (the removed types were hand-written; the generated `SalesOrderTypeBase`/`InputBase` never had `recurring`). If `bin/generate` reports `SalesOrderType.php` or an Input as changed, stop: a once-only file was edited in a way the generator reads — check before committing.

Row counts: every table back at its starting count (compare `SELECT COUNT(*)` of `0_sales_orders`, `0_sales_order_details`, `0_sales_recurring`, `0_debtor_trans`, `0_audit_trail`, `0_refs` before and after the run, as the Release 3 tasks did).

Commit on graphql `feature/release-4`:

```bash
git add -A src tests docker .github README.md
git status --short   # only this task's files; no config_graphql.php, no tmp/
git commit -m "Recurrence leaves the module: sgw_sales' extension serves it

The module's RecurringSchedule and Recurrence types are gone; SalesOrderService
asks its participants whether an order is relaxed and lets them keep their
rows in step. With no clash left, the loader keeps sgw_sales, whose extension
serves recurring exactly as Release 2 did (its snapshot test). test-extension
runs an installed extension's GraphQL suites here; ci runs them all, no skips;
CI clones sgw_sales feature/graphql-extension until its PR merges.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013U8a6JAbsHc4c1KcSLiaYK"
```

Do not push (Checkpoint D).

---

### Checkpoint B — after Task 3 (recurrence moved; the panel sees no change)

Placed here because this is the step clients could notice: `recurring` changes owner, across two repositories, and every later task builds on the extension serving it. Review before generation (Tasks 4–5) is layered on top.

Scope: graphql `feature/release-4` since Checkpoint A (`git log --oneline <Checkpoint A head>..HEAD`), and sgw_sales `feature/graphql-extension` since `master` (31eb202).

- [ ] **Review packages.** One per repository, written to the SDD workspace (`.superpowers/sdd/<plan>/`):

```bash
cd /home/cambell/src/sgw/frontaccounting/modules/graphql
D=/home/cambell/.claude-personal/plugins/cache/claude-plugins-official/superpowers/6.4.1/skills/subagent-driven-development
P=$PWD/docs/superpowers/plans/2026-09-28-release-4-extensions-recurring.md
W=$(bash $D/scripts/sdd-workspace $P)
bash $D/scripts/review-package $P <checkpoint-A-head> HEAD $W/review-B-graphql.diff
(cd ../sgw_sales && bash $D/scripts/review-package $P 31eb202 feature/graphql-extension $W/review-B-sgw_sales.diff)
```

- [ ] **Independent review** (a fresh reviewer on the most capable model; it reviews and verifies, it does not fix). Give it both packages, the spec (§2.4–§2.6, §3, §5, §6), Tasks 2–3's reports, and this checklist. Medium effort, correctness first:
  - **Nothing writes a schedule twice, or not at all.** Every `SalesOrderService` path — create, update, delete, close, a batch with a refused item — calls each participant's hook exactly once, inside the order's `FaTransaction`; the module has no schedule code left (`grep -rn 'sales_recurring\|RecurringSchedule' src container.php app.php` empty); a participant exception rolls the whole mutation back.
  - **The clash rule still guards the merge order** (spec §6): an extension contributing a field the core also serves is dropped whole, participant included — prove it by reading the loader test Task 1 wrote for the contract ruling, and by the fact that Task 2's end-to-end tests skipped until Task 3.
  - **Behaviour identical to Release 2** (spec §3.3), except the one deliberate change: `RecurrenceSchemaSnapshotTest` equals the fixture captured from the module before the move (check the fixture's commit is Task 2 Step 3's, before any extension code served it); `recurring` on a delete response is the schedule as it was (`snapshot()`), on a close the schedule before the close; the relaxations (header editable once delivered, no delivered floor) hold for an order with a schedule and for one given a schedule in the same update; `dt_next` is kept unless the rhythm changes; with sgw_sales inactive, `recurring` and the `Recurrence*` types are absent (not `BAD_INPUT`).
  - **SQL safety in the participant**: every value reaching `db_query()` is `db_escape()`d or cast to int; `occur`, `repeats` and dates pass `toColumns()` validation first.
  - **One participant per request**: the field resolver's participant is the instance that wrote (container singleton), so `snapshot()` sees this request's deletes and closes; a fresh request reads the table.
  - **Activation**: `activate_extension()` lists 1.0 then 1.4, 1.4 checked by `dt_next`'s nullability; `update_1.4.sql` has no helper queries left; FrontAccounting's `update_databases()`/`check_table()`/`db_import()` semantics as cited in Task 2 Step 11 (verify against upstream `includes/hooks.inc` and `admin/db/maintenance_db.inc`).
  - **The stack and CI**: `test-extension` validates its name (no path traversal), fails on a missing config, runs from the module's checkout; `ci` runs every installed extension's suites with `--fail-on-skipped`; the module's CI pins `SGW_SALES_REF` to a branch (not a SHA, which the Dockerfile's shallow clone cannot take); sgw_sales' CI job checks out the module at `feature/release-4` and mounts itself.
  - **sgw_sales without the module** still works: its own suites and pages load no GraphQL class (`hooks_sgw_sales::graphql_extensions()` is only called by the module; the interface check guards it).
  - Tests meaningful (not asserting what they arrange), no test row left behind, PHP 7.4 syntax.

  The reviewer writes `$W/checkpoint-B-review.md`: findings (severity, repo:file:line, scenario, fix) and a verdict.

- [ ] **Spec walk — §3.** For each requirement, name the test that proves it:

| Spec | Requirement | Proof |
|---|---|---|
| §3.1 | Code in `sgw_sales/includes/GraphQL/`, registered by `hooks_sgw_sales::graphql_extensions` when the contract exists | `RegistrationTest::testTheHookRegistersTheExtension` |
| §3.1 | Not registered for a company where sgw_sales is inactive | `RegistrationTest::testNotActiveForTheCompanyItIsNotRegistered` |
| §3.2 | Participant writes with `db_query` in the order's transaction | `RecurrenceParticipantTest::testATransactionThatFailsTakesTheScheduleWithIt`, `SalesOrderRecurrenceTest::testAScheduleRollsBackWithItsBatch` |
| §3.2 | Types and `recurring` fields moved; the module has none | `CoreKnowsNoExtensionTest`, `CoreServesNoRecurrenceTest` |
| §3.2 | Tests moved | `sgw_sales:tests/GraphQL/**`; the module's recurrence tests deleted |
| §3.2 | Second write path documented | `SalesRecurringModel` docblock |
| §3.3 | Recurrence schema identical to Release 2 | `RecurrenceSchemaSnapshotTest` (no skip) |
| §3.3 | Inactive company: `recurring` absent | `SalesOrderRecurrenceTest::testWithoutSgwSalesTheSchemaHasNoRecurring`, `CoreServesNoRecurrenceTest` |
| §3.4 | Activation applies 1.0 then 1.4 | `ActivateExtensionTest` + Task 2 Step 11's recorded check |
| §5 | Extension suites run in the module's stack; both CIs run them | `docker/fa-graphql ci` output; both workflow files |
| §6 | At every step exactly one side serves `recurring` | Task 2's skips before Task 3; no skips after |

  Any requirement with no proof is a finding. Any deviation from the spec is fixed, or written into the spec marked *(revised)* with its reason.

- [ ] **The panel's flow, unchanged.** `graphql:tests/Http/PanelFlowTest.php` is not edited by Tasks 2–3 (`git diff <Checkpoint A head>..HEAD -- tests/Http/PanelFlowTest.php` empty) and passes, recurring parts included, now served by the extension. Also by hand, through Apache, the shape a client sees:

```bash
URL=http://localhost:8100/modules/graphql/
TOKEN=$(curl -s -H 'Content-Type: application/json' \
  -d '{"query":"mutation{login(user:\"apitest\",password:\"password\"){accessToken}}"}' $URL \
  | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["login"]["accessToken"])')
curl -s -H 'Content-Type: application/json' -H "Authorization: Bearer $TOKEN" \
  -d '{"query":"{ __type(name:\"SalesOrderType\"){ fields { name type { name } } } }"}' $URL \
  | python3 -c 'import sys,json;print([f for f in json.load(sys.stdin)["data"]["__type"]["fields"] if f["name"]=="recurring"])'
```

  Expected: `[{'name': 'recurring', 'type': {'name': 'Recurrence'}}]`.

- [ ] **Fix round.** Critical and Important findings go to one fix dispatch (both repositories as needed), each with a failing test first; then one scoped re-review of the fix diffs. Minor findings are recorded in the ledger (fixed now if trivial, otherwise deferred with a ruling).

- [ ] **Suites green on upstream and the fork**, with sgw_sales' branch mounted:

```bash
cd /home/cambell/src/sgw/frontaccounting/modules/graphql
export SGW_SALES_PATH=../sgw_sales
docker/fa-graphql test && docker/fa-graphql test-extension sgw_sales --fail-on-skipped
# The fork, on a throwaway stack (never touch other containers):
FA_REPO=https://github.com/cambell-prince/frontaccounting.git FA_REF=master-cp \
  COMPOSE_PROJECT_NAME=fa-graphql-fork HTTP_PORT=8105 DB_PORT=3325 PMA_PORT=8106 docker/fa-graphql ci
COMPOSE_PROJECT_NAME=fa-graphql-fork HTTP_PORT=8105 DB_PORT=3325 PMA_PORT=8106 docker/fa-graphql destroy --yes
# sgw_sales' own suites, on its own stack:
(cd ../sgw_sales && docker/fa-sgw-sales ci)
```

  Expected: all green; `ci` on the fork includes the extension suites with no skips; sgw_sales' own CI unaffected. Row counts back at their starting values on the main stack.

- [ ] **Ledger**: `Checkpoint B: complete (graphql <range>, sgw_sales <range>)`, rulings and deferred minors recorded.

---

### Task 4: sgw_sales — the hardened RecurringInvoiceService

Spec §4.1. One generation service for `sgw_sales`' page and the API: explicit dates, the checks Release 3 ported, delivery + invoice + `dt_next` in **one FrontAccounting transaction** (so a retry finds the order no longer due), `due()` restricted to sales orders, and emailing split out of `generate()`. `sgw_sales` must keep working without the graphql module, so the checks are written here with FrontAccounting's own functions (the same calls the module's `DeliveryService`, `InvoiceService` and `BillingChecks` make), not by calling the module.

Work in `sgw_sales` on branch `feature/graphql-extension` (created in Task 2). Tests run on `sgw_sales`' own stack (`docker/fa-sgw-sales`), which boots FrontAccounting through Apache and `session.inc`, has fiscal years through today (`ensure_fiscal_year`), and is independent of the graphql module.

**Files:**
- Modify: `sgw_sales:includes/service/RecurringInvoiceService.php` (rewrite)
- Modify: `sgw_sales:includes/service/GeneratedInvoice.php` (adds `deliveryNo`, drops `emailed`)
- Create: `sgw_sales:includes/service/RecurrenceNotDue.php`, `sgw_sales:includes/service/GenerationRefused.php`
- Modify: `sgw_sales:includes/db/GenerateRecurringModel.php` (`find()` takes `$asOf` and a PDO, restricts to `trans_type = 30`, adds `debtor_no`, `branch_code`, `customer_ref`)
- Modify: `sgw_sales:includes/controller/GenerateRecurring.php` (the page: explicit date, email after generate, a refusal is a message not a fatal)
- Modify: `sgw_sales:tests/Db/RecurringInvoiceServiceTest.php` (rewrite: `due()` only — `generate()` now writes on FrontAccounting's connection, which the Db suite's rolled-back Anorm connection cannot see)
- Modify: `sgw_sales:tests/Http/fixtures/service-probe.php`, `sgw_sales:tests/Http/ServiceWithoutAPageTest.php`, `sgw_sales:tests/Http/HttpTestCase.php` (helpers)
- Modify: `sgw_sales:README.md` (a "Generating recurring invoices" note)

**Interfaces:**
- Consumes: FrontAccounting's `Cart`, `get_customer_to_order()`, `get_invoice_duedate()` (`sales/includes/db/sales_order_db.inc:388,409`), `is_date_in_fiscalyear()` (`includes/date_functions.inc:192`), `db_has_currency_rates()` (`includes/data_checks.inc`), `$SysPrefs->allow_negative_stock()`, `Cart::check_qoh()`, `$Refs->get_next()`, `begin_transaction()`/`commit_transaction()`/`cancel_transaction()` (`includes/db/sql_functions.inc:20-47`), `RecurrenceSchedule` (unchanged).
- Produces (Task 5 and the page rely on these exact names):
  - `RecurringInvoiceService::due(\DateTimeInterface $asOf, bool $all = false, ?\PDO $pdo = null): array` — `GenerateRecurringModel[]`, soonest first.
  - `RecurringInvoiceService::generate(int $orderNo, \DateTimeInterface $invoiceDate, bool $allowEarly = false): GeneratedInvoice` — one FA transaction; no email.
  - `RecurringInvoiceService::emailInvoice($invoiceNo)` — unchanged (the page's rep107 path).
  - `protected RecurringInvoiceService::writeDelivery(int $orderNo, string $faDate): int` and `protected writeInvoice(int $deliveryNo, string $faDate, string $comment): int` — seams used only by the atomicity test's subclass.
  - `GeneratedInvoice` — public `int $orderNo`, `int $deliveryNo`, `int $invoiceNo`, `string $comment`, `string $dtNext` (Y-m-d).
  - Exceptions (namespace `SGW_Sales\service`): `RecurrenceNotFound` (existing; also a recurrence whose sales order no longer exists), `RecurrenceEnded` (existing), `RecurrenceNotDue extends \DomainException` (new), `GenerationRefused extends \RuntimeException` (new: `__construct(string $message, ?string $field = null, array $messages = [])`, `field(): ?string`, `messages(): string[]`).
  - `GenerateRecurringModel` gains public `$debtorNo`, `$branchCode`, `$customerRef`; `find($showAll, ?\DateTimeInterface $asOf = null, ?\PDO $pdo = null)`.
- **Due, precisely** (both `due()` and `generate()`): a schedule is due on date D when it has not ended (`dt_end IS NULL OR dt_end > D`) and either `dt_next <= D`, or `dt_next IS NULL AND dt_start <= D` (never generated and already started). The last clause is new: today `find()` lists a never-generated schedule whose start is still in the future as due; generating it would bill early.
- **Deviation from the contract, additive:** `generate()` takes a third parameter `bool $allowEarly = false`, and `due()` keeps its `$all` and gains an optional PDO. The page's "Show All" lets a person pick an order that is not yet due and generate it (existing behaviour, documented in the current docblock); the page passes `$allowEarly = check_value('show_all')`. The API never passes it.

- [ ] **Step 1: Check what the plan relies on, on the sgw_sales stack**

```bash
cd /home/cambell/src/sgw/frontaccounting/modules/sgw_sales
git checkout feature/graphql-extension
docker/fa-sgw-sales up
docker/fa-sgw-sales exec php -r '
  $root = "/var/www/html";
  foreach (["sales/includes/db/sales_order_db.inc" => ["get_invoice_duedate", "get_customer_to_order"],
            "includes/date_functions.inc" => ["is_date_in_fiscalyear"],
            "includes/data_checks.inc" => ["db_has_currency_rates"],
            "includes/db/sql_functions.inc" => ["cancel_transaction"]] as $f => $fns) {
    $src = file_get_contents("$root/$f");
    foreach ($fns as $fn) echo $fn, ": ", (strpos($src, "function $fn(") !== false ? "ok" : "MISSING"), "\n";
  }'
docker/fa-sgw-sales db shell <<'SQL'
SELECT MIN(begin), MAX(end), SUM(closed) FROM 0_fiscal_year;
SELECT COUNT(*) AS quotations_only FROM 0_sales_orders q WHERE q.trans_type = 32
  AND q.order_no NOT IN (SELECT order_no FROM 0_sales_orders WHERE trans_type = 30);
SELECT value FROM 0_sys_prefs WHERE name IN ('allow_negative_stock', 'curr_default');
SQL
```

Expected: every function `ok`; a fiscal year covering today and none covering 2000-01-01; note whether a quotation-only order number exists (`testDueListsOnlySalesOrders` skips when none does) and the home currency. If any function is missing, stop and report BLOCKED — the plan's calls would not exist on this FrontAccounting.

- [ ] **Step 2: Write the failing tests**

`sgw_sales:tests/Db/RecurringInvoiceServiceTest.php` — replace the whole file (the old tests stubbed `generateInvoice()`/`emailInvoice()`, which no longer exist in that shape; generation is covered over HTTP below):

```php
<?php

namespace SGW_Sales\Tests\Db;

use SGW_Sales\db\SalesRecurringModel;
use SGW_Sales\service\RecurringInvoiceService;

/**
 * due(), against the database through Anorm, rolled back. generate() writes on
 * FrontAccounting's own connection inside its transaction, which this suite's
 * rolled-back PDO cannot see; tests/Http/ServiceWithoutAPageTest covers it.
 */
class RecurringInvoiceServiceTest extends DbTestCase
{
    private function recur(?string $dtNext, ?string $dtEnd = null, string $dtStart = '2016-04-01', ?int $orderNo = null): int
    {
        $orderNo = $orderNo ?? (int) $this->anySalesOrder()['order_no'];
        $m = new SalesRecurringModel();
        $m->transNo = $orderNo;
        $m->dtStart = $dtStart;
        $m->dtEnd = $dtEnd;
        $m->dtNext = $dtNext;
        $m->auto = 0;
        $m->repeats = SalesRecurringModel::REPEAT_MONTHLY;
        $m->every = 1;
        $m->occur = '21';
        $m->write();
        return $orderNo;
    }

    /** @return int[] */
    private function listed(\DateTimeInterface $asOf, bool $all = false): array
    {
        $numbers = [];
        foreach ((new RecurringInvoiceService())->due($asOf, $all, $this->pdo) as $model) {
            $numbers[] = (int) $model->orderNo;
        }
        return $numbers;
    }

    public function testDueListsWhatIsDueOnTheDateGiven(): void
    {
        $due = $this->recur('2017-09-21');

        $this->assertContains($due, $this->listed(new \DateTime('2017-09-21')));
        $this->assertNotContains($due, $this->listed(new \DateTime('2017-09-20')));
        $this->assertContains($due, $this->listed(new \DateTime('2017-09-20'), true), 'all lists what is not yet due');
    }

    public function testANeverGeneratedScheduleIsDueOnceItHasStarted(): void
    {
        $orderNo = $this->recur(null, null, '2017-09-21');

        $this->assertNotContains($orderNo, $this->listed(new \DateTime('2017-09-20')), 'not before its start');
        $this->assertContains($orderNo, $this->listed(new \DateTime('2017-09-21')));
    }

    public function testAnEndedScheduleIsNotListed(): void
    {
        $orderNo = $this->recur('2017-09-21', '2017-09-21');

        $this->assertNotContains($orderNo, $this->listed(new \DateTime('2017-09-21')));
        $this->assertNotContains($orderNo, $this->listed(new \DateTime('2017-09-21'), true));
    }

    public function testDueCarriesTheCustomerBranchAndCustomerReference(): void
    {
        $order = $this->anySalesOrder();
        $this->recur('2017-09-21', null, '2016-04-01', (int) $order['order_no']);
        $row = $this->pdo->query(
            'SELECT debtor_no, branch_code, customer_ref FROM ' . $this->prefix . 'sales_orders'
            . ' WHERE trans_type = 30 AND order_no = ' . (int) $order['order_no']
        )->fetch(\PDO::FETCH_ASSOC);

        $found = null;
        foreach ((new RecurringInvoiceService())->due(new \DateTime('2017-09-21'), false, $this->pdo) as $model) {
            if ((int) $model->orderNo === (int) $order['order_no']) {
                $found = $model;
            }
        }

        $this->assertNotNull($found);
        $this->assertSame((string) $row['debtor_no'], (string) $found->debtorNo);
        $this->assertSame((string) $row['branch_code'], (string) $found->branchCode);
        $this->assertSame((string) $row['customer_ref'], (string) $found->customerRef);
    }

    public function testDueListsOnlySalesOrders(): void
    {
        // A schedule whose trans_no is a quotation's number and no sales order's:
        // the join on sales_orders alone would pick up the quotation.
        $quotation = $this->pdo->query(
            'SELECT q.order_no FROM ' . $this->prefix . 'sales_orders q WHERE q.trans_type = 32'
            . ' AND q.order_no NOT IN (SELECT order_no FROM ' . $this->prefix . 'sales_orders WHERE trans_type = 30)'
            . ' AND q.order_no NOT IN (SELECT trans_no FROM ' . $this->prefix . 'sales_recurring)'
            . ' ORDER BY q.order_no LIMIT 1'
        )->fetchColumn();
        if (!$quotation) {
            $this->markTestSkipped('the loaded dataset has no quotation-only order number');
        }
        $this->recur('2017-09-21', null, '2016-04-01', (int) $quotation);

        $this->assertNotContains((int) $quotation, $this->listed(new \DateTime('2017-09-21'), true));
    }
}
```

`sgw_sales:tests/Http/fixtures/service-probe.php` — replace the whole file:

```php
<?php

/**
 * What a caller that is not a FrontAccounting page looks like: FrontAccounting
 * booted and a user logged in, and nothing else - no ui.inc, no reporting.inc,
 * no view. ServiceWithoutAPageTest copies this into the module's root for the
 * length of a test, because Apache will not serve anything under tests/.
 *
 * ?order=N&date=Y-m-d[&early=1][&email=1][&fail=invoice]
 */

use SGW_Sales\service\GenerationRefused;
use SGW_Sales\service\RecurringInvoiceService;

$page_security = 'SA_SALESINVOICE';
$path_to_root = "../..";
include_once($path_to_root . "/includes/session.inc");

$service = ($_GET['fail'] ?? '') === 'invoice'
    ? new class extends RecurringInvoiceService {
        protected function writeInvoice(int $deliveryNo, string $faDate, string $comment): int
        {
            throw new \RuntimeException("the invoice failed after delivery $deliveryNo was written");
        }
    }
    : new RecurringInvoiceService();

$out = array();
try {
    $_POST['PARAM_0'] = 'the caller had this here';
    $date = new \DateTime($_GET['date'] ?? 'today');
    $generated = $service->generate((int) $_GET['order'], $date, !empty($_GET['early']));
    $out['generated'] = (array) $generated;
    if (!empty($_GET['email'])) {
        $service->emailInvoice($generated->invoiceNo);
        $out['emailed'] = true;
    }
} catch (\Throwable $e) {
    $out['error'] = get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
    $out['errorClass'] = get_class($e);
    $out['field'] = $e instanceof GenerationRefused ? $e->field() : null;
}
$out['post'] = $_POST;
$out['view_loaded'] = class_exists('GenerateRecurringView', false);
$out['transaction_level'] = $GLOBALS['transaction_level'] ?? null;

echo '<<<JSON' . json_encode($out) . 'JSON>>>';
```

`sgw_sales:tests/Http/HttpTestCase.php` — add these helpers after `invoiceCount()`:

```php
    protected function deliveryCount(int $orderNo): int
    {
        $statement = Anorm::pdo()->prepare(
            'SELECT COUNT(*) FROM ' . DB::prefix('debtor_trans') . ' WHERE type=' . ST_CUSTDELIVERY . ' AND order_=:order'
        );
        $statement->execute([':order' => $orderNo]);
        return (int) $statement->fetchColumn();
    }

    /** The GL of a delivery and an invoice sums to zero, as every FrontAccounting posting must. */
    protected function assertGlBalanced(int $deliveryNo, int $invoiceNo): void
    {
        $statement = Anorm::pdo()->prepare(
            'SELECT ROUND(SUM(amount), 2) FROM ' . DB::prefix('gl_trans')
            . ' WHERE (type=' . ST_CUSTDELIVERY . ' AND type_no=:d) OR (type=' . ST_SALESINVOICE . ' AND type_no=:i)'
        );
        $statement->execute([':d' => $deliveryNo, ':i' => $invoiceNo]);
        $this->assertEquals(0.0, (float) $statement->fetchColumn(), 'GL not balanced');
    }

    /** A monthly recurrence on the 1st whose next date is $dtNext (null: never generated, started 2016). */
    protected function monthlyOnThe1st(int $orderNo, ?string $dtNext, ?string $dtEnd = null): SalesRecurringModel
    {
        $recurrence = $this->dueMonthly($orderNo);
        $recurrence->dtNext = $dtNext;
        $recurrence->dtEnd = $dtEnd;
        $recurrence->write();
        return $recurrence;
    }
```

`sgw_sales:tests/Http/ServiceWithoutAPageTest.php` — replace the whole file:

```php
<?php

namespace SGW_Sales\Tests\Http;

use SGW_Sales\db\SalesRecurringModel;

/**
 * RecurringInvoiceService called the way an API calls it: FrontAccounting
 * booted, a user logged in, and none of what the Generate Recurring Invoices
 * page includes. Whatever the service needs of FrontAccounting it has to ask
 * for itself - Cart wants count_array() from ui.inc, which every FA page has
 * loaded and nothing else has.
 *
 * Not rolled back, as GenerateInvoiceTest is not: the documents stay in this
 * stack's database, and the recurrence is deleted afterwards.
 */
class ServiceWithoutAPageTest extends HttpTestCase
{
    /** Served from the module's root: Apache denies tests/. */
    private const PROBE = __DIR__ . '/../../test-service-probe.php';

    /** @var SalesRecurringModel|null */
    private $recurrence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectDb();
        if (!@copy(__DIR__ . '/fixtures/service-probe.php', self::PROBE)) {
            $this->markTestSkipped('cannot write the probe into the module directory');
        }
    }

    protected function tearDown(): void
    {
        @unlink(self::PROBE);
        if ($this->recurrence && $this->recurrence->id) {
            $this->recurrence->delete();
        }
    }

    private function probe(int $orderNo, string $date, array $more = []): array
    {
        $query = http_build_query(['order' => $orderNo, 'date' => $date] + $more);
        [$status, $body] = $this->request('/modules/sgw_sales/test-service-probe.php?' . $query);
        $this->assertSame(200, $status);
        if (!preg_match('/<<<JSON(.*)JSON>>>/s', $body, $m)) {
            $this->fail('the probe did not answer: ' . substr(strip_tags($body), 0, 500));
        }
        return json_decode($m[1], true);
    }

    private function today(): string
    {
        return (new \DateTime('today'))->format('Y-m-d');
    }

    public function testADueOrderIsDeliveredAndInvoicedInOneGoWithNoPageBehindIt(): void
    {
        $orderNo = $this->unrecurredOrder();
        $invoices = $this->invoiceCount($orderNo);
        $deliveries = $this->deliveryCount($orderNo);
        $this->recurrence = $this->dueMonthly($orderNo);

        $out = $this->probe($orderNo, $this->today(), ['email' => 1]);

        $this->assertArrayNotHasKey('error', $out, $out['error'] ?? '');
        $this->assertFalse($out['view_loaded']);
        $generated = $out['generated'];
        $this->assertSame($orderNo, $generated['orderNo']);
        $this->assertGreaterThan(0, $generated['deliveryNo']);
        $this->assertGreaterThan(0, $generated['invoiceNo']);
        $this->assertStringStartsWith('Invoice for period 1 ', $generated['comment']);
        $this->assertTrue($out['emailed']);
        $this->assertSame(0, $out['transaction_level'], 'the transaction is closed when generate() returns');

        $expected = (new \DateTime('first day of next month'))->format('Y-m-d');
        $this->assertSame($expected, $generated['dtNext']);
        $this->assertSame($expected, SalesRecurringModel::readByTransNo($orderNo)->dtNext);
        $this->assertSame($invoices + 1, $this->invoiceCount($orderNo));
        $this->assertSame($deliveries + 1, $this->deliveryCount($orderNo));
        $this->assertGlBalanced($generated['deliveryNo'], $generated['invoiceNo']);

        // Emailing borrows $_POST for FrontAccounting's report; the caller gets its own back.
        $this->assertSame(['PARAM_0' => 'the caller had this here'], $out['post']);
    }

    public function testTheInvoiceIsDatedAsAskedNotToday(): void
    {
        $orderNo = $this->unrecurredOrder();
        $this->recurrence = $this->dueMonthly($orderNo);
        $date = (new \DateTime('first day of this month'))->format('Y-m-d');

        $out = $this->probe($orderNo, $date);

        $this->assertArrayNotHasKey('error', $out, $out['error'] ?? '');
        $statement = \Anorm\Anorm::pdo()->prepare(
            'SELECT tran_date FROM ' . \SGW_Sales\db\DB::prefix('debtor_trans') . ' WHERE type=10 AND trans_no=:no'
        );
        $statement->execute([':no' => $out['generated']['invoiceNo']]);
        $this->assertSame($date, $statement->fetchColumn());
    }

    public function testARetryOnTheSameDateBillsNothingTwice(): void
    {
        $orderNo = $this->unrecurredOrder();
        $this->recurrence = $this->dueMonthly($orderNo);
        $first = $this->probe($orderNo, $this->today());
        $this->assertArrayNotHasKey('error', $first, $first['error'] ?? '');
        $invoices = $this->invoiceCount($orderNo);

        $again = $this->probe($orderNo, $this->today());

        $this->assertSame('SGW_Sales\service\RecurrenceNotDue', $again['errorClass'] ?? null, $again['error'] ?? '');
        $this->assertSame($invoices, $this->invoiceCount($orderNo));
    }

    public function testAnOrderNotYetDueIsRefusedUnlessAskedForEarly(): void
    {
        $orderNo = $this->unrecurredOrder();
        $tomorrow = (new \DateTime('tomorrow'))->format('Y-m-d');
        $this->recurrence = $this->monthlyOnThe1st($orderNo, $tomorrow);
        $invoices = $this->invoiceCount($orderNo);

        $refused = $this->probe($orderNo, $this->today());
        $this->assertSame('SGW_Sales\service\RecurrenceNotDue', $refused['errorClass'] ?? null, $refused['error'] ?? '');
        $this->assertSame($invoices, $this->invoiceCount($orderNo));

        $early = $this->probe($orderNo, $this->today(), ['early' => 1]);
        $this->assertArrayNotHasKey('error', $early, $early['error'] ?? '');
        $this->assertSame($invoices + 1, $this->invoiceCount($orderNo));
    }

    public function testAnEndedScheduleIsRefused(): void
    {
        $orderNo = $this->unrecurredOrder();
        $this->recurrence = $this->monthlyOnThe1st($orderNo, null, $this->today());

        $out = $this->probe($orderNo, $this->today());

        $this->assertSame('SGW_Sales\service\RecurrenceEnded', $out['errorClass'] ?? null, $out['error'] ?? '');
    }

    public function testADateOutsideTheFiscalYearIsRefusedAndNothingIsWritten(): void
    {
        $orderNo = $this->unrecurredOrder();
        $this->recurrence = $this->monthlyOnThe1st($orderNo, '1999-12-01');
        $invoices = $this->invoiceCount($orderNo);
        $deliveries = $this->deliveryCount($orderNo);

        $out = $this->probe($orderNo, '2000-01-01');

        $this->assertSame('SGW_Sales\service\GenerationRefused', $out['errorClass'] ?? null, $out['error'] ?? '');
        $this->assertSame('date', $out['field']);
        $this->assertSame($invoices, $this->invoiceCount($orderNo));
        $this->assertSame($deliveries, $this->deliveryCount($orderNo));
        $this->assertSame('1999-12-01', SalesRecurringModel::readByTransNo($orderNo)->dtNext);
    }

    public function testAFailureAfterTheDeliveryRollsEverythingBack(): void
    {
        $orderNo = $this->unrecurredOrder();
        $this->recurrence = $this->dueMonthly($orderNo);
        $invoices = $this->invoiceCount($orderNo);
        $deliveries = $this->deliveryCount($orderNo);

        $out = $this->probe($orderNo, $this->today(), ['fail' => 'invoice']);

        $this->assertStringStartsWith('RuntimeException: the invoice failed after delivery', $out['error'] ?? '');
        $this->assertSame(0, $out['transaction_level']);
        $this->assertSame($deliveries, $this->deliveryCount($orderNo), 'the delivery rolled back with the invoice');
        $this->assertSame($invoices, $this->invoiceCount($orderNo));
        $this->assertNull(SalesRecurringModel::readByTransNo($orderNo)->dtNext, 'the schedule did not move on');
    }

    public function testOrderWithoutARecurrenceIsRefusedAndNothingIsInvoiced(): void
    {
        $orderNo = $this->unrecurredOrder();
        $before = $this->invoiceCount($orderNo);

        $out = $this->probe($orderNo, $this->today());

        $this->assertSame('SGW_Sales\service\RecurrenceNotFound', $out['errorClass'] ?? null, $out['error'] ?? '');
        $this->assertSame($before, $this->invoiceCount($orderNo));
    }
}
```

- [ ] **Step 3: Run to see them fail**

```bash
docker/fa-sgw-sales test --testsuite db --filter RecurringInvoiceServiceTest
docker/fa-sgw-sales test --testsuite http --filter ServiceWithoutAPageTest
```

Expected: FAIL — `due()` gets a `DateTime` where it expects a bool (TypeError / wrong rows), `generate()` gets a `DateTime` as `$email`, `deliveryNo`/`errorClass` missing, `RecurrenceNotDue`/`GenerationRefused` not found.

- [ ] **Step 4: Implement**

`sgw_sales:includes/service/RecurrenceNotDue.php`:

```php
<?php

namespace SGW_Sales\service;

/** The recurrence is not due on the date asked: nothing to invoice yet (or any more). */
class RecurrenceNotDue extends \DomainException
{
}
```

`sgw_sales:includes/service/GenerationRefused.php`:

```php
<?php

namespace SGW_Sales\service;

/**
 * A check refused the generation before anything was written: the date outside the
 * fiscal year, a missing exchange rate, a customer on hold, not enough stock, or
 * FrontAccounting not writing a document.
 */
class GenerationRefused extends \RuntimeException
{
    /** @var string|null */
    private $field;

    /** @var string[] */
    private $messages;

    /**
     * @param string|null $field the input the refusal is about ('date'), if any
     * @param string[] $messages FrontAccounting's messages, if it gave any
     */
    public function __construct(string $message, ?string $field = null, array $messages = [])
    {
        parent::__construct($message);
        $this->field = $field;
        $this->messages = $messages === [] ? [$message] : $messages;
    }

    public function field(): ?string
    {
        return $this->field;
    }

    /** @return string[] */
    public function messages(): array
    {
        return $this->messages;
    }
}
```

`sgw_sales:includes/service/GeneratedInvoice.php` — replace the whole file:

```php
<?php

namespace SGW_Sales\service;

/**
 * What RecurringInvoiceService::generate() did: one delivery and one invoice, and
 * the recurrence moved on. Emailing is the caller's (the page's rep107, or the
 * API's report child), after the transaction has committed.
 */
class GeneratedInvoice
{
    /** @var int The sales order the invoice was raised from */
    public $orderNo;

    /** @var int The delivery's transaction number */
    public $deliveryNo;

    /** @var int The invoice's transaction number */
    public $invoiceNo;

    /** @var string The comment on the invoice: the period it covers */
    public $comment;

    /** @var string The recurrence's new dt_next, Y-m-d */
    public $dtNext;

    public function __construct(int $orderNo, int $deliveryNo, int $invoiceNo, string $comment, string $dtNext)
    {
        $this->orderNo = $orderNo;
        $this->deliveryNo = $deliveryNo;
        $this->invoiceNo = $invoiceNo;
        $this->comment = $comment;
        $this->dtNext = $dtNext;
    }
}
```

`sgw_sales:includes/db/GenerateRecurringModel.php` — replace `find()` and add the three properties (keep the constructor and the rest):

```php
	/**
	 * The recurrences due on $asOf (all that have not ended with $showAll), soonest
	 * first. Due: not ended (dt_end after $asOf), and either dt_next on or before
	 * $asOf, or never generated (dt_next NULL) and already started.
	 *
	 * @param bool $showAll include those not yet due
	 * @param \DateTimeInterface|null $asOf today by default
	 * @param \PDO|null $pdo the connection to read on; Anorm's default by default.
	 *   The GraphQL extension passes its request's connection (the token's company).
	 * @return \Generator<GenerateRecurringModel>|GenerateRecurringModel[]|boolean
	 */
	public static function find($showAll, ?\DateTimeInterface $asOf = null, ?\PDO $pdo = null) {
		$pdo = $pdo ?: Anorm::pdo();
		$date = ($asOf ?: new \DateTime())->format('Y-m-d');
		$transactionType = ST_SALESORDER;
		$where = "so.trans_type=" . $transactionType
			. " AND (sr.dt_end>:asOfEnd OR sr.dt_end IS NULL)";
		$params = [':asOfEnd' => $date];
		if (!$showAll) {
			$where .= " AND (sr.dt_next<=:asOfNext OR (sr.dt_next IS NULL AND sr.dt_start<=:asOfStart))";
			$params[':asOfNext'] = $date;
			$params[':asOfStart'] = $date;
		}
		$result = DataMapper::find(GenerateRecurringModel::class, $pdo)
			->select("so.order_no,so.reference,so.debtor_no,so.branch_code,so.customer_ref,debtor.name,so.ord_date," .
				"sr.id,sr.dt_start,sr.dt_end,sr.dt_next,sr.auto,sr.repeats,sr.every,sr.occur," .
				"inv.dt_last,inv.inv_total,so.total,so.trans_type")
			->from(DB::prefix("sales_orders") . " AS so")
			->join("LEFT JOIN " .
				"(SELECT order_, max(tran_date) dt_last, sum(ov_amount) inv_total FROM ". DB::prefix("debtor_trans") . " WHERE type=" . ST_SALESINVOICE . " GROUP BY order_) " .
				"inv ON inv.order_=so.order_no")
			->join("JOIN " . DB::prefix("sales_recurring") . " AS sr ON sr.trans_no=so.order_no")
			->join("JOIN " . DB::prefix("debtors_master") . " AS debtor ON so.debtor_no=debtor.debtor_no")
			->where($where, $params)
			->groupBy("so.order_no")
			->orderBy("sr.dt_next")
			->some();
		return $result;
	}

	public $orderNo;
	public $reference;
	public $debtorNo;
	public $branchCode;
	public $customerRef;
	public $name;
```

(The `trans_type` restriction moves from the invoice join's `ON` clause, where it restricted nothing, to the `WHERE`. Check the property-to-column mapping on the stack in Step 5: `DataMapper::createByClass` maps `debtorNo` to `debtor_no`; if the alias does not map, select the columns `AS debtor_no` etc. explicitly.)

`sgw_sales:includes/service/RecurringInvoiceService.php` — replace the whole file:

```php
<?php

namespace SGW_Sales\service;

use SGW_Sales\db\GenerateRecurringModel;

/**
 * Recurring invoices: which orders are due, and raising the invoice for one.
 *
 * Used by the Generate Recurring Invoices page and by the GraphQL extension.
 * Needs FrontAccounting booted and a user logged in: the documents are written by
 * FrontAccounting's own Cart, which is what posts to the ledger.
 *
 * generate() writes the delivery, the invoice and the recurrence's next date in
 * one FrontAccounting transaction (on FrontAccounting's connection, not Anorm's),
 * so a failure leaves nothing behind and a retry after success finds the order
 * no longer due. It does not email: the caller does, after the commit.
 */
class RecurringInvoiceService
{
    /**
     * Recurrences due on $asOf (all that have not ended, with $all), soonest first.
     *
     * @param \PDO|null $pdo the connection to read on; Anorm's default by default
     * @return GenerateRecurringModel[]
     */
    public function due(\DateTimeInterface $asOf, bool $all = false, ?\PDO $pdo = null): array
    {
        $found = GenerateRecurringModel::find($all, $asOf, $pdo);
        if (!$found) {
            return [];
        }
        return is_array($found) ? $found : iterator_to_array($found, false);
    }

    /**
     * Deliver and invoice a recurring sales order dated $invoiceDate, and move its
     * recurrence on to the next date after it.
     *
     * @param bool $allowEarly invoice an order that is not yet due (the page's
     *   "Show All", where a person picks it); never from the API
     * @throws RecurrenceNotFound the order has no recurrence, or no longer exists
     * @throws RecurrenceEnded the recurrence has ended by $invoiceDate
     * @throws RecurrenceNotDue not due on $invoiceDate (and not $allowEarly)
     * @throws GenerationRefused a check refused it; nothing was written
     */
    public function generate(int $orderNo, \DateTimeInterface $invoiceDate, bool $allowEarly = false): GeneratedInvoice
    {
        self::includeFa();
        $date = \DateTime::createFromFormat('!Y-m-d', $invoiceDate->format('Y-m-d'));
        $ymd = $date->format('Y-m-d');

        begin_transaction();
        try {
            // Locked for the whole transaction: two generations of one order queue,
            // and the second finds it no longer due.
            $recurrence = self::lockRecurrence($orderNo);
            if ($recurrence === null || !self::salesOrderExists($orderNo)) {
                throw new RecurrenceNotFound("Sales order $orderNo has no recurrence");
            }
            if ($recurrence->dtEnd && $recurrence->dtEnd <= $ymd) {
                throw new RecurrenceEnded("The recurrence of sales order $orderNo ended on " . $recurrence->dtEnd);
            }
            if (!$allowEarly && !self::isDue($recurrence, $ymd)) {
                throw new RecurrenceNotDue(
                    "Sales order $orderNo is not due on $ymd: next due " . ($recurrence->dtNext ?: $recurrence->dtStart)
                );
            }
            $faDate = sql2date($ymd);
            if (!is_date_in_fiscalyear($faDate)) {
                throw new GenerationRefused("$ymd is out of the fiscal year or closed for further data entry.", 'date');
            }

            $comment = RecurrenceSchedule::comment($recurrence, $date);
            $deliveryNo = $this->writeDelivery($orderNo, $faDate);
            $invoiceNo = $this->writeInvoice($deliveryNo, $faDate, $comment);

            $next = RecurrenceSchedule::nextDateAfter($recurrence, $date)->format('Y-m-d');
            db_query(
                'UPDATE ' . TB_PREF . 'sales_recurring SET dt_next=' . db_escape($next)
                . ' WHERE trans_no=' . db_escape($orderNo),
                'The recurrence could not be moved on'
            );
            commit_transaction();
        } catch (\Throwable $e) {
            cancel_transaction();
            throw $e;
        }

        return new GeneratedInvoice($orderNo, $deliveryNo, $invoiceNo, $comment, $next);
    }

    /**
     * The delivery: every line's full quantity again (a recurring order is delivered
     * whole each period), repriced from the price list on the date.
     */
    protected function writeDelivery(int $orderNo, string $faDate): int
    {
        global $SysPrefs;

        $delivery = new \Cart(ST_SALESORDER, array($orderNo), true);
        // customer_delivery.php :408-415 shows an on-hold customer no form.
        $customer = get_customer_to_order($delivery->customer_id);
        if ($customer && (int) $customer['dissallow_invoices'] === 1) {
            throw new GenerationRefused(
                'The selected customer account is currently on hold. Please contact the credit control personnel to discuss.'
            );
        }
        $delivery->document_date = $faDate;
        $delivery->due_date = $faDate;
        $delivery->reference = 'auto';
        foreach ($delivery->line_items as $item) {
            $item->qty_done = 0;
            $item->qty_dispatched = $item->quantity;
            self::reprice($item, $delivery);
        }
        $delivery->Comments = 'Auto generated recurring delivery.';
        // FrontAccounting writes with 1.0 where a rate is missing (includes/banking.inc:30-45).
        if (!db_has_currency_rates($delivery->customer_currency, $faDate)) {
            throw new GenerationRefused(
                'There is no exchange rate for ' . $delivery->customer_currency . ' as of ' . date2sql($faDate) . '.',
                'date'
            );
        }
        // customer_delivery.php :205-209
        if (!$SysPrefs->allow_negative_stock() && ($low = $delivery->check_qoh())) {
            $message = 'This document cannot be processed because there is insufficient quantity for items marked.';
            throw new GenerationRefused($message, null, array_merge([$message], array_map('strval', $low)));
        }

        $no = $delivery->write(1);
        if (!$no || $no == -1) {
            throw new GenerationRefused('FrontAccounting did not write the delivery.');
        }
        return (int) $no;
    }

    /**
     * The invoice for that delivery, dated $faDate, due by the payment terms from that
     * date (not prepare_child()'s today), with its own reference for that date.
     */
    protected function writeInvoice(int $deliveryNo, string $faDate, string $comment): int
    {
        global $Refs;

        $invoice = new \Cart(ST_CUSTDELIVERY, array($deliveryNo), true);
        $invoice->document_date = $faDate;
        foreach ($invoice->line_items as $item) {
            $item->qty_done = 0;
            self::reprice($item, $invoice);
        }
        // No way to register a cash payment with a recurring invoice at once.
        $invoice->payment_terms['cash_sale'] = false;
        $invoice->due_date = get_invoice_duedate($invoice->payment, $invoice->document_date);
        $invoice->reference = $Refs->get_next(
            ST_SALESINVOICE,
            null,
            array('date' => $faDate, 'customer' => $invoice->customer_id, 'branch' => $invoice->Branch)
        );
        $invoice->Comments = $comment;

        $no = $invoice->write(1);
        if (!$no || $no == -1) {
            throw new GenerationRefused('FrontAccounting did not write the invoice.');
        }
        return (int) $no;
    }

    /**
     * Email the given $invoiceNo (transaction number) through FrontAccounting's
     * invoice report, which takes its parameters from $_POST and nowhere else.
     * What the caller had there is put back afterwards. The page's path: a
     * FrontAccounting page has session.inc loaded; the API emails through its own
     * report child instead.
     * @param int $invoiceNo
     */
    public function emailInvoice($invoiceNo)
    {
        $saved = array();
        $keys = array('REP_ID');
        for ($i = 0; $i < 8; $i++) {
            $keys[] = 'PARAM_' . $i;
        }
        foreach ($keys as $key) {
            if (array_key_exists($key, $_POST)) {
                $saved[$key] = $_POST[$key];
            }
        }

        /* rep107.php prints as it is included, unless the PARAM_x values are
         * false, in which case it exits early. See rep107.php for details.
         * PARAM_0..7: from, to, currency, email, pay_service, comments,
         * customer, orientation.
         */
        for ($i = 0; $i < 8; $i++) {
            $_POST['PARAM_' . $i] = false;
        }
        require_once(__DIR__ . '/../../../../reporting/rep107.php');

        $_POST['PARAM_0'] = $invoiceNo;
        $_POST['PARAM_1'] = $invoiceNo;
        $_POST['PARAM_2'] = ALL_TEXT; // Empty string
        $_POST['PARAM_3'] = 1;
        $_POST['REP_ID'] = '107';
        try {
            print_invoices();
        } finally {
            foreach ($keys as $key) {
                unset($_POST[$key]);
            }
            foreach ($saved as $key => $value) {
                $_POST[$key] = $value;
            }
        }
    }

    /** Due on $ymd: next date reached, or never generated and already started. */
    private static function isDue(\stdClass $recurrence, string $ymd): bool
    {
        if ($recurrence->dtNext) {
            return $recurrence->dtNext <= $ymd;
        }
        return $recurrence->dtStart <= $ymd;
    }

    /**
     * The recurrence row on FrontAccounting's connection, locked (FOR UPDATE), as the
     * plain object RecurrenceSchedule reads. SalesRecurringModel is not used: it
     * reads on Anorm's own connection, outside this transaction.
     */
    private static function lockRecurrence(int $orderNo): ?\stdClass
    {
        $row = db_fetch_assoc(db_query(
            'SELECT * FROM ' . TB_PREF . 'sales_recurring WHERE trans_no=' . db_escape($orderNo) . ' FOR UPDATE',
            'The recurrence could not be read'
        ));
        if (!$row) {
            return null;
        }
        $recurrence = new \stdClass();
        $recurrence->transNo = (int) $row['trans_no'];
        $recurrence->dtStart = $row['dt_start'];
        $recurrence->dtEnd = self::sqlDate($row['dt_end'] ?? null);
        $recurrence->dtNext = self::sqlDate($row['dt_next'] ?? null);
        $recurrence->auto = (int) $row['auto'];
        $recurrence->repeats = $row['repeats'];
        $recurrence->every = (int) $row['every'];
        $recurrence->occur = (string) $row['occur'];
        return $recurrence;
    }

    private static function sqlDate(?string $value): ?string
    {
        return $value === null || $value === '' || $value === '0000-00-00' ? null : $value;
    }

    private static function salesOrderExists(int $orderNo): bool
    {
        return (bool) db_fetch(db_query(
            'SELECT 1 FROM ' . TB_PREF . 'sales_orders WHERE trans_type=' . ST_SALESORDER
            . ' AND order_no=' . db_escape($orderNo),
            'The sales order could not be read'
        ));
    }

    /** The template price where the price list has none (as before). */
    private static function reprice($item, \Cart $cart): void
    {
        $price = get_price(
            $item->stock_id,
            $cart->customer_currency,
            $cart->sales_type,
            $cart->price_factor,
            $cart->document_date
        );
        if ($price != 0) {
            $item->price = $price;
        }
    }

    /**
     * Everything Cart needs, asked for here because a caller that is not a
     * FrontAccounting page has not: FA's sales code takes ui.inc for granted
     * (count_array() in sales_db.inc, for one), and every FA page includes it.
     * The included files include others by $path_to_root, from the scope they land in.
     */
    private static function includeFa(): void
    {
        global $path_to_root;
        include_once($path_to_root . '/includes/ui.inc');
        include_once($path_to_root . '/sales/includes/cart_class.inc');
        include_once($path_to_root . '/sales/includes/sales_db.inc');
    }
}
```

Notes for the implementer:
- In the API the whole of `generate()` runs inside the module's `ServiceCall`/`FaTransaction` (Task 5), so its `begin_transaction()` nests (`includes/db/sql_functions.inc:20-47`: only the outermost level issues `BEGIN`/`COMMIT`). Its `cancel_transaction()` rolls back and resets the level to 0; the module's `FaTransaction` then cancels again, which is a no-op at level 0. Both orders are correct.
- `includeFa()` uses `$path_to_root`, which is `".."`-relative in the page and set by the module's `Bootstrap` in the API. If `includeFa()` fails in the API (Task 5) because `$path_to_root` is relative to a different working directory, switch it to `\FA\GraphQL\Fa\Bootstrap::includeFa()` when that class exists, and keep the page's path otherwise — record it as a deviation.

`sgw_sales:includes/controller/GenerateRecurring.php` — replace `run()`'s generate branch and `table()`:

```php
	public function run() {
		global $Ajax;
		if (get_post('GenerateInvoices')) {
			$Ajax->activate('_page_body');
			$today = new \DateTime();
			// "Show All" lists orders not yet due; a person picking one generates it early.
			$early = (bool) check_value('show_all');
			// Collected first: emailing an invoice goes through $_POST.
			foreach (self::selectedOrders($_POST) as $orderNo) {
				try {
					$generated = $this->_service->generate($orderNo, $today, $early);
				} catch (\Exception $e) {
					display_error(sprintf(_('Sales order %d was not invoiced: %s'), $orderNo, $e->getMessage()));
					continue;
				}
				// After the commit: a failure to send leaves the invoice written and the order moved on.
				$this->_service->emailInvoice($generated->invoiceNo);
				$this->_view->generatedInvoice($orderNo);
			}
			return;
		}
		if (list_updated('select_all')) {
			$Ajax->activate('_page_body');
			$this->_force = check_value('select_all') ? self::FORCE_CHECK : self::FORCE_CLEAR; 
		}
		if (list_updated('show_all')) {
			$Ajax->activate('_page_body');
			$this->_showAll = check_value('show_all');
		}
		$this->_view->viewList();
	}
```

```php
	public function table() {
		$k = 0;
		foreach ($this->_service->due(new \DateTime(), (bool) $this->_showAll) as $model) {
			if ($this->_force != self::FORCE_NO) {
				$key = 's_' . $model->orderNo;
				$_POST[$key] = $this->_force;
			}
			$this->_view->tableRow($model, $k);
		}
	}
```

`sgw_sales:README.md` — add a section:

```markdown
## Generating recurring invoices

`SGW_Sales\service\RecurringInvoiceService` is the one place invoices are
generated, for the Generate Recurring Invoices page and for the GraphQL API
(`modules/graphql`'s extension, `recurringGenerate`):

- `due(DateTimeInterface $asOf, bool $all = false)` — the sales orders whose
  recurrence is due on `$asOf`: not ended, and either their next date reached, or
  never generated and already started.
- `generate(int $orderNo, DateTimeInterface $invoiceDate)` — delivers every line
  of the order again and invoices it, dated `$invoiceDate`, and moves the
  recurrence on, in one FrontAccounting transaction. It refuses an order that is
  not due on that date, so running it twice bills once. It checks the fiscal year,
  the exchange rate, a customer on hold and stock, and writes nothing if any fails.
  It does not email: the page emails through FrontAccounting's invoice report
  afterwards; the API through its own report process.
```

- [ ] **Step 5: Run to see them pass**

```bash
docker/fa-sgw-sales test --testsuite db --filter RecurringInvoiceServiceTest
docker/fa-sgw-sales test --testsuite http --filter ServiceWithoutAPageTest
docker/fa-sgw-sales test --testsuite http --filter GenerateInvoiceTest
docker/fa-sgw-sales test --testsuite unit
```

Expected: PASS. `GenerateInvoiceTest` drives the page; it must still generate and email (its order is due in its setup — if it relied on generating a not-yet-due order without "Show All", make its recurrence due and record that in the report). If `testDueCarriesTheCustomerBranchAndCustomerReference` fails because the new properties stay null, apply the `AS` column aliases noted above.

- [ ] **Step 6: Run everything on sgw_sales' stack**

```bash
docker/fa-sgw-sales test
docker/fa-sgw-sales lint
docker/fa-sgw-sales analyze
```

Expected: all suites PASS (the http suite's documents stay in this stack's database, as `GenerateInvoiceTest`'s always have); lint and analyze clean.

- [ ] **Step 7: Gates and commit (sgw_sales, branch `feature/graphql-extension`)**

```bash
git add includes/service includes/db/GenerateRecurringModel.php includes/controller/GenerateRecurring.php \
    tests/Db/RecurringInvoiceServiceTest.php tests/Http README.md
git commit -m "Harden RecurringInvoiceService: explicit dates, checks, one transaction

generate() delivers and invoices a recurring order dated as asked and moves
its recurrence on in one FrontAccounting transaction, refusing an order not
due on that date (so a retry bills nothing twice), a date outside the fiscal
year, a missing exchange rate, a customer on hold and missing stock. due()
takes the date and lists sales orders only; a never-generated schedule is due
once it has started. Emailing moves out of generate(): the page emails after
the commit.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013U8a6JAbsHc4c1KcSLiaYK"
```

---

### Task 5: sgw_sales extension — recurringDueList and recurringGenerate; graphql — the report child's company

Spec §4.2 and §4.3. `sgw_sales`' extension gains a query and a mutation over Task 4's `RecurringInvoiceService`: the list of recurring orders due on a date, and generation whose items are **independent** (each its own transaction under the document lock; one failure reports `error` and the rest continue — a deliberate departure from the core's atomic batches, spec §4.2), emailing after each item commits through the module's `InvoiceMailer`. In the graphql module, `bin/fa-report` preselects the target company before `session.inc` runs, so the report child logs in wherever this module is active for that company, not only where it is active in the default company.

Work in both repos: `sgw_sales` on `feature/graphql-extension`, graphql on `feature/release-4`. The extension's tests live in `sgw_sales:tests/GraphQL/` and run inside the graphql module's stack (`docker/fa-graphql test-extension sgw_sales`, Task 3), which bind-mounts the `sgw_sales` checkout (`SGW_SALES_PATH` in `docker/.env`) and has the mail catcher.

**Files:**
- Create: `sgw_sales:includes/GraphQL/RecurringGeneration.php`
- Create: `sgw_sales:includes/GraphQL/Type/RecurringDueType.php`, `RecurringGenerateInputType.php`, `RecurringGenerateResultType.php`, `GenerateErrorType.php`
- Modify: `sgw_sales:includes/GraphQL/SgwSalesExtension.php` (`queryFields`, `mutationFields`)
- Test: `sgw_sales:tests/GraphQL/RecurringGenerationTestCase.php`, `sgw_sales:tests/GraphQL/Integration/RecurringDueListTest.php`, `sgw_sales:tests/GraphQL/Integration/RecurringGenerateTest.php`
- Modify: `graphql:bin/fa-report` (preselect the company)
- Modify: `graphql:docker/fa-graphql` (`db second-company add|remove`, `test-default-company`, `ci` runs it)
- Test: `graphql:tests/Integration/Billing/ReportDefaultCompanyTest.php`
- Modify: `graphql:docs/superpowers/specs/2026-09-21-foundation-design.md` is NOT touched; `graphql:docs/superpowers/specs/2026-09-26-release-3-billing-design.md` §6's multi-company caveat gains a *(revised: Release 4)* note that it is fixed.

**Interfaces:**
- Consumes (Task 1, graphql): `FA\GraphQL\Extension\ExtensionContext` (`container()`, `mailer()`), `AbstractExtension`; `FA\GraphQL\Auth\Guard::require(string)`, `FA\GraphQL\Fa\DocumentLock::run(callable)`, `FA\GraphQL\Fa\Service\ServiceCall::run(callable)`, `FA\GraphQL\Fa\Service\IntKey::parse($value, string $field): int`, `FA\GraphQL\Fa\DateConversion::fromSql(?string)`, `FA\GraphQL\Error\{BadInput, FaRejected, NotFound}`, `FA\GraphQL\Type\Invoice\InvoiceEmailResultType` (the core's type, reused as the same instance from the container), `\Anorm\GraphQL\Type\DateType::instance()`, `InvoiceMailer::send(array $ids): array`.
- Consumes (Task 2, sgw_sales): `SGW_Sales\GraphQL\Type\RecurrenceRepeatsType` (enum `RecurrenceRepeats`, values `MONTH` = `'month'`, `YEAR` = `'year'`), `SGW_Sales\Tests\GraphQL\ExtensionTestCase` (extends the module's `SalesOrderTestCase`; `requireExtensionServesRecurrence()`, `participant()`), `phpunit-graphql.xml`.
- Consumes (Task 4, sgw_sales): `RecurringInvoiceService::due(\DateTimeInterface, bool, ?\PDO): array`, `::generate(int, \DateTimeInterface, bool): GeneratedInvoice`; `GeneratedInvoice` (`orderNo`, `deliveryNo`, `invoiceNo`, `dtNext`); `GenerateRecurringModel` (`orderNo`, `reference`, `debtorNo`, `branchCode`, `customerRef`, `dtStart`, `dtEnd`, `dtNext`, `repeats`, `every`, `occur`); exceptions `RecurrenceNotFound`, `RecurrenceEnded`, `RecurrenceNotDue`, `GenerationRefused` (`field()`).
- Produces (the schema, exactly):

```graphql
type Query {
  recurringDueList(asOf: Date): [RecurringDue!]!   # asOf defaults to today
}
type Mutation {
  recurringGenerate(input: [RecurringGenerateInput!]!): [RecurringGenerateResult!]!
}
type RecurringDue {
  orderId: ID!  customerId: ID!  branchId: ID!  reference: String  customerRef: String
  next: Date  repeats: RecurrenceRepeats!  every: Int!  day: Int  monthDay: String  end: Date
}
input RecurringGenerateInput { orderId: ID!  date: Date!  email: Boolean = false }
type RecurringGenerateResult {
  orderId: ID!  invoiceId: ID  deliveryId: ID  next: Date
  email: InvoiceEmailResult          # null when not asked, or the generation failed
  error: RecurringGenerateError      # null on success
}
type RecurringGenerateError { code: String!  message: String!  field: String }
```

  `RecurringDue.next` is null for a schedule never yet generated (due once started). Error codes: `NOT_FOUND` (no recurrence, or no such order), `NOT_DUE`, `ENDED`, `BAD_INPUT` (with `field`: `orderId` or `date`), `FA_REJECTED` (a check or FrontAccounting refused; nothing written), `INTERNAL` (logged, masked).
- Areas: `recurringDueList` — `SA_SALESTRANSVIEW` (3073). `recurringGenerate` — `SA_SALESDELIVERY` (3076) and `SA_SALESINVOICE` (3077), checked before any item; with any item's `email: true` also `SA_SALESTRANSVIEW` (as `invoiceEmail`). A refusal is a whole-field `FORBIDDEN` error, before anything is generated.
- graphql, `bin/fa-report`: before `session.inc` is included, `config_db.php` is included at file scope and `$def_coy` set to the target company. `session.inc` includes it with `include_once($path_to_root . "/config_db.php")` (upstream `includes/session.inc:383`; fork `:33`), which PHP skips for an already-included file whatever the path's spelling, so `current_user`'s constructor (`includes/current_user.inc:42-45`) and `user_company()` (`:426-431`) see the target company, and `session.inc:498-501` installs **that** company's hooks before the login.
- graphql, driver: `docker/fa-graphql db second-company add|remove` — adds company 1 (the same database and prefix as company 0), makes it the default (`$def_coy = 1`) with graphql **inactive** in `company/1/installed_extensions.php`; `remove` restores `config_db.php` and deletes `company/1`. `docker/fa-graphql test-default-company` runs `ReportDefaultCompanyTest` between an `add` and a `remove` (the `remove` in a `trap`), and `docker/fa-graphql ci` runs it after the suites.
- **Deviation from the contract, recorded:** the contract asks for "a test for a non-default company". The real failure needs FrontAccounting's `config_db.php` changed (root-owned, rewritten by the entrypoint), so the automated test runs under the driver's `test-default-company` rather than in the default suite, where it skips unless the second company is present.

- [ ] **Step 1: Check what the plan relies on, in the graphql stack**

```bash
cd /home/cambell/src/sgw/frontaccounting/modules/graphql
grep -n "SGW_SALES_PATH" docker/.env || echo "set SGW_SALES_PATH=../sgw_sales in docker/.env, then docker/fa-graphql up"
docker/fa-graphql test-extension sgw_sales --filter RegistrationTest
grep -n "type name\|getName()\|InvoiceEmailResult" src/Extension/ExtensionLoader.php src/Extension/SchemaAssembler.php
grep -n "include_once(\$path_to_root . \"/config_db.php\")" ../../includes/session.inc
docker/fa-graphql exec php -r 'require "/var/www/html/config_db.php"; var_dump($def_coy, array_keys($db_connections));'
docker/fa-graphql exec sh -c 'ls -l /var/www/html/config_db.php; id'
```

Expected:
- the Task 2 registration tests pass (the extension loads and serves `recurring`);
- **decide from the loader's source whether an extension may reuse a core type** (`InvoiceEmailResult`) as the same instance. webonyx accepts one type under two paths when it is the same object; if Task 1's loader rejects a type **name** it already knows without comparing instances, this task's `email` field would drop the whole extension. In that case stop and report NEEDS_CONTEXT naming the loader line: the fix belongs in Task 1's loader ("same instance is not a clash"), not a copy of the type here;
- `session.inc` includes `config_db.php` with `include_once` (the fix depends on it);
- `$def_coy` is 0 and there is one company; `config_db.php` is root-owned and `docker/fa-graphql exec` runs as root (the driver's `c_exec`) — `db second-company` writes it that way.

- [ ] **Step 2: Write the failing tests (sgw_sales)**

`sgw_sales:tests/GraphQL/RecurringGenerationTestCase.php`:

```php
<?php

namespace SGW_Sales\Tests\GraphQL;

use FA\GraphQL\Auth\Claims;
use FA\GraphQL\Config;
use FA\GraphQL\Fa\Bootstrap;
use FA\GraphQL\Fa\Service\FaIncludes;
use FA\GraphQL\RequestInfo;
use FA\GraphQL\SessionGate;
use FA\GraphQL\Tests\Support\AssertsGlBalanced;
use FA\GraphQL\Tests\Support\FaBillingRows;
use FA\GraphQL\Tests\Support\FaTestRows;
use FA\GraphQL\Tests\Support\MailCatcher;
use FA\GraphQL\Tests\Support\ReportFiles;
use GraphQL\GraphQL;
use GraphQL\Type\Schema;

/**
 * recurringDueList / recurringGenerate through the real schema, signed in as
 * apitest (SalesOrderTestCase), with the billing documents, the orders and any
 * mail or report PDF a test caused removed afterwards.
 */
abstract class RecurringGenerationTestCase extends ExtensionTestCase
{
    use AssertsGlBalanced;

    private ?FaBillingRows $billingRows = null;

    /** @var string[] */
    private array $mailBefore = [];

    /** @var string[] */
    private array $pdfBefore = [];

    private string $prefix = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireExtensionServesRecurrence();
        FaIncludes::billing();
        $this->billingRows = FaBillingRows::mark(FaTestRows::connect());
        $this->mailBefore = MailCatcher::available() ? MailCatcher::files() : [];
        $this->pdfBefore = ReportFiles::files(Bootstrap::defaultRoot());
        $this->prefix = FaTestRows::prefix();
    }

    protected function tearDown(): void
    {
        if (MailCatcher::available()) {
            MailCatcher::delete(MailCatcher::newSince($this->mailBefore));
        }
        ReportFiles::deleteNew(Bootstrap::defaultRoot(), $this->pdfBefore);
        if ($this->billingRows !== null) {
            $this->billingRows->purge();
        }
        parent::tearDown();
        if ($this->prefix !== '') {
            FaTestRows::sweep($this->pdo(), $this->prefix);
        }
    }

    /**
     * @param array<string, mixed> $variables
     * @return array<string, mixed> the GraphQL result (data, errors)
     */
    protected function graphql(string $query, array $variables = []): array
    {
        return GraphQL::executeQuery(
            $this->container->get(Schema::class),
            $query,
            null,
            $this->container,
            $variables
        )->toArray();
    }

    /** A new session as another user of company 0, in this (separate) process. */
    protected function enterAs(string $login): void
    {
        $factory = require '/var/www/html/modules/graphql/container.php';
        $this->container = $factory(
            Config::fromArray(['secret' => '0123456789abcdef0123456789abcdef', 'fa_root' => Bootstrap::defaultRoot()]),
            new RequestInfo(false, 'phpunit 127.0.0.1')
        );
        $gate = $this->container->get(SessionGate::class);
        $gate->boot();
        $gate->enter(new Claims(0, $login, 'recurring-test', new \DateTimeImmutable('+5 minutes')));
    }

    /**
     * A recurring sales order for demo customer 1 / branch 1 of the service item 202,
     * monthly on day $day, starting $start; tracked for cleanup.
     */
    protected function recurringOrder(string $start, int $day, ?string $end = null, int $customerId = 1, int $branchId = 1): int
    {
        $recurring = ['start' => $start, 'repeats' => 'MONTH', 'every' => 1, 'day' => $day];
        if ($end !== null) {
            $recurring['end'] = $end;
        }
        $result = $this->graphql(
            'mutation ($input: [SalesOrderCreateInput!]!) { salesOrderCreate(input: $input) { id } }',
            ['input' => [[
                'customerId' => (string) $customerId,
                'branchId' => (string) $branchId,
                'orderDate' => date('Y-m-d'),
                'paymentTermsId' => '3',
                'deliverTo' => 'Recurring test',
                'deliveryAddress' => '1 Test Street',
                'lines' => [['stockId' => '202', 'quantity' => 1.0, 'unitPrice' => 30.0]],
                'recurring' => $recurring,
            ]]]
        );
        $this->assertArrayNotHasKey('errors', $result, json_encode($result['errors'] ?? null));
        $orderNo = (int) $result['data']['salesOrderCreate'][0]['id'];
        $this->track($orderNo);

        return $orderNo;
    }

    protected function invoicesFor(int $orderNo): int
    {
        $statement = $this->pdo()->prepare('SELECT COUNT(*) FROM 0_debtor_trans WHERE type = 10 AND order_ = ?');
        $statement->execute([$orderNo]);
        return (int) $statement->fetchColumn();
    }

    protected function dtNext(int $orderNo): ?string
    {
        $statement = $this->pdo()->prepare('SELECT dt_next FROM 0_sales_recurring WHERE trans_no = ?');
        $statement->execute([$orderNo]);
        $value = $statement->fetchColumn();
        return $value === false || $value === null ? null : (string) $value;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    protected function generate(array $items): array
    {
        return $this->graphql(
            'mutation ($input: [RecurringGenerateInput!]!) { recurringGenerate(input: $input) {'
            . ' orderId invoiceId deliveryId next'
            . ' email { id sent recipient messages }'
            . ' error { code message field } } }',
            ['input' => $items]
        );
    }
}
```

(Use the module's `pdo()` from `AssertsGlBalanced`/`FaTestCase` as the Billing tests do; if both define it, keep the trait's and drop the `use` conflict with `insteadof`, as `BillingTestCase` does — read it.)

`sgw_sales:tests/GraphQL/Integration/RecurringDueListTest.php`:

```php
<?php

namespace SGW_Sales\Tests\GraphQL\Integration;

use SGW_Sales\Tests\GraphQL\RecurringGenerationTestCase;

/**
 * recurringDueList (Release 4 spec §4.2).
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RecurringDueListTest extends RecurringGenerationTestCase
{
    private const LIST = 'query ($asOf: Date) { recurringDueList(asOf: $asOf) {'
        . ' orderId customerId branchId reference customerRef next repeats every day monthDay end } }';

    /** @return array<int, array<string, mixed>> keyed by order id */
    private function due(?string $asOf = null): array
    {
        $result = $this->graphql(self::LIST, $asOf === null ? [] : ['asOf' => $asOf]);
        $this->assertArrayNotHasKey('errors', $result, json_encode($result['errors'] ?? null));
        $byId = [];
        foreach ($result['data']['recurringDueList'] as $row) {
            $byId[(int) $row['orderId']] = $row;
        }
        return $byId;
    }

    public function testAStartedScheduleNeverGeneratedIsDueToday(): void
    {
        $orderNo = $this->recurringOrder(date('Y-m-01'), 1);

        $due = $this->due();

        $this->assertArrayHasKey($orderNo, $due);
        $this->assertSame(
            [
                'orderId' => (string) $orderNo, 'customerId' => '1', 'branchId' => '1',
                'next' => null, 'repeats' => 'MONTH', 'every' => 1, 'day' => 1, 'monthDay' => null, 'end' => null,
            ],
            array_intersect_key($due[$orderNo], array_flip(
                ['orderId', 'customerId', 'branchId', 'next', 'repeats', 'every', 'day', 'monthDay', 'end']
            ))
        );
    }

    public function testAScheduleStartingLaterIsDueOnlyFromItsStart(): void
    {
        $start = (new \DateTime('first day of next month'))->format('Y-m-d');
        $orderNo = $this->recurringOrder($start, 1);

        $this->assertArrayNotHasKey($orderNo, $this->due());
        $this->assertArrayHasKey($orderNo, $this->due($start));
    }

    public function testAnEndedScheduleIsNotDue(): void
    {
        $orderNo = $this->recurringOrder(date('Y-m-01'), 1, date('Y-m-d'));

        $this->assertArrayNotHasKey($orderNo, $this->due());
    }

    public function testAGeneratedOrderIsNoLongerDue(): void
    {
        $orderNo = $this->recurringOrder(date('Y-m-01'), 1);
        $result = $this->generate([['orderId' => (string) $orderNo, 'date' => date('Y-m-d')]]);
        $this->assertNull($result['data']['recurringGenerate'][0]['error']);

        $this->assertArrayNotHasKey($orderNo, $this->due());
    }

    public function testListingNeedsSalesTransactionView(): void
    {
        // noapi's role lacks SA_GRAPHQL; sgwpanel holds 3073. A user with SA_GRAPHQL and
        // not 3073 is made for the test: reuse the seed's "GraphQL Orders" role minus 3073.
        $this->pdo()->exec("UPDATE 0_security_roles SET areas = REPLACE(areas, '3073;', '') WHERE role = 'GraphQL Orders'");
        try {
            $this->enterAs('apiorders');
            $result = $this->graphql(self::LIST);
            $this->assertSame('FORBIDDEN', $result['errors'][0]['extensions']['code'] ?? null);
        } finally {
            $this->pdo()->exec(
                "UPDATE 0_security_roles SET areas = CONCAT('3073;', areas) WHERE role = 'GraphQL Orders'"
                . " AND FIND_IN_SET('3073', REPLACE(areas, ';', ',')) = 0"
            );
        }
    }
}
```

(`testListingNeedsSalesTransactionView`: check in Step 1 how the seed writes the `GraphQL Orders` role's `areas` (`grep -n "GraphQL Orders" -A6 ../graphql/tests/data/seed.sql`) and make the removal and restoration exact for that string — the restoration must leave the row byte-identical; assert it at the end of the test.)

`sgw_sales:tests/GraphQL/Integration/RecurringGenerateTest.php`:

```php
<?php

namespace SGW_Sales\Tests\GraphQL\Integration;

use FA\GraphQL\Tests\Support\MailCatcher;
use SGW_Sales\Tests\GraphQL\RecurringGenerationTestCase;

/**
 * recurringGenerate (Release 4 spec §4.2): items independent, retry-safe, emailed
 * after each item commits.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RecurringGenerateTest extends RecurringGenerationTestCase
{
    public function testADueOrderIsDeliveredInvoicedAndMovedOn(): void
    {
        $orderNo = $this->recurringOrder(date('Y-m-01'), 1);

        $result = $this->generate([['orderId' => (string) $orderNo, 'date' => date('Y-m-d')]]);

        $this->assertArrayNotHasKey('errors', $result, json_encode($result['errors'] ?? null));
        $item = $result['data']['recurringGenerate'][0];
        $this->assertNull($item['error']);
        $this->assertNull($item['email']);
        $this->assertSame((string) $orderNo, $item['orderId']);
        $this->assertNotNull($item['invoiceId']);
        $this->assertNotNull($item['deliveryId']);
        $next = (new \DateTime('first day of next month'))->format('Y-m-d');
        $this->assertSame($next, $item['next']);
        $this->assertSame($next, $this->dtNext($orderNo));
        $this->assertSame(1, $this->invoicesFor($orderNo));
        $this->assertGlBalanced(13, (int) $item['deliveryId']);
        $this->assertGlBalanced(10, (int) $item['invoiceId']);

        $read = $this->graphql(
            'query ($s: String) { salesOrderList(query: {selector: $s}) { id recurring { next } } }',
            ['s' => json_encode(['id' => (string) $orderNo])]
        );
        $this->assertSame($next, $read['data']['salesOrderList'][0]['recurring']['next']);
    }

    public function testARetryBillsNothingTwice(): void
    {
        $orderNo = $this->recurringOrder(date('Y-m-01'), 1);
        $first = $this->generate([['orderId' => (string) $orderNo, 'date' => date('Y-m-d')]]);
        $this->assertNull($first['data']['recurringGenerate'][0]['error']);

        $again = $this->generate([['orderId' => (string) $orderNo, 'date' => date('Y-m-d')]]);

        $this->assertSame('NOT_DUE', $again['data']['recurringGenerate'][0]['error']['code']);
        $this->assertNull($again['data']['recurringGenerate'][0]['invoiceId']);
        $this->assertSame(1, $this->invoicesFor($orderNo));
    }

    public function testItemsAreIndependent(): void
    {
        $due = $this->recurringOrder(date('Y-m-01'), 1);
        $notYet = $this->recurringOrder((new \DateTime('first day of next month'))->format('Y-m-d'), 1);
        $ended = $this->recurringOrder(date('Y-m-01'), 1, date('Y-m-d'));

        $result = $this->generate([
            ['orderId' => (string) $due, 'date' => date('Y-m-d')],
            ['orderId' => '999999', 'date' => date('Y-m-d')],
            ['orderId' => (string) $notYet, 'date' => date('Y-m-d')],
            ['orderId' => (string) $ended, 'date' => date('Y-m-d')],
            ['orderId' => 'x1', 'date' => date('Y-m-d')],
        ]);

        $this->assertArrayNotHasKey('errors', $result, json_encode($result['errors'] ?? null));
        $items = $result['data']['recurringGenerate'];
        $this->assertCount(5, $items);
        $this->assertNull($items[0]['error']);
        $this->assertSame('NOT_FOUND', $items[1]['error']['code']);
        $this->assertSame('NOT_DUE', $items[2]['error']['code']);
        $this->assertSame('ENDED', $items[3]['error']['code']);
        $this->assertSame(['BAD_INPUT', 'orderId'], [$items[4]['error']['code'], $items[4]['error']['field']]);
        $this->assertSame(1, $this->invoicesFor($due), 'the first item committed whatever came after');
        $this->assertSame(0, $this->invoicesFor($notYet));
        $this->assertSame(0, $this->invoicesFor($ended));
    }

    public function testADateOutsideTheFiscalYearWritesNothing(): void
    {
        $orderNo = $this->recurringOrder('1999-12-01', 1);

        $result = $this->generate([['orderId' => (string) $orderNo, 'date' => '2000-01-01']]);

        $error = $result['data']['recurringGenerate'][0]['error'];
        $this->assertSame(['BAD_INPUT', 'date'], [$error['code'], $error['field']]);
        $this->assertSame(0, $this->invoicesFor($orderNo));
        $this->assertNull($this->dtNext($orderNo));
    }

    public function testEmailIsSentAfterTheItemCommits(): void
    {
        if (!MailCatcher::available()) {
            $this->markTestSkipped('The stack mail catcher is not installed (docker/fa-graphql up --build).');
        }
        $customer = $this->graphql(
            'mutation ($i: [CustomerCreateInput!]!) { customerCreate(input: $i) { id branches { id } } }',
            ['i' => [[
                'name' => $this->prefixed('Recurring Mail'),
                'ref' => $this->prefixed('RM'),
                'currencyId' => 'USD',
                'salesTypeId' => '1', 'paymentTermsId' => '3', 'creditStatusId' => '1',
                'branch' => ['salesmanId' => '1', 'salesAreaId' => '1', 'taxGroupId' => '1', 'locationId' => 'DEF', 'shipperId' => '1'],
                'contact' => ['email' => 'gqlt-recurring@example.com'],
            ]]]
        );
        $this->assertArrayNotHasKey('errors', $customer, json_encode($customer['errors'] ?? null));
        $customerId = (int) $customer['data']['customerCreate'][0]['id'];
        $branchId = (int) $customer['data']['customerCreate'][0]['branches'][0]['id'];
        $orderNo = $this->recurringOrder(date('Y-m-01'), 1, null, $customerId, $branchId);

        $result = $this->generate([['orderId' => (string) $orderNo, 'date' => date('Y-m-d'), 'email' => true]]);

        $item = $result['data']['recurringGenerate'][0];
        $this->assertNull($item['error']);
        $this->assertTrue($item['email']['sent'], implode("\n", $item['email']['messages']));
        $this->assertSame('gqlt-recurring@example.com', $item['email']['recipient']);
        $this->assertSame($item['invoiceId'], $item['email']['id']);
        $this->assertCount(1, MailCatcher::newSince([]) === [] ? [] : array_filter(
            MailCatcher::files(),
            static function (string $f): bool {
                return strpos((string) file_get_contents($f), 'gqlt-recurring@example.com') !== false;
            }
        ));
    }

    public function testGeneratingNeedsDeliveryAndInvoiceAreas(): void
    {
        $orderNo = $this->recurringOrder(date('Y-m-01'), 1);
        $this->enterAs('sgwpanel'); // 3073, 3074, 3075: no 3076 or 3077

        $result = $this->generate([['orderId' => (string) $orderNo, 'date' => date('Y-m-d')]]);

        $this->assertSame('FORBIDDEN', $result['errors'][0]['extensions']['code'] ?? null);
        $this->assertSame(0, $this->invoicesFor($orderNo));
    }

    private function prefixed(string $name): string
    {
        return \FA\GraphQL\Tests\Support\FaTestRows::prefix() . ' ' . $name;
    }
}
```

(The customer inputs follow the demo data the module's Task 7 of Release 2 used; read `graphql:tests/Http/PanelFlowTest.php` for the exact `customerCreate` input and use its values. `FaTestRows::prefix()` must be the same prefix `setUp()` stored — keep one: store it in a property and use it both for names and the sweep.)

- [ ] **Step 3: Run to see them fail (sgw_sales)**

```bash
cd /home/cambell/src/sgw/frontaccounting/modules/graphql
docker/fa-graphql test-extension sgw_sales --filter 'RecurringDueListTest|RecurringGenerateTest'
```

Expected: FAIL — `Cannot query field "recurringDueList" on type "Query"` / `"recurringGenerate" on type "Mutation"`.

- [ ] **Step 4: Implement (sgw_sales)**

`sgw_sales:includes/GraphQL/Type/RecurringDueType.php`:

```php
<?php

namespace SGW_Sales\GraphQL\Type;

use Anorm\GraphQL\Type\DateType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/** A recurring sales order due on the date asked (recurringDueList). */
final class RecurringDueType extends ObjectType
{
    public function __construct(RecurrenceRepeatsType $repeats)
    {
        parent::__construct([
            'name' => 'RecurringDue',
            'description' => 'A recurring sales order that is due: its next date reached, or never generated and started.',
            'fields' => [
                'orderId' => ['type' => Type::nonNull(Type::id())],
                'customerId' => ['type' => Type::nonNull(Type::id())],
                'branchId' => ['type' => Type::nonNull(Type::id())],
                'reference' => ['type' => Type::string()],
                'customerRef' => ['type' => Type::string()],
                'next' => [
                    'type' => DateType::instance(),
                    'description' => 'The date it fell due; none when it has never been generated.',
                ],
                'repeats' => ['type' => Type::nonNull($repeats)],
                'every' => ['type' => Type::nonNull(Type::int())],
                'day' => ['type' => Type::int(), 'description' => 'Monthly: the day of the month.'],
                'monthDay' => ['type' => Type::string(), 'description' => 'Yearly: MM-DD.'],
                'end' => ['type' => DateType::instance()],
            ],
        ]);
    }
}
```

`sgw_sales:includes/GraphQL/Type/RecurringGenerateInputType.php`:

```php
<?php

namespace SGW_Sales\GraphQL\Type;

use Anorm\GraphQL\Type\DateType;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\Type;

final class RecurringGenerateInputType extends InputObjectType
{
    public function __construct()
    {
        parent::__construct([
            'name' => 'RecurringGenerateInput',
            'fields' => [
                'orderId' => ['type' => Type::nonNull(Type::id())],
                'date' => [
                    'type' => Type::nonNull(DateType::instance()),
                    'description' => 'The invoice and delivery date; the order must be due on it.',
                ],
                'email' => [
                    'type' => Type::boolean(),
                    'defaultValue' => false,
                    'description' => "Email the invoice through FrontAccounting's invoice report once it is written.",
                ],
            ],
        ]);
    }
}
```

`sgw_sales:includes/GraphQL/Type/GenerateErrorType.php`:

```php
<?php

namespace SGW_Sales\GraphQL\Type;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

final class GenerateErrorType extends ObjectType
{
    public function __construct()
    {
        parent::__construct([
            'name' => 'RecurringGenerateError',
            'description' => 'Why one item was not generated: NOT_FOUND, NOT_DUE, ENDED, BAD_INPUT, FA_REJECTED or INTERNAL.',
            'fields' => [
                'code' => ['type' => Type::nonNull(Type::string())],
                'message' => ['type' => Type::nonNull(Type::string())],
                'field' => ['type' => Type::string(), 'description' => 'The input it is about, for BAD_INPUT.'],
            ],
        ]);
    }
}
```

`sgw_sales:includes/GraphQL/Type/RecurringGenerateResultType.php`:

```php
<?php

namespace SGW_Sales\GraphQL\Type;

use Anorm\GraphQL\Type\DateType;
use FA\GraphQL\Type\Invoice\InvoiceEmailResultType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

final class RecurringGenerateResultType extends ObjectType
{
    public function __construct(InvoiceEmailResultType $email, GenerateErrorType $error)
    {
        parent::__construct([
            'name' => 'RecurringGenerateResult',
            'description' => 'One item of recurringGenerate. Items are independent: each is written, or not, on its own.',
            'fields' => [
                'orderId' => ['type' => Type::nonNull(Type::id())],
                'invoiceId' => ['type' => Type::id()],
                'deliveryId' => ['type' => Type::id()],
                'next' => ['type' => DateType::instance(), 'description' => "The recurrence's new next date."],
                'email' => [
                    'type' => $email,
                    'description' => 'The email outcome when asked for and the invoice was written.',
                ],
                'error' => ['type' => $error, 'description' => 'Why nothing was written for this item.'],
            ],
        ]);
    }
}
```

`sgw_sales:includes/GraphQL/RecurringGeneration.php`:

```php
<?php

namespace SGW_Sales\GraphQL;

use FA\GraphQL\Auth\Guard;
use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\FaRejected;
use FA\GraphQL\Error\NotFound;
use FA\GraphQL\Extension\ExtensionContext;
use FA\GraphQL\Fa\DateConversion;
use FA\GraphQL\Fa\DocumentLock;
use FA\GraphQL\Fa\Service\IntKey;
use FA\GraphQL\Fa\Service\ServiceCall;
use SGW_Sales\db\GenerateRecurringModel;
use SGW_Sales\service\GeneratedInvoice;
use SGW_Sales\service\GenerationRefused;
use SGW_Sales\service\RecurrenceEnded;
use SGW_Sales\service\RecurrenceNotDue;
use SGW_Sales\service\RecurrenceNotFound;
use SGW_Sales\service\RecurringInvoiceService;

/**
 * recurringDueList and recurringGenerate (Release 4 spec §4.2), over the one
 * generation service the page also uses.
 */
final class RecurringGeneration
{
    private ExtensionContext $context;
    private RecurringInvoiceService $service;

    public function __construct(ExtensionContext $context, ?RecurringInvoiceService $service = null)
    {
        $this->context = $context;
        $this->service = $service ?? new RecurringInvoiceService();
    }

    /**
     * @param array<string, mixed> $args
     * @return array<int, array<string, mixed>>
     */
    public function due(array $args): array
    {
        Guard::require('SA_SALESTRANSVIEW');
        $asOf = $args['asOf'] ?? new \DateTimeImmutable('today');
        // The request's connection: the token's company, read outside any write.
        $pdo = $this->context->container()->get(\PDO::class);

        return array_map([self::class, 'dueRow'], $this->service->due($asOf, false, $pdo));
    }

    /**
     * @return array<string, mixed>
     */
    public static function dueRow(GenerateRecurringModel $model): array
    {
        $monthly = $model->repeats === 'month';

        return [
            'orderId' => (int) $model->orderNo,
            'customerId' => (int) $model->debtorNo,
            'branchId' => (int) $model->branchCode,
            'reference' => $model->reference,
            'customerRef' => $model->customerRef === '' ? null : $model->customerRef,
            'next' => DateConversion::fromSql($model->dtNext),
            'repeats' => $model->repeats,
            'every' => (int) $model->every,
            'day' => $monthly ? (int) $model->occur : null,
            'monthDay' => $monthly ? null : (string) $model->occur,
            'end' => DateConversion::fromSql($model->dtEnd),
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<int, array<string, mixed>>
     */
    public function generate(array $args): array
    {
        Guard::require('SA_SALESDELIVERY');
        Guard::require('SA_SALESINVOICE');
        $items = array_values($args['input']);
        foreach ($items as $item) {
            if (!empty($item['email'])) {
                Guard::require('SA_SALESTRANSVIEW'); // as invoiceEmail
                break;
            }
        }

        $results = [];
        foreach ($items as $item) {
            $results[] = $this->one($item);
        }
        return $results;
    }

    /**
     * One item, on its own: its own transaction under the document lock, then its
     * email after the commit.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function one(array $item): array
    {
        $result = [
            'orderId' => (string) $item['orderId'],
            'invoiceId' => null,
            'deliveryId' => null,
            'next' => null,
            'email' => null,
            'error' => null,
        ];
        try {
            $orderNo = IntKey::parse($item['orderId'], 'orderId');
            $date = $item['date'];
            /** @var GeneratedInvoice $generated */
            $generated = DocumentLock::run(function () use ($orderNo, $date) {
                return ServiceCall::run(function () use ($orderNo, $date) {
                    return $this->service->generate($orderNo, $date);
                });
            });
        } catch (\Throwable $e) {
            $result['error'] = self::error($e);
            return $result;
        }

        $result['orderId'] = (string) $generated->orderNo;
        $result['invoiceId'] = (string) $generated->invoiceNo;
        $result['deliveryId'] = (string) $generated->deliveryNo;
        $result['next'] = DateConversion::fromSql($generated->dtNext);
        if (!empty($item['email'])) {
            $result['email'] = $this->email($generated->invoiceNo);
        }
        return $result;
    }

    /**
     * The invoice is written whatever becomes of the email: a failure is reported,
     * never turned into an item error.
     *
     * @return array<string, mixed>
     */
    private function email(int $invoiceNo): array
    {
        try {
            return $this->context->mailer()->send([$invoiceNo])[0];
        } catch (\Throwable $e) {
            error_log('sgw_sales recurringGenerate: invoice ' . $invoiceNo . ' not emailed: '
                . get_class($e) . ': ' . $e->getMessage());
            return [
                'id' => $invoiceNo,
                'sent' => false,
                'recipient' => null,
                'messages' => [$e instanceof FaRejected || $e instanceof NotFound
                    ? $e->getMessage()
                    : 'The invoice was written but could not be emailed (see the server log).'],
            ];
        }
    }

    /**
     * @return array{code: string, message: string, field: ?string}
     */
    public static function error(\Throwable $e): array
    {
        if ($e instanceof RecurrenceNotFound || $e instanceof NotFound) {
            return ['code' => 'NOT_FOUND', 'message' => $e->getMessage(), 'field' => 'orderId'];
        }
        if ($e instanceof RecurrenceNotDue) {
            return ['code' => 'NOT_DUE', 'message' => $e->getMessage(), 'field' => null];
        }
        if ($e instanceof RecurrenceEnded) {
            return ['code' => 'ENDED', 'message' => $e->getMessage(), 'field' => null];
        }
        if ($e instanceof GenerationRefused) {
            return $e->field() !== null
                ? ['code' => 'BAD_INPUT', 'message' => $e->getMessage(), 'field' => $e->field()]
                : ['code' => 'FA_REJECTED', 'message' => $e->getMessage(), 'field' => null];
        }
        if ($e instanceof BadInput) {
            return ['code' => 'BAD_INPUT', 'message' => $e->getMessage(), 'field' => $e->field()];
        }
        if ($e instanceof FaRejected) {
            return ['code' => 'FA_REJECTED', 'message' => $e->getMessage(), 'field' => null];
        }
        error_log('sgw_sales recurringGenerate: ' . get_class($e) . ': ' . $e->getMessage()
            . ' @ ' . $e->getFile() . ':' . $e->getLine());
        return ['code' => 'INTERNAL', 'message' => 'Internal server error', 'field' => null];
    }
}
```

`sgw_sales:includes/GraphQL/SgwSalesExtension.php` — add these two methods (keep Task 2's `name()`, `typeFields()`, `inputFields()`, `participants()`; add the `use` lines):

```php
use Anorm\GraphQL\Type\DateType;
use GraphQL\Type\Definition\Type;
use SGW_Sales\GraphQL\Type\RecurringDueType;
use SGW_Sales\GraphQL\Type\RecurringGenerateInputType;
use SGW_Sales\GraphQL\Type\RecurringGenerateResultType;
```

```php
    public function queryFields(ExtensionContext $c): array
    {
        $container = $c->container();

        return [
            'recurringDueList' => [
                'type' => Type::nonNull(Type::listOf(Type::nonNull($container->get(RecurringDueType::class)))),
                'description' => 'Recurring sales orders due on asOf (default today): next date reached, '
                    . 'or never generated and started. sgw_sales.',
                'args' => ['asOf' => ['type' => DateType::instance()]],
                'resolve' => static function ($root, array $args) use ($c): array {
                    return (new RecurringGeneration($c))->due($args);
                },
            ],
        ];
    }

    public function mutationFields(ExtensionContext $c): array
    {
        $container = $c->container();

        return [
            'recurringGenerate' => [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(
                    $container->get(RecurringGenerateResultType::class)
                ))),
                'description' => 'Deliver and invoice recurring sales orders due on each date, and move them on. '
                    . 'Items are independent: each is written, or reports its error, on its own; a retry of a '
                    . 'written item reports NOT_DUE. With email, the invoice is emailed after it is written. sgw_sales.',
                'args' => [
                    'input' => ['type' => Type::nonNull(Type::listOf(Type::nonNull(
                        $container->get(RecurringGenerateInputType::class)
                    )))],
                ],
                'resolve' => static function ($root, array $args) use ($c): array {
                    return (new RecurringGeneration($c))->generate($args);
                },
            ],
        ];
    }
```

- [ ] **Step 5: Run to see them pass (sgw_sales)**

```bash
docker/fa-graphql test-extension sgw_sales --filter 'RecurringDueListTest|RecurringGenerateTest'
```

Expected: PASS. If `testAStartedScheduleNeverGeneratedIsDueToday` or the generate tests fail inside `RecurringInvoiceService::generate()` with a missing include, apply Task 4's note on `includeFa()` (`$path_to_root` in the API is the module `Bootstrap`'s). If the loader drops the extension after `email` was added (the `InvoiceEmailResult` question from Step 1), stop: NEEDS_CONTEXT.

- [ ] **Step 6: Write the failing test (graphql)**

`graphql:tests/Integration/Billing/ReportDefaultCompanyTest.php`:

```php
<?php

namespace FA\GraphQL\Tests\Integration\Billing;

use FA\GraphQL\Fa\Bootstrap;
use FA\GraphQL\Fa\Service\InvoiceMailer;
use FA\GraphQL\Tests\Support\FaTestRows;
use FA\GraphQL\Tests\Support\MailCatcher;
use FA\GraphQL\Tests\Support\ReportFiles;

/**
 * Release 4 spec §4.3: the report child logs in for its target company even when
 * FrontAccounting's default company does not have this module active. Runs only
 * under `docker/fa-graphql test-default-company`, which makes company 1 the default
 * with graphql inactive there (`db second-company add`) and removes it afterwards;
 * skipped otherwise.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ReportDefaultCompanyTest extends InvoiceTestCase
{
    /** @var string[] */
    private array $mailBefore = [];

    /** @var string[] */
    private array $pdfBefore = [];

    private string $prefix = '';

    protected function setUp(): void
    {
        parent::setUp();
        if ((int) ($GLOBALS['def_coy'] ?? 0) !== 1 || !isset($GLOBALS['db_connections'][1])) {
            $this->markTestSkipped('Needs company 1 as the default: run docker/fa-graphql test-default-company.');
        }
        if (!MailCatcher::available()) {
            $this->markTestSkipped('The stack mail catcher is not installed (docker/fa-graphql up --build).');
        }
        $this->mailBefore = MailCatcher::files();
        $this->pdfBefore = ReportFiles::files(Bootstrap::defaultRoot());
        $this->prefix = FaTestRows::prefix();
    }

    protected function tearDown(): void
    {
        MailCatcher::delete(MailCatcher::newSince($this->mailBefore));
        ReportFiles::deleteNew(Bootstrap::defaultRoot(), $this->pdfBefore);
        parent::tearDown();
        if ($this->prefix !== '') {
            FaTestRows::sweep($this->pdo(), $this->prefix);
        }
    }

    public function testAnInvoiceOfCompany0IsEmailedWhileCompany1IsTheDefault(): void
    {
        $invoiceId = $this->invoiceForANewCustomer('gqlt-defcoy@example.com');

        $results = $this->container->get(InvoiceMailer::class)->send([$invoiceId]);

        $this->assertTrue($results[0]['sent'], implode("\n", $results[0]['messages']));
        $this->assertNotSame([InvoiceMailer::DID_NOT_RUN], $results[0]['messages']);
        $this->assertCount(1, MailCatcher::newSince($this->mailBefore));
    }
}
```

`graphql:docker/fa-graphql` — add after `db_fixtures()`:

```bash
# Release 4 spec §4.3: a second company, made FrontAccounting's default, with this
# module inactive there — the situation in which the report child used to log in
# with the wrong company's hooks. Same database and prefix as company 0; nothing is
# copied. The entrypoint rewrites config_db.php on every start, so a restart undoes
# `add` too.
db_second_company() {
    local cfg="$FA_DIR/config_db.php"
    case "${1:-}" in
        add)
            c_exec sh -c "grep -q 'second-company-test' '$cfg' && exit 0
                cp '$cfg' '$cfg.before-second-company'
                sed -i 's/^\\\$def_coy = [0-9]*;/\$def_coy = 1;/' '$cfg'
                printf '%s\n' '/* second-company-test: docker/fa-graphql db second-company add */' \
                    '\$db_connections[1] = array_merge(\$db_connections[0], array(\"name\" => \"Second (test)\"));' >> '$cfg'
                rm -rf '$FA_DIR/company/1' && cp -a '$FA_DIR/company/0' '$FA_DIR/company/1'"
            c_exec php -r '
                $f = "'"$FA_DIR"'/company/1/installed_extensions.php";
                include $f;
                foreach ($installed_extensions as $k => $e) {
                    if ($e["package"] === "graphql") { $installed_extensions[$k]["active"] = false; }
                }
                file_put_contents($f, "<?php\n\$next_extension_id = " . (int) $next_extension_id . ";\n\$installed_extensions = "
                    . var_export($installed_extensions, true) . ";\n");'
            info "company 1 added as the default, graphql inactive there"
            ;;
        remove)
            c_exec sh -c "[ -f '$cfg.before-second-company' ] && mv '$cfg.before-second-company' '$cfg'; rm -rf '$FA_DIR/company/1'"
            info "company 1 removed"
            ;;
        *) die "usage: fa-graphql db second-company <add|remove>" ;;
    esac
}

cmd_test_default_company() {
    ensure_running
    db_second_company add
    trap 'db_second_company remove' EXIT
    c_exec_user php vendor/bin/phpunit --fail-on-skipped --filter ReportDefaultCompanyTest
    trap - EXIT
    db_second_company remove
}
```

and in `cmd_db`'s `case`: `second-company) db_second_company "$@" ;;` (and the usage string); a dispatch entry `test-default-company) cmd_test_default_company ;;`; `cmd_help` lines:

```
  test-default-company
                     The report child with company 1 as FrontAccounting's default
                     and this module inactive there (Release 4 §4.3)
```

and in `cmd_ci()` after `cmd_test "$@"` (and after Task 3's extension suites): `cmd_test_default_company`.

(Use the driver's real helper names — `ensure_running`, `info`, `die`, `c_exec`, `c_exec_user` — and its real phpunit invocation for one filter; read `cmd_test` and Task 3's `cmd_test_extension`. Quoting inside `c_exec sh -c` is the risky part: verify with `docker/fa-graphql db second-company add && docker/fa-graphql exec cat /var/www/html/config_db.php && docker/fa-graphql exec cat /var/www/html/company/1/installed_extensions.php && docker/fa-graphql db second-company remove && docker/fa-graphql exec cat /var/www/html/config_db.php`.)

- [ ] **Step 7: Run to see it fail (graphql)**

```bash
docker/fa-graphql test-default-company
```

Expected: FAIL — `sent` is false with `The report process did not run: FrontAccounting refused the login…` (the child installed company 1's hooks, where graphql is inactive, so `hooks_graphql::authenticate` never ran and the empty password failed). Afterwards `docker/fa-graphql exec grep -c second-company-test /var/www/html/config_db.php` prints 0 (the trap removed it).

- [ ] **Step 8: Implement (graphql)**

`graphql:bin/fa-report` — after `$faReportRoot = realpath(...) ?: $faReportRoot;` insert:

```php
// Release 4 spec §4.3: FrontAccounting installs the hooks of user_company() before
// the login (includes/session.inc:498-501), and a new session's user_company() is
// $def_coy (includes/current_user.inc:42-45). Include config_db.php here, at file
// scope, and make the target company the default: session.inc's
// include_once($path_to_root . "/config_db.php") then skips it, so the child
// installs its target company's hooks — hooks_graphql among them wherever this
// module is active for that company — not the default company's.
include_once $faReportRoot . '/config_db.php';
$def_coy = $faReportCompany;
```

`graphql:docs/superpowers/specs/2026-09-26-release-3-billing-design.md` — in §6, after the multi-company caveat, add: `*(revised: Release 4)* Fixed: `bin/fa-report` makes the target company FrontAccounting's default before `session.inc` runs (Release 4 spec §4.3).`

- [ ] **Step 9: Run to see it pass (graphql), and everything**

```bash
docker/fa-graphql test-default-company
docker/fa-graphql test --filter InvoiceEmailTest
docker/fa-graphql test
docker/fa-graphql test-extension sgw_sales --fail-on-skipped
docker/fa-graphql lint && docker/fa-graphql analyze
```

Expected: PASS; `InvoiceEmailTest` (company 0 is the default again) unchanged; the full suite and the extension's suites pass with no skips; `config_db.php` back to its original (no `second-company-test`).

- [ ] **Step 10: Gates and commit (both repos)**

sgw_sales (`feature/graphql-extension`):

```bash
cd /home/cambell/src/sgw/frontaccounting/modules/sgw_sales
git add includes/GraphQL tests/GraphQL
git commit -m "GraphQL: recurringDueList and recurringGenerate

The extension lists the recurring sales orders due on a date and generates
them through RecurringInvoiceService: each item in its own transaction under
the module's document lock, a failure reported on its item while the rest
continue, a retry of a written item reported NOT_DUE, and the invoice emailed
through the module's report child after the item commits.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013U8a6JAbsHc4c1KcSLiaYK"
```

graphql (`feature/release-4`):

```bash
cd /home/cambell/src/sgw/frontaccounting/modules/graphql
git add bin/fa-report docker/fa-graphql tests/Integration/Billing/ReportDefaultCompanyTest.php \
    docs/superpowers/specs/2026-09-26-release-3-billing-design.md
git commit -m "Report child: log in with the target company's hooks

bin/fa-report makes the target company FrontAccounting's default before
session.inc runs, so the child installs that company's hooks before the login
and signs in wherever this module is active for it, not only where it is active
in the default company. docker/fa-graphql test-default-company proves it with
company 1 as the default and this module inactive there; ci runs it.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013U8a6JAbsHc4c1KcSLiaYK"
```

---

### Checkpoint C: generation (Tasks 4–5)

The generation service, the extension's due list and generate mutation, and the report child's company are reviewed together, once, across both repos. There are no per-task reviews.

- [ ] **Step 1: Everything green, on every stack it runs on**

```bash
# sgw_sales' own stack: the page, the cron, the hardened service
cd /home/cambell/src/sgw/frontaccounting/modules/sgw_sales
docker/fa-sgw-sales up
docker/fa-sgw-sales test                     # unit, db, http
docker/fa-sgw-sales test --testsuite http --filter ServiceWithoutAPageTest

# the module's stack (upstream FrontAccounting master): core, extension, default company
cd /home/cambell/src/sgw/frontaccounting/modules/graphql
docker/fa-graphql ci                         # lint, analyze, test, test-extension sgw_sales, test-default-company
docker/fa-graphql exec grep -c second-company-test /var/www/html/config_db.php   # 0: test-default-company cleaned up

# the fork (cambell-prince/frontaccounting master-cp), PHP 7.4
FA_REPO=https://github.com/cambell-prince/frontaccounting.git FA_REF=master-cp PHP_VERSION=7.4 docker/fa-graphql ci
```

Expected: all PASS, no skips in `test-extension sgw_sales` (`--fail-on-skipped`) or `test-default-company`. Use the driver's real variable names for the fork and PHP build (read `docker/.env.example` and `.github/workflows/ci.yml`'s matrix). Record the counts (tests, assertions) for the report.

- [ ] **Step 2: Nothing left behind**

After the runs, on the module stack:

```bash
docker/fa-graphql db shell -e "SELECT COUNT(*) FROM 0_debtor_trans WHERE type IN (10,13);
  SELECT COUNT(*) FROM 0_sales_orders; SELECT COUNT(*) FROM 0_sales_recurring;
  SELECT COUNT(*) FROM 0_gl_trans; SELECT COUNT(*) FROM 0_debtors_master WHERE name LIKE 'gqlt%'"
```

Expected: the same counts as a fresh `docker/fa-graphql db reset` (take them first). Any difference is a cleanup gap in the new tests: Important.

- [ ] **Step 3: Independent review**

Dispatch one reviewer (the requesting-code-review skill, medium) with:
- the diffs: `graphql` `git diff <checkpoint-B-sha>..HEAD` on `feature/release-4`; `sgw_sales` `git diff <checkpoint-B-sha>..HEAD` on `feature/graphql-extension` (record both SHAs at Checkpoint B);
- the spec, §4.1–§4.3 and §5 (testing), and this plan's Tasks 4–5;
- the questions to answer, each with evidence (file:line, a command run):
  1. **Retry safety.** Can any path write a second delivery or invoice for one period — a retry after a timeout, two concurrent calls, the page and the API at once? `dt_next` is moved in the same FrontAccounting transaction as the documents, and the recurrence row is locked `FOR UPDATE` first; the API also holds the document lock. Is the lock taken on FrontAccounting's connection (not Anorm's PDO)?
  2. **Atomicity.** A failure after the delivery (invoice write, `dt_next` update) leaves no delivery, no GL, no reference consumed, `dt_next` unchanged. Is `cancel_transaction` reached on every throw, including FrontAccounting's `display_error` → `FaRejected` path under `ServiceCall`, and the nesting with `DocumentLock`'s transaction?
  3. **Dates.** Delivery, invoice, due date and `dt_next` come from the date asked, not `today`/`CURDATE()`; `due()` uses `asOf` throughout; a never-generated schedule is due only from its start; `trans_type = 30` restricts the WHERE (not an ON clause).
  4. **Ported Release 3 checks.** Fiscal year (refused, `field: date`, nothing written), exchange rate, customer on hold, negative stock — each as `DeliveryService`/`InvoiceService`/`BillingChecks` do it; say which have tests.
  5. **Items independent.** One item's failure never rolls back or blocks another; errors carry the right codes; `INTERNAL` never leaks a message; email runs after the item's commit and an email failure never becomes an item error.
  6. **Authorisation.** `recurringDueList` 3073; `recurringGenerate` 3076 and 3077 (and 3073 with email) before any item runs.
  7. **The page and the cron.** Behaviour unchanged for their users: the page still emails through `rep107`, "Show All" still generates early, the cron's output unchanged.
  8. **Report child.** `bin/fa-report`'s `include_once config_db.php` + `$def_coy` before `session.inc`, on both upstream and the fork; any other code in the child that reads `$def_coy` after login and now sees the target company (e.g. the login throttle file, `$_SESSION` keys) — harmless or not.
  9. **Driver.** `db second-company add|remove` idempotent, root-only writes, restored on failure (the `trap`); a stack restart also undoes it.
  10. **Contract.** Task 5 reuses the core `InvoiceEmailResult` instance — confirm the loader treats the same instance as no clash and a different type of the same name as a clash (drops the extension).

  Severity: Critical (wrong money, double billing, data loss), Important (a spec rule not met, a cleanup gap, an untested §4 behaviour the spec lists as tested), Minor (everything else; recorded in ROADMAP's deferred hardening at Task 6).

- [ ] **Step 4: Fix round**

One implementer fixes every Critical and Important finding (and any Minor that is a one-line change), test first, in the repo it belongs to, one commit per repo:

```
Checkpoint C fixes: <one line>

<finding → fix, one line each>

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013U8a6JAbsHc4c1KcSLiaYK
```

Then re-run Step 1 and Step 2 in full and dispatch a **scoped** re-review of the fix commits only, against the findings list. Repeat only while the re-review finds a Critical or Important.

- [ ] **Step 5: Record and continue**

Note in the parent's progress log: both HEAD SHAs, the test counts per stack, the findings and their fixes, and the Minors carried to Task 6's ROADMAP update. Continue with Task 6. Nothing is pushed until Checkpoint D.

---

### Task 6: The recurring-generation flow over HTTP, per-company activation, and the docs

**Files:**
- Create: `graphql:tests/Http/RecurringGenerationFlowTest.php`
- Create: `graphql:tests/Integration/Extension/ExtensionActivationTest.php`
- Modify: `graphql:README.md` (status line; "What it covers" rows; new "Extensions" and "Recurring invoice generation" sections; layout row for `src/Extension`)
- Modify: `graphql:ROADMAP-2026-09.md` (Release 4 delivered; "Next" becomes the rest of AR; the multi-company report-login item removed from deferred work)
- Modify: `sgw_sales:README.md` (new "GraphQL extension" section; the Requirements bullet about `update_1.4.sql`; "Calling it from other code" for the hardened service)

**Interfaces:**
- Consumes:
  - Task 1: `FA\GraphQL\Extension\Extensions` (lazy, per request; loads through `hook_invoke_all(ExtensionRegistry::HOOK, $registry)` over `$GLOBALS['Hooks']`), `ExtensionRegistry::HOOK = 'graphql_extensions'`, `ExtensionRegistry::CONTRACT_VERSION = '1.0'`, `AbstractExtension`, `SalesOrderParticipant`, `ExtensionContext`, the loader rules (spec §2.5).
  - Task 2: `recurring: Recurrence` on `SalesOrderType`, `recurring: RecurrenceInput` on `SalesOrderCreateInput`/`SalesOrderUpdateInput`, `RecurrenceRepeats` = `MONTH | YEAR`, served by `sgw_sales`' `SgwSalesExtension` (name `sgw_sales`).
  - Task 3: `docker/fa-graphql test-extension sgw_sales`.
  - Task 5: `recurringDueList(asOf: Date!): [RecurringDue!]!` (`orderId customerId branchId reference customerRef next repeats every day monthDay end`), `recurringGenerate(input: [RecurringGenerateInput!]!): [RecurringGenerateResult!]!` with input `{orderId: ID!, date: Date!, email: Boolean = false}` and result `{orderId invoiceId deliveryId next email { id sent recipient messages } error { code message }}`; areas: list `SA_SALESTRANSVIEW` (3073), generate `SA_SALESDELIVERY` (3076) and `SA_SALESINVOICE` (3077).
  - Release 3: `invoiceList`, `customerPaymentCreate`, `CustomerType.balance { balance }`, `tests/Support/{FaOrderRows,FaBillingRows,FaTestRows,MailCatcher}`, `tests/Http/GraphQLClient`.
  - Seed users: `apitest` (holds every area used here), `apiorders` (3073 and 3075 only — Release 2 seed).
- Produces: nothing later tasks consume (last task before Checkpoint D).

- [ ] **Step 1: Align the test's names with the real schema**

The field names below are the contract's (Task 5). Before writing the test, confirm them against the running stack, as Release 2's and 3's flow tests did:

```bash
T=$(curl -s -H 'Content-Type: application/json' \
  -d '{"query":"mutation{login(user:\"apitest\",password:\"password\"){accessToken}}"}' \
  http://localhost:8100/modules/graphql/ | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["login"]["accessToken"])')
for t in RecurringDue RecurringGenerateInput RecurringGenerateResult GenerateError Recurrence RecurrenceInput; do
  curl -s -H 'Content-Type: application/json' -H "Authorization: Bearer $T" \
    -d "{\"query\":\"{__type(name:\\\"$t\\\"){name fields{name} inputFields{name}}}\"}" \
    http://localhost:8100/modules/graphql/; echo
done
curl -s -H 'Content-Type: application/json' -H "Authorization: Bearer $T" \
  -d '{"query":"{__schema{queryType{fields{name args{name}}} mutationType{fields{name}}}}"}' \
  http://localhost:8100/modules/graphql/ | python3 -c 'import sys,json;d=json.load(sys.stdin)["data"]["__schema"];print([f for f in d["queryType"]["fields"] if f["name"].startswith("recurring")]);print([f["name"] for f in d["mutationType"]["fields"] if f["name"].startswith("recurring")])'
```

Expected: `recurringDueList(asOf)` on Query, `recurringGenerate` on Mutation, and the fields listed under Interfaces. If a name differs (Task 5 may have recorded a deviation), use the real name in the constants of Step 2 and note it in your report. Also confirm how `FaOrderRows::purge()` treats `sales_recurring`:

```bash
grep -n "sales_recurring" tests/Support/FaOrderRows.php
```

If it does not delete the order's `sales_recurring` row, the test's tearDown deletes it (the code below does so unconditionally — harmless if already gone).

- [ ] **Step 2: Write the HTTP flow test**

The private helpers `pdo()`, `table()`, `today()`, `code()`, `ok()`, `createCustomer()`, `balance()`, `readInvoice()` and `ordersOfTestCustomers()` are copied verbatim from `tests/Http/BillingFlowTest.php` (they are private there; copy their bodies exactly, then adjust only the constant names they reference if yours differ). They are shown here so the file is complete; if `BillingFlowTest`'s versions differ from these, keep `BillingFlowTest`'s.

`graphql:tests/Http/RecurringGenerationFlowTest.php`:

```php
<?php

namespace FA\GraphQL\Tests\Http;

use FA\GraphQL\Tests\Support\FaBillingRows;
use FA\GraphQL\Tests\Support\FaOrderRows;
use FA\GraphQL\Tests\Support\FaTestRows;
use FA\GraphQL\Tests\Support\MailCatcher;
use PHPUnit\Framework\TestCase;

/**
 * Release 4 end to end (spec §4.2, §8): a recurring order is due, is generated
 * (delivered and invoiced) and emailed in one call, is no longer due, cannot be
 * billed twice, is paid, and leaves the customer's balance at zero. Items of one
 * recurringGenerate call are independent. Who may list and who may generate.
 *
 * Everything served here for recurrence comes from sgw_sales' extension; this
 * module ships no recurrence code (spec §1, success).
 *
 * tearDown removes everything a test wrote, pass or fail: billing rows written
 * since setUp (FaBillingRows), the orders (FaOrderRows) and their sales_recurring
 * rows, the test's customers (FaTestRows), the refresh tokens its logins created,
 * and the mail it caused.
 */
class RecurringGenerationFlowTest extends TestCase
{
    use GraphQLClient;

    /** A sellable service item (Release 3 BillingFlowTest Step 1): no stock check. */
    private const ITEM = '202';

    /** A home-currency bank account (Release 3 BillingFlowTest Step 1). */
    private const BANK_ACCOUNT = '1';

    private const CUSTOMER_CREATE = 'mutation ($in: [CustomerCreateInput!]!) '
        . '{ customerCreate(input: $in) { id branches { id } } }';

    private const ORDER_CREATE = 'mutation ($in: [SalesOrderCreateInput!]!) '
        . '{ salesOrderCreate(input: $in) { id version recurring { start repeats every monthDay next } } }';

    private const DUE_LIST = 'query ($asOf: Date!) { recurringDueList(asOf: $asOf) '
        . '{ orderId customerId reference customerRef next repeats every monthDay } }';

    private const GENERATE = 'mutation ($in: [RecurringGenerateInput!]!) { recurringGenerate(input: $in) '
        . '{ orderId invoiceId deliveryId next email { id sent recipient messages } error { code message } } }';

    private const INVOICE_FIELDS = '{ id total outstanding orderId deliveryIds voided }';

    private const INVOICE_BY = 'query ($q: MangoInput) { invoiceList(query: $q) ' . self::INVOICE_FIELDS . ' }';

    private const PAYMENT_CREATE = 'mutation ($in: [CustomerPaymentCreateInput!]!) '
        . '{ customerPaymentCreate(input: $in) { id unallocated allocations { toId amount } } }';

    private const CUSTOMER_BALANCE = 'query ($q: MangoInput) { customerList(query: $q) { id balance { balance } } }';

    private string $token;
    private string $prefix;
    /** @var int[] */
    private array $orders = [];
    private int $tokenMark = 0;
    /** @var string[] */
    private array $mailBefore = [];
    private ?FaOrderRows $orderRows = null;
    private ?FaBillingRows $billingRows = null;
    private ?\PDO $pdo = null;

    protected function setUp(): void
    {
        if (!$this->hasRecurringTable()) {
            $this->markTestSkipped('Recurring generation needs sgw_sales active (the sales_recurring table).');
        }
        $this->prefix = 'RGEN-' . bin2hex(random_bytes(4));
        $this->orderRows = FaOrderRows::mark($this->pdo(), $this->table(''));
        $this->billingRows = FaBillingRows::mark($this->pdo(), $this->table(''));
        $this->tokenMark = (int) $this->pdo()->query(
            'SELECT COALESCE(MAX(id), 0) FROM ' . $this->table('graphql_refresh_token')
        )->fetchColumn();
        $this->mailBefore = MailCatcher::available() ? MailCatcher::files() : [];
        $this->token = $this->login()['accessToken'];
    }

    protected function tearDown(): void
    {
        if ($this->orderRows === null) {
            return;
        }
        $this->billingRows->purge();
        $orders = array_unique(array_merge($this->orders, $this->ordersOfTestCustomers()));
        $recurring = $this->pdo()->prepare('DELETE FROM ' . $this->table('sales_recurring') . ' WHERE trans_no = ?');
        foreach ($orders as $orderNo) {
            $recurring->execute([$orderNo]);
            $this->orderRows->purge((int) $orderNo);
        }
        FaTestRows::sweep($this->pdo(), $this->prefix, $this->table(''));
        $this->pdo()->prepare('DELETE FROM ' . $this->table('graphql_refresh_token') . ' WHERE id > ?')
            ->execute([$this->tokenMark]);
        if (MailCatcher::available()) {
            MailCatcher::delete(MailCatcher::newSince($this->mailBefore));
        }
        $this->orders = [];
        $this->orderRows = null;
        $this->billingRows = null;
    }

    public function testADueRecurringOrderIsGeneratedEmailedAndPaidOnce(): void
    {
        $customer = $this->createCustomer();
        $order = $this->createRecurringOrder($customer, 1, 80.0);
        $this->assertSame('YEAR', $order['recurring']['repeats']);

        // Due today: a new schedule has no next date yet, so it is due from its start.
        $due = $this->dueOrderIds();
        $this->assertContains((string) $order['id'], $due, 'the new recurring order is due');

        // Generate it and email the invoice in the same call.
        $result = $this->ok(self::GENERATE, ['in' => [[
            'orderId' => $order['id'],
            'date' => $this->today(),
            'email' => MailCatcher::available(),
        ]]])['recurringGenerate'];
        $this->assertCount(1, $result);
        $item = $result[0];
        $this->assertNull($item['error'], json_encode($item['error']));
        $this->assertSame((string) $order['id'], (string) $item['orderId']);
        $this->assertNotNull($item['invoiceId']);
        $this->assertNotNull($item['deliveryId']);
        $this->assertGreaterThan($this->today(), (string) $item['next'], 'the next date moves on a year');
        if (MailCatcher::available()) {
            $this->assertTrue($item['email']['sent'], implode("\n", $item['email']['messages']));
            $this->assertStringEndsWith('@example.com', (string) $item['email']['recipient']);
            $this->assertCount(1, MailCatcher::newSince($this->mailBefore));
        }

        // The invoice is the order's, delivered from it, and wholly outstanding.
        $invoice = $this->invoice((string) $item['invoiceId']);
        $this->assertSame((string) $order['id'], (string) $invoice['orderId']);
        $this->assertSame([(string) $item['deliveryId']], array_map('strval', $invoice['deliveryIds']));
        $total = (float) $invoice['total'];
        $this->assertGreaterThan(0.0, $total);
        $this->assertEqualsWithDelta($total, (float) $invoice['outstanding'], 0.001);

        // No longer due, and a retry bills nothing twice (spec §4.1).
        $this->assertNotContains((string) $order['id'], $this->dueOrderIds(), 'generated orders leave the due list');
        $retry = $this->ok(self::GENERATE, ['in' => [['orderId' => $order['id'], 'date' => $this->today()]]])
            ['recurringGenerate'][0];
        $this->assertNull($retry['invoiceId']);
        $this->assertNotNull($retry['error']);
        $this->assertSame('FA_REJECTED', $retry['error']['code'], (string) $retry['error']['message']);
        $this->assertCount(1, $this->invoicesOfOrder((string) $order['id']), 'exactly one invoice for the period');

        // Pay it; the customer is settled.
        $payment = $this->ok(self::PAYMENT_CREATE, ['in' => [[
            'customerId' => $customer['id'],
            'branchId' => $customer['branches'][0]['id'],
            'bankAccountId' => self::BANK_ACCOUNT,
            'date' => $this->today(),
            'amount' => $total,
            'allocations' => [['invoiceId' => $item['invoiceId'], 'amount' => $total]],
        ]]])['customerPaymentCreate'][0];
        $this->assertEqualsWithDelta(0.0, (float) $payment['unallocated'], 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $this->invoice((string) $item['invoiceId'])['outstanding'], 0.001);
        $this->assertEqualsWithDelta(0.0, $this->balance($customer['id']), 0.001);
    }

    public function testItemsOfOneCallAreIndependent(): void
    {
        $customer = $this->createCustomer();
        $order = $this->createRecurringOrder($customer, 1, 25.0);

        // Item 0 names an order that does not exist; item 1 is due. Item 1 still bills.
        $result = $this->ok(self::GENERATE, ['in' => [
            ['orderId' => '999999', 'date' => $this->today()],
            ['orderId' => $order['id'], 'date' => $this->today()],
        ]])['recurringGenerate'];
        $this->assertCount(2, $result);
        $this->assertNotNull($result[0]['error'], 'an unknown order is refused');
        $this->assertNull($result[0]['invoiceId']);
        $this->assertNull($result[1]['error'], json_encode($result[1]['error']));
        $this->assertNotNull($result[1]['invoiceId'], 'a refused item does not stop the next (spec §4.2)');
        $this->assertCount(1, $this->invoicesOfOrder((string) $order['id']));
    }

    public function testAnOrderRoleMayListButNotGenerate(): void
    {
        $customer = $this->createCustomer();
        $order = $this->createRecurringOrder($customer, 1, 10.0);
        $orders = $this->login('apiorders')['accessToken'];   // 3073 + 3075 only (Release 2 seed)

        $listed = $this->gql(self::DUE_LIST, ['asOf' => $this->today()], $orders);
        $this->assertNull($this->code($listed), $listed['raw']);

        $refused = $this->gql(self::GENERATE, ['in' => [['orderId' => $order['id'], 'date' => $this->today()]]], $orders);
        $this->assertSame('FORBIDDEN', $this->code($refused), $refused['raw']);
        $this->assertCount(0, $this->invoicesOfOrder((string) $order['id']), 'nothing was billed');
    }

    /** @return string[] */
    private function dueOrderIds(): array
    {
        $due = $this->ok(self::DUE_LIST, ['asOf' => $this->today()])['recurringDueList'];
        return array_map(static function (array $row): string {
            return (string) $row['orderId'];
        }, $due);
    }

    private function createRecurringOrder(array $customer, int $quantity, float $price): array
    {
        $order = $this->ok(self::ORDER_CREATE, ['in' => [[
            'customerId' => $customer['id'],
            'branchId' => $customer['branches'][0]['id'],
            'orderDate' => $this->today(),
            'customerRef' => $this->prefix . '-order',
            'lines' => [['stockId' => self::ITEM, 'quantity' => $quantity, 'unitPrice' => $price]],
            'recurring' => [
                'start' => $this->today(),
                'repeats' => 'YEAR',
                'every' => 1,
                'monthDay' => substr($this->today(), 5),
            ],
        ]]])['salesOrderCreate'][0];
        $this->orders[] = (int) $order['id'];
        return $order;
    }

    private function invoice(string $id): array
    {
        $rows = $this->ok(self::INVOICE_BY, ['q' => ['selector' => json_encode(['id' => $id])]])['invoiceList'];
        $this->assertCount(1, $rows, "invoice $id");
        return $rows[0];
    }

    /** @return array[] */
    private function invoicesOfOrder(string $orderId): array
    {
        return array_values(array_filter(
            $this->ok(self::INVOICE_BY, ['q' => ['selector' => json_encode(['orderId' => $orderId])]])['invoiceList'],
            static function (array $invoice): bool {
                return !$invoice['voided'];
            }
        ));
    }

    private function hasRecurringTable(): bool
    {
        $found = $this->pdo()->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $found->execute([$this->table('sales_recurring')]);
        return (int) $found->fetchColumn() === 1;
    }

    // --- Copied verbatim from tests/Http/BillingFlowTest.php (private there). ---
    // pdo(), table(), today(), code(), ok(), createCustomer(), balance(),
    // ordersOfTestCustomers(): paste BillingFlowTest's bodies here unchanged.
    // createCustomer() must create the customer with a contact email @example.com
    // and a customer ref starting with $this->prefix, as BillingFlowTest's does.
}
```

Replace the last comment block with `BillingFlowTest`'s eight private methods, pasted unchanged. (They are not reproduced here because they must be byte-identical to that file's current versions — two copies that drift are worse than one; if you find yourself editing them, extract a `BillingFlowHelpers` trait under `tests/Http/` used by both tests instead, and record the choice.)

- [ ] **Step 3: Run it**

Run: `docker/fa-graphql test --filter RecurringGenerationFlowTest`
Expected: PASS (3 tests). The behaviour exists since Tasks 2–5; this test pins it end to end. If it fails, the defect is in the task that owns the failing behaviour (generation: Task 4/5; `recurring` fields: Task 2; areas: Task 5) — fix it there, re-run that task's tests, and record the fix in your report. Then confirm cleanup:

```bash
docker/fa-graphql db shell <<'SQL'
SELECT COUNT(*) FROM 0_sales_recurring; SELECT COUNT(*) FROM 0_debtor_trans;
SELECT COUNT(*) FROM 0_sales_orders; SELECT COUNT(*) FROM 0_graphql_refresh_token;
SQL
```

Record the counts before the run and after: they must match.

- [ ] **Step 4: Write the per-company activation test**

`graphql:tests/Integration/Extension/ExtensionActivationTest.php`:

```php
<?php

namespace FA\GraphQL\Tests\Integration\Extension;

use DI\Container;
use FA\GraphQL\Auth\Claims;
use FA\GraphQL\Config;
use FA\GraphQL\Fa\Bootstrap;
use FA\GraphQL\RequestInfo;
use FA\GraphQL\SessionGate;
use FA\GraphQL\Tests\Integration\FaTestCase;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Schema;

/**
 * Extensions are per company (spec §2.1, §3.3): with sgw_sales active for the
 * token's company its fields are in the schema; with it inactive they are absent —
 * not null, not refused, absent — and the core schema is unchanged.
 *
 * Inactive is simulated in-process, as Release 2's recurrence tests did:
 * install_hooks() puts an extension in $Hooks only when it is active for the
 * company, and Extensions loads through hook_invoke_all over $Hooks — so removing
 * sgw_sales from $Hooks before the schema is first built is what "inactive for this
 * company" looks like to the loader.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ExtensionActivationTest extends FaTestCase
{
    private Container $container;

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
        $gate->enter(new Claims(0, 'apitest', 'extension-activation-test', new \DateTimeImmutable('+5 minutes')));
    }

    public function testSgwSalesFieldsAreServedWhereItIsActive(): void
    {
        if (!isset($GLOBALS['Hooks']['sgw_sales'])) {
            $this->markTestSkipped('sgw_sales is not active for company 0 on this stack.');
        }
        $schema = $this->container->get(Schema::class);

        $this->assertTrue($schema->getQueryType()->hasField('recurringDueList'));
        $this->assertTrue($schema->getMutationType()->hasField('recurringGenerate'));
        $this->assertTrue($this->objectType($schema, 'SalesOrderType')->hasField('recurring'));
        $this->assertTrue($this->inputType($schema, 'SalesOrderCreateInput')->hasField('recurring'));
        $this->assertTrue($this->inputType($schema, 'SalesOrderUpdateInput')->hasField('recurring'));
    }

    public function testSgwSalesFieldsAreAbsentWhereItIsInactive(): void
    {
        unset($GLOBALS['Hooks']['sgw_sales']);   // before the schema is first built
        $schema = $this->container->get(Schema::class);

        $this->assertFalse($schema->getQueryType()->hasField('recurringDueList'));
        $this->assertFalse($schema->getMutationType()->hasField('recurringGenerate'));
        $this->assertFalse($this->objectType($schema, 'SalesOrderType')->hasField('recurring'));
        $this->assertFalse($this->inputType($schema, 'SalesOrderCreateInput')->hasField('recurring'));
        $this->assertFalse($this->inputType($schema, 'SalesOrderUpdateInput')->hasField('recurring'));
        $this->assertNull($schema->getType('Recurrence'), 'no sgw_sales type leaks into the schema');

        // The core is unchanged.
        $this->assertTrue($schema->getQueryType()->hasField('salesOrderList'));
        $this->assertTrue($schema->getMutationType()->hasField('salesOrderCreate'));
        $this->assertTrue($this->objectType($schema, 'SalesOrderType')->hasField('lines'));
    }

    private function objectType(Schema $schema, string $name): ObjectType
    {
        $type = $schema->getType($name);
        $this->assertInstanceOf(ObjectType::class, $type, $name);
        return $type;
    }

    private function inputType(Schema $schema, string $name): InputObjectType
    {
        $type = $schema->getType($name);
        $this->assertInstanceOf(InputObjectType::class, $type, $name);
        return $type;
    }
}
```

`$schema->getType('Recurrence')` returns `null` for an unknown name in webonyx 15 when the schema has no type loader; if the module's schema uses a type loader that throws instead, replace that assertion with `$this->assertArrayNotHasKey('Recurrence', $schema->getTypeMap())` and record it.

- [ ] **Step 5: Run it**

Run: `docker/fa-graphql test --filter ExtensionActivationTest`
Expected: PASS (2 tests). If `testSgwSalesFieldsAreAbsentWhereItIsInactive` finds the fields present, the Extensions service was resolved before the test removed the hook (for example, eagerly in `SessionGate::enter()`): that contradicts spec §2.1's "after FaSession opens or enters a company" only if it also ignores the company's `$Hooks` — check which, fix in Task 1's `Extensions` (it must read `$GLOBALS['Hooks']` when it first loads, not cache a list from boot), and record it.

- [ ] **Step 6: README (this module)**

In `graphql:README.md`:

1. Replace the status line with:

```markdown
**Status: Release 4 (Extensions and recurring invoices).** What comes next is in
[`ROADMAP-2026-09.md`](ROADMAP-2026-09.md); the designs are in
`docs/superpowers/specs/`.
```

2. In "What it covers", change the "Recurring schedules" row's first cell to `Recurring schedules (sgw_sales extension)`, and add after it:

```markdown
| Recurring invoices (sgw_sales extension) | `recurringDueList(asOf)` | `recurringGenerate` (deliver, invoice and optionally email each due order; items independent) |
```

and after the table's closing paragraph add:

```markdown
Rows marked *(sgw_sales extension)* are served by the `sgw_sales` FrontAccounting
extension through this module's extension contract, only for companies where
`sgw_sales` is active; see [Extensions](#extensions).
```

3. Add a new top-level section after "Calling the API"'s subsections (before "Generating Types"):

````markdown
## Extensions

Other FrontAccounting extensions can add to this API without this module knowing
about them (Release 4 spec §2). `sgw_sales` is the first: it serves the recurrence
fields and recurring invoice generation.

**Discovery.** When a request opens a company, this module calls
`hook_invoke_all('graphql_extensions', $registry)` over that company's active
FrontAccounting extensions. An extension adds a method to its `hooks_*` class:

```php
function graphql_extensions(&$registry, $opts = null)
{
    if (interface_exists(\FA\GraphQL\Extension\Extension::class)) {
        $registry->register(new \My_Module\GraphQL\MyExtension());
    }
}
```

The `interface_exists` guard means the extension loads none of this module's classes
unless this module is serving the request; nothing needs `composer require` — the
contract is loaded by this module's autoloader in the same PHP process. An extension
inactive for the token's company contributes nothing: its fields are absent from that
company's schema.

**The contract** (`FA\GraphQL\Extension\`, version `1.0`):

| | |
|---|---|
| `Extension` | `name()`, `contractVersion()`, `queryFields()`, `mutationFields()`, `typeFields()`, `inputFields()`, `participants()` — extend `AbstractExtension` and override what you contribute |
| `queryFields` / `mutationFields` | root fields, `name => field config`; types are the extension's own (generated with `anorm-graphql` from its models where it has any, extending `FaModelType`) |
| `typeFields` / `inputFields` | fields added to extensible core types: `SalesOrderType`, `SalesOrderCreateInput`, `SalesOrderUpdateInput`; input fields must be nullable |
| `participants` | `SalesOrderParticipant` objects: `validate`, `isRelaxed`, `afterCreate`, `afterUpdate`, `afterDelete`, `afterClose` — called by the sales order service inside the order's transaction |
| `ExtensionContext` | the request's container, `FaSession`, `InvoiceMailer`, `includeFa()`, company and login; use `Guard`, `ServiceCall`, `FaTransaction`, `DocumentLock`, `DateConversion`, `BadInput` and `FaRejected` as the core does |

**Loader rules**, checked on every request; a rejected extension is dropped and logged
(`graphql extension <name>: <reason>` in the error log), never failing the request:

- no root field, type, type field or input field that the core or an earlier extension
  already has — on a clash the later extension is dropped whole;
- `typeFields`/`inputFields` only on the extensible core types above;
- contributed input fields nullable;
- `contractVersion()` with the same major version as this module's contract;
- an exception while registering or collecting contributions drops that extension.

A participant that throws during a write is **not** isolated: it is part of the
mutation's transaction, so the mutation fails and nothing is written. Extensions are
trusted code — they run in the request's process, transaction and document lock.

**Testing an extension.** Put its GraphQL tests in `<extension>/tests/GraphQL/` with
their own PHPUnit config, and run them inside this module's stack against a checkout
of the extension bind-mounted over the image's copy:

    docker/fa-graphql test-extension sgw_sales

`apiVersion` is this module's version; an extension's fields are versioned by the
extension.

### Recurring invoice generation (sgw_sales)

For companies where `sgw_sales` is active:

```graphql
query ($asOf: Date!) {
  recurringDueList(asOf: $asOf) { orderId customerId reference customerRef next repeats every monthDay }
}

mutation ($in: [RecurringGenerateInput!]!) {
  recurringGenerate(input: $in) {
    orderId invoiceId deliveryId next
    email { sent recipient messages }
    error { code message }
  }
}
```

- `recurringGenerate` takes `{orderId, date, email}` per item. Each item delivers and
  invoices one due period and advances the schedule, in one FrontAccounting
  transaction: a retry after success finds the order not due and bills nothing twice
  (`error.code` `FA_REJECTED`).
- **Items are independent**: a refused item reports `error` and the rest continue — a
  billing run over many orders is not all-or-nothing. This is the one exception to the
  module's atomic batches.
- `email: true` sends each invoice through FrontAccounting's `rep107` after its item
  commits, with `invoiceEmail`'s rules.
- Areas: `recurringDueList` needs *Sales transactions view*; `recurringGenerate`
  needs *Sales deliveries edition* and *Sales invoices edition*. The seeded *GraphQL
  Panel* role can list but not generate; granting generation to a machine-token user
  is an operator decision.
- Scheduling rules (what "due" means, the next date) are `sgw_sales`' own.
````

4. In "Layout", add a row after the `src/` row:

```markdown
| `src/Extension/` | the extension contract: interfaces, registry, loader, context |
```

- [ ] **Step 7: ROADMAP**

In `graphql:ROADMAP-2026-09.md`:

1. In "Where it stands", add a row to the table after "Release 3 — Billing":

```markdown
| Release 4 — Extensions and recurring invoices | An extension contract discovered through FrontAccounting's hooks (root fields, contributions to the sales order Type and inputs, write participants in the core's transaction); `sgw_sales` as the first extension, serving recurrence and recurring invoice generation (`recurringDueList`, `recurringGenerate` with email) | `2026-09-28-release-4-extensions-recurring-design.md` |
```

2. Replace the whole "Next: Release 4 — Recurring invoice generation" section with:

```markdown
## Next: the rest of accounts receivable

Roughly in the order the panel is expected to need them.

1. **Credit notes** — from an invoice and free-hand; the largest AR gap.
2. **Invoice PDF download** and customer statements, through FrontAccounting's reports
   (the `bin/fa-report` child already runs them).
3. **Direct invoices** without an order, **cash sales**, and **prepayment (deposit)
   invoices**.
4. **Quotations**, and converting a quotation to an order.
5. **Editing posted deliveries and invoices** (today: void and re-enter).

Extensions may now serve work that belongs to another FrontAccounting module; new
extensible core types are added as a need appears (Release 4 spec §1, non-goals).
```

and delete the old "Later: the rest of accounts receivable" section (its list moved up).

3. In "Deferred hardening", delete the bullet about the report child's multi-company login if present (fixed in Release 4, spec §4.3), and in "Platform" add:

```markdown
- **More extensible core types** for extensions (today: the sales order Type and
  inputs), when an extension needs them.
```

- [ ] **Step 8: README (sgw_sales)**

In `sgw_sales:README.md`:

1. In "Requirements", replace the bullet that says to apply `sql/update_1.4.sql` by hand with:

```markdown
 - Activating the extension creates and upgrades its table (`sql/update_1.0.sql`, then
   `sql/update_1.4.sql`); an install activated before this release should be
   re-activated per company once. Grant the *SayGo Sales* areas to a role in
   Setup → Access Setup.
```

2. In "Calling it from other code", make the service description match Task 4's hardened API (read the section first and change only what no longer holds): `due(\DateTimeInterface $asOf)` returns the orders due on that date; `generate(int $orderNo, \DateTimeInterface $invoiceDate)` delivers and invoices one due period and advances the schedule in one FrontAccounting transaction, and no longer emails — the page emails after it; it throws `RecurrenceNotFound`, `RecurrenceEnded`, `RecurrenceNotDue` or `GenerationRefused`.

3. Add a section before "Development":

````markdown
## GraphQL extension ##

When the [FrontAccounting GraphQL module](https://github.com/saygoweb/frontaccounting-module-graphql)
is installed, this module extends its API through the module's extension contract
(`hooks_sgw_sales::graphql_extensions`, code in `includes/GraphQL/`). Without the
GraphQL module nothing here is loaded.

For companies where this module is active the API gains:

 - `recurring` on sales orders (read) and on the sales order create/update inputs
   (`start`, `end`, `repeats` `MONTH|YEAR`, `every`, `day` or `monthDay`, `auto`),
   written in the order's own transaction;
 - `recurringDueList(asOf)` — the recurring orders due on a date;
 - `recurringGenerate(input: [{orderId, date, email}])` — deliver, invoice and
   optionally email each due order; items are independent, and a retry never bills a
   period twice.

The page and the API share one generation service (`RecurringInvoiceService`).

Its GraphQL tests (`tests/GraphQL/`) run inside the GraphQL module's docker stack,
against this checkout:

    SGW_SALES_PATH=$PWD ../graphql/docker/fa-graphql test-extension sgw_sales
````

(If Task 3 settled a different way to point the module's stack at this checkout, use that command and record it.)

- [ ] **Step 9: Gates and commit**

This module, branch `feature/release-4`:

```bash
docker/fa-graphql test && docker/fa-graphql lint && docker/fa-graphql analyze
docker/fa-graphql test-extension sgw_sales
git add tests/Http/RecurringGenerationFlowTest.php tests/Integration/Extension/ExtensionActivationTest.php README.md ROADMAP-2026-09.md
git commit -m "Release 4 end to end: generate, email and pay a recurring order; per-company activation; docs

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013U8a6JAbsHc4c1KcSLiaYK"
```

Expected: every suite PASS (one known skip on upstream), lint and analyze clean, tables at their starting row counts, the mail catcher as found.

`sgw_sales`, branch `feature/graphql-extension`:

```bash
cd ../sgw_sales
git add README.md
git commit -m "README: the GraphQL extension, activation, the hardened generation service

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013U8a6JAbsHc4c1KcSLiaYK"
```

---

### Checkpoint D — final (both repositories), then push and open the two PRs

Run by the controller after Task 6. Reviews and fixes as at Releases 2 and 3: one independent review, one fix wave, one scoped re-review, then publish. **Do not merge either PR** — merging, and the order it happens in, is the user's decision (the merge notes below say what order works).

- [ ] **Review packages.** Build one per repository:

```bash
D=/home/cambell/.claude-personal/plugins/cache/claude-plugins-official/superpowers/6.4.1/skills/subagent-driven-development
P=$PWD/docs/superpowers/plans/2026-09-28-release-4-extensions-recurring.md
W=$(bash $D/scripts/sdd-workspace $P)
bash $D/scripts/review-package $P main feature/release-4 $W/review-D-graphql.diff
(cd ../sgw_sales && bash $D/scripts/review-package $P master feature/graphql-extension $W/review-D-sgw_sales.diff)
```

- [ ] **Independent review on the most capable model.** One reviewer, both packages, the whole Release 4 spec, the ledger (rulings and deferred minors). Focus, in order:
  1. **The contract's safety (spec §2.4–2.5):** a participant's exception rolls the order's transaction back (nothing written: order, lines, `sales_recurring`); a throwing extension at registration is dropped and logged and the request still answers; clashes drop the later extension whole; only the three extensible core types accept contributions; contributed inputs are nullable; an unknown contract major version is refused.
  2. **Per-company activation (§2.1, §3.3):** with `sgw_sales` inactive its fields are absent; nothing of `sgw_sales` is loaded when this module does not serve the request; `interface_exists` guard present.
  3. **Client compatibility (§3.3):** the `recurring` fields' names, types, nullability and behaviour match Release 2 — the snapshot test in `sgw_sales` pins them; the panel's flow (`PanelFlowTest`'s recurring assertions, now served by the extension) passes unchanged.
  4. **Generation (§4):** one transaction per item (delivery, invoice, `dt_next`); retry after success refused, no double billing; items independent; explicit dates; the Release 3 checks (fiscal year, rate, on hold, negative stock, closed order, ended schedule); `GenerateRecurringModel` restricted to `trans_type = 30`; email only after commit; areas 3073 / 3076 + 3077.
  5. **`bin/fa-report` (§4.3):** the target company's hooks installed before login; still CLI-only; no regression in Release 3's email tests.
  6. **The page (`sgw_sales`):** still works — list, generate, email — without the GraphQL module installed (its own stack's tests).
  7. **Success criteria (§1):** `grep -rniE 'sgw_sales|sales_recurring|recurrence|recurring' src/ bin/ app.php container.php` in this module is empty except for generic comments naming the contract's example (list and judge each hit).

  Checklist for the reviewer to walk with evidence: every numbered requirement of spec §2–§5 names its test.

- [ ] **The four combinations, both repositories under test.** Each on a throwaway stack (HTTP 8105, DB 3325, PMA 8106; never the port-8100 stack's name; `destroy --yes` after each), with `SGW_SALES_PATH` pointing at the `sgw_sales` checkout on `feature/graphql-extension`, so the branch under review is what runs:

| FrontAccounting | PHP | Run |
|---|---|---|
| upstream `master` | 7.4 | `docker/fa-graphql ci` then `docker/fa-graphql test-extension sgw_sales` |
| upstream `master` | 8.3 | same |
| fork `master-cp` | 7.4 | same |
| fork `master-cp` | 8.3 | same |

And `sgw_sales`' own stack: `docker/fa-sgw-sales ci` on PHP 7.4 and 8.3 (its service and page tests, without the GraphQL module).

Expected: all green; the email tests ran (not skipped); tables at their starting row counts on each stack.

- [ ] **One fix wave** for every Critical and Important finding and every Minor the review marks must-fix, dispatched to one implementer across both repositories (commits on the same two branches, subjects `Address Release 4 final review`). Then **one scoped re-review** of the fix diffs. Residual findings are adjudicated with rulings in the ledger, not a second wave.

- [ ] **Spec deviations** found by the review or the tasks are written into the spec, marked *(revised)*, including the delivery shape: Release 4 ships as **two PRs** (one per repository), not the four-step sequence of §6 — record the merge order below in §6.

- [ ] **Push and open the PRs.** `sgw_sales` first: this module's CI clones `sgw_sales` at `feature/graphql-extension` (Task 3's pin), which must exist on GitHub before this module's CI runs.

```bash
# sgw_sales
cd ../sgw_sales
git push -u origin feature/graphql-extension
gh pr create --base master --head feature/graphql-extension \
  --title "GraphQL extension: recurrence and recurring invoice generation; hardened generation service" \
  --body-file /tmp/claude-1000/-home-cambell-src-sgw-frontaccounting-modules-graphql/72e63b23-a4b8-464f-9b58-ec406a5ce66f/scratchpad/r4plan/pr-sgw_sales.md

# this module
cd ../graphql
git push -u origin feature/release-4
gh pr create --base main --head feature/release-4 \
  --title "Release 4: extension contract; recurrence and recurring invoices via sgw_sales" \
  --body-file /tmp/claude-1000/-home-cambell-src-sgw-frontaccounting-modules-graphql/72e63b23-a4b8-464f-9b58-ec406a5ce66f/scratchpad/r4plan/pr-graphql.md
```

Write the two body files first. Each has a Summary, a Test plan (the four combinations, `sgw_sales`' own stack, the reviews), the rulings made on the user's behalf, and this **merge order** section, identical in both:

```markdown
## Merge order

These two PRs belong together (Release 4 spec §6, revised). At every step exactly one
side serves `recurring`, so the panel sees no gap:

1. Merge **saygoweb/frontaccounting-module-sgw_sales** (`feature/graphql-extension` →
   `master`) first. On its own it is inert for the API: its extension registers only
   when the GraphQL module's contract exists, and the page's generation service is
   tested on its own stack. Until step 2 the GraphQL module's `main` still serves
   `recurring` itself — both would register the same fields, and the loader drops the
   later (the extension), so there is no clash in production either.
2. In **saygoweb/frontaccounting-module-graphql**, change the CI/stack pin
   `SGW_SALES_REF` from `feature/graphql-extension` to `master` (one commit on this
   PR), wait for CI green, then merge this PR.
3. If `sgw_sales`' CI runs its GraphQL tests against a GraphQL-module branch other than
   `main`, point it back at `main` in a follow-up PR.
```

The final line of each body is the attribution:

```markdown
🤖 Generated with [Claude Code](https://claude.com/claude-code)

https://claude.ai/code/session_013U8a6JAbsHc4c1KcSLiaYK
```

(Check step 1's claim before writing it: with this module's `main` still serving `recurring` and `sgw_sales`' extension also contributing `recurring`, does the Task 1 loader drop the extension whole rather than fail? It should — spec §2.5, "the core or an earlier extension already has". If `main` at merge time predates Task 1 entirely, `sgw_sales`' extension is never called there at all, which is also safe. Word the note to match what is true.)

- [ ] **Watch CI on both PRs** (in the background; wait with an until-loop, not chained sleeps). `sgw_sales`' PR CI: its own suites, plus its GraphQL tests if Task 2 or 3 added that job — if that job points at this module's `main`, it will fail until this module merges (the contract is not on `main` yet); pin it to `feature/release-4` for the PR, and note the follow-up in merge step 3. Report each PR's four jobs.

- [ ] **Hand off** with superpowers:finishing-a-development-branch's reporting (the PRs already exist; do not merge). Tell the user:
  - both PR URLs and CI results, and the merge order;
  - the rulings made during Release 4 (from the ledger, each with its cost if wrong);
  - operating notes: re-activate `sgw_sales` per company (its activation now applies `update_1.4.sql`); the panel's machine-token role can list due orders but not generate until an operator grants *Sales deliveries edition* and *Sales invoices edition*;
  - what comes next (ROADMAP: the rest of AR, credit notes first) and the deferred minors.
