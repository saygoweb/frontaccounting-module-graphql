# Inputs to the specification

Collected while scaffolding (2026-09-21). Not a design — the things the design has
to answer. Sources: the anorm-graphql design spec
(`anorm-graphql/docs/superpowers/specs/2026-09-21-anorm-graphql-design.md`), a
status exchange with the anorm-graphql session, `modules/api`, and what the
scaffold turned up.

## Priorities (from Cambell, 2026-09-21)

1. Sales orders, invoices, customer payments and allocations.
2. Conditionally — when `sgw_sales` is installed — its recurring orders: listing
   the generation table, and triggering the generation of recurring invoices.
3. The first client is `saygoweb.com-my`, a custom web hosting panel, which needs
   to **add recurring invoices and trigger the sending of them**. Its backend is
   PHP (Slim 4, Guzzle, PHP-DI, Anorm 3.2), so calls are server-to-server; it
   already talks to i-MSCP's GraphQL API with bearer tokens
   (`docs/superpowers/specs/2026-09-19-imscp-graphql-integration-design.md` there).
   It has no FrontAccounting integration today.
4. PHP 7.4 floor, settled.

### What sgw_sales brings, and costs

- One table, `sales_recurring` (`id`, `trans_no` → sales order, `dt_start`,
  `dt_end`, `dt_next`, `auto`, `every`, `repeats` year|month, `occur`). Single
  key, so it can be generated — but a recurring order is only meaningful together
  with its sales order, which is written through FA's `Cart`.
- The generation list is a join (`GenerateRecurringModel::find($showAll)`), not a
  table: a hand-written read-only Type.
- Generation is `SGW_Sales\controller\GenerateRecurring`: `generateInvoice()`
  (Cart: order → delivery → invoice), `emailInvoice()` (requires
  `reporting/rep107.php` and drives it through `$_POST`), then advances `dt_next`.
  It is welded to its view (`$this->_view->generatedInvoice()`) and the loop lives
  in `run()`, reading `$_POST`. Reusing it means a stub view or a small refactor
  in sgw_sales to extract a service.
- **Anorm version clash.** sgw_sales locks `saygoweb/anorm` v1.6.0 in its own
  `vendor/`; this module needs ^3.2. Both `hooks.php` files load their own
  autoloader into the same process, so one `Anorm\` wins for both. Either
  sgw_sales moves to Anorm 3, or the two must never load together.
- "Conditionally" needs a rule: detect the active extension at request time and
  add the recurring fields to the schema only then.

## Open decisions

1. ~~webonyx/graphql-php 14.x is blocked by composer.~~ **Decided 2026-09-21.**
   Three DoS advisories (GHSA-r7cg-qjjm-xhqq, GHSA-fc86-6rv6-2jpm, CVE-2026-40476)
   cover every 14.x release; the fixes are in 15.32.3+, and `simpod/graphql-utils`
   for webonyx 15 needs PHP >= 8.1. anorm-graphql v1 therefore ships on
   `webonyx/graphql-php ^15.32.3` with **no simpod**, keeping the PHP 7.4 floor.
   Its runtime provides `Anorm\GraphQL\Builder\FieldBuilder` and `ObjectBuilder`
   with the same calls; hand-written Types here should use those, not raw config
   arrays, so they read like the generated ones. This module pins `^15.32.3` too.
2. ~~PHP floor.~~ **Decided: 7.4.**
3. ~~Which FrontAccounting.~~ **Decided 2026-09-21: the fork plus sgw_sales.** The
   image builds `cambell-prince/frontaccounting` @ `master-cp` and clones
   `sgw_sales` into `modules/`, activated by the entrypoint — what production runs.
   **sgw_sales is refactored as part of this work:** upgraded to Anorm 3 (its 1.6
   cannot share a process with this module's 3.2), and its generation logic
   extracted into a view-free service that both its page and this API call. One
   implementation of billing logic; the date arithmetic gets tests.
4. ~~Authentication and session.~~ **Decided 2026-09-21: JWT, granted on user
   authentication.** A client authenticates as a FrontAccounting user (company,
   user, password) and is given a signed JWT; every other request carries
   `Authorization: Bearer <jwt>`, and the module boots FA as the user and company
   in its claims, ending in a real `$_SESSION['wa_current_user']` because FA's own
   functions check and record it. No per-request passwords (`modules/api`'s
   `X-USER` / `X-PASSWORD`), no opaque tokens in a table. **Lifetime: short access JWT + long-lived refresh token**, the refresh token
   kept hashed in a module table (created through `activate_extension` /
   `update_databases`), rotated on use and individually revocable. Still open:
   where the signing secret lives. `saygoweb.com-my`
   already uses `lcobucci/jwt ^4.0`, which runs on PHP 7.4.
5. ~~Authorisation.~~ **Decided: per-entity checks in `authorize()`, mapped onto
   FA's existing `SA_*` areas.** `FaModelType::authorize()` calls
   `Guard::requireFor($this->areas(), $verb)`; each once-only Type declares
   `areas(): array` (verb => `SA_*` area) rather than a new area of its own. See
   design spec §4.5 and §5.
6. ~~Multi-company.~~ **Decided: `CompanyContext`, set once per request.**
   `FaSession::openCompany()` resolves `$db_connections[$company]` and calls
   `CompanyContext::set()`; the container's `\PDO` and every model's table prefix
   are built lazily from it. See design spec §4.2–§4.3.
7. **Scope and order of entities.** Which tables first; which are read-only.
8. ~~Licence.~~ **Decided: GPL-3.0-or-later**, matching FrontAccounting and the sgw
   modules: the `LICENSE` file and `license` in `composer.json`. See design spec
   §10, step 1.
9. **Test data.** FA's `en_US-demo.sql` for now. A fixture of this module's own?

## Naming: models are where the domain language is fixed

Entity names come from the model class short name minus `Model`; GraphQL field
names are model property names; Mango selectors filter on property names. So the
renaming from FA's schema happens once, in the models: `DebtorsMaster` →
`CustomerModel`, `debtor_no` → `customerId` (or `id`), `br_name` → `branchName`.
The spec needs the full table → entity and column → property map, and a rule for
how far `anorm make` output is regenerated versus hand-maintained once renamed.

Properties ending in `Id` become GraphQL `ID`; FA's `_no` / `_code` foreign keys
will not unless renamed to end in `Id` — another reason to rename.

## Constraints inherited from anorm-graphql 0.1

- `--type-base <class>` (added in Task 9) is how `FaModelType` enters every
  generated Type: the generated `<Entity>TypeBase` extends the named class
  instead of `Anorm\GraphQL\ModelType` (design spec §4.5).
- A model with no single key property is **skipped**. `debtor_trans`
  (`type` + `trans_no`), `gl_trans`-style and other composite-key tables need
  hand-written Types.
- **Never upsert transactional tables directly** — it bypasses GL postings.
  Generate them `--readonly`, then override `resolveUpsert` in the once-only
  subclass to call FA's own functions (`write_customer_trans`, carts, ...).
- `ModelType::newModel()` does `new $class($container->get(\PDO::class))`, and
  Anorm's QueryBuilder constructs models as `new $class($pdo)`. Register `\PDO` in
  the PHP-DI container as *the* connection; every model constructor takes a PDO
  first and runs no query (generation uses a `NullPdo`).
- The `0_` prefix is the models' concern (the table name given to `DataMapper`).
  It is per company, so it cannot be a literal in the model.
- Field types come from declared property types. `anorm make` output that
  documents every column as string gives an all-String schema — declare real types.
- Anorm inserts every property, so column DEFAULTs never apply. FA has many
  `NOT NULL DEFAULT ''` columns: give those properties PHP defaults.
- Relationship fields (nested objects) are a v1 non-goal of anorm-graphql.
- Generated tests wrap each test in a transaction and roll back; keep models in
  Anorm's static mode (DDL commits implicitly).
- An `ApiSchema` whose entities are all read-only has an empty Mutation type,
  which validators reject; remove `'mutation'` by hand in that case.
- Expected invocation: `-m src/Model -n 'FA\GraphQL\Model' -o src/Type
  -t 'FA\GraphQL\Type' -s src/ApiSchema.php --schema-ns 'FA\GraphQL'`.
- Status on 2026-09-21: spec and plan only, nothing to `composer require`. Work
  lands on branch `v1`; runtime (Tasks 2-3) before the CLI (Task 7). Consume it by
  composer `path` repository — `ANORM_GRAPHQL_PATH` in `docker/.env` mounts it.

## Tooling gaps found

- `anorm make` can only prompt for a database password (`-p`); there is no
  non-interactive form, so it cannot be scripted or run in CI as it stands.
- `anorm make <database>` with no table makes a model for table `''`; there is no
  all-tables mode. ~80 FA tables means a loop, or a change to Anorm.

## FrontAccounting behaviour worth remembering

- FA turns database errors into `E_USER_ERROR` and `output_html()` swallows them:
  an endpoint can answer 200 with an empty or HTML body. Look in `tmp/errors.log`
  (`docker/ci/plugin-dev.sh --env graphql logs errors`, run from the
  FrontAccounting checkout).
- `includes/session.inc` enforces `$page_security` and emits HTML; an API must
  boot FA without it, as `modules/api/session-custom.inc` does.
- `hooks.php` is included before the session starts — no DB work in the
  constructor without checking `logged_in()`.
- FA sets `sql_mode` on its own mysqli connection; Anorm's PDO connection gets the
  server default. The two connections are also separate transactions.

## Composite keys: what FA needs, and the anorm-graphql session's position (2026-09-21)

The panel scope is decided: customers, branches and contacts are created and
updated through the API, as well as sales orders with a recurring schedule.

Keys of the tables in scope. Single: `debtors_master`, `crm_persons`,
`crm_contacts`, `sales_order_details`, `debtor_trans_details`, `cust_allocations`,
`stock_master` (string), `sales_types`, `payment_terms`, `tax_groups`, `areas`,
`salesman`, `locations` (string), `shippers`, `bank_trans`, `sales_recurring`.
Composite: `sales_orders` (`trans_type`, `order_no`), `debtor_trans` (`type`,
`trans_no`, `debtor_no`), `cust_branch` (`branch_code`, `debtor_no`), `refs`.

These are not two-part identities. `trans_type` (30 order, 32 quotation) and
`debtor_trans.type` (10 invoice, 11 credit note, 12 payment, 13 delivery) are
**discriminators**; within one value the second column is unique. `branch_code` is
AUTO_INCREMENT and unique alone. Anorm 3.2 is single-key throughout and has no
default scopes, so true composite keys would be an Anorm change first.

Position of the anorm-graphql session (its opinion; the user decides):

- **v1: `ModelType::scope(): array`** — fixed property => value pairs ANDed into
  every list and every read-by-key, stamped on every write, left out of the
  fields and the Input. Nearly free, because the injection fix below already
  replaces `read()` with a bound query. A once-only subclass declaring `scope()`
  gives a correct list-only `SalesOrder` on v1.
- **v1.1: `--config` PHP file** returning `['entities' => [name => [model, key,
  scope, readonly, types, exclude]]]` — one model, several entities
  (`SalesOrder`/`Quotation`; `Invoice`/`CreditNote`/`CustomerPayment`/`Delivery`),
  key override, per-field type overrides (FA FKs never end in `Id`), excludes. No
  field renames, no relationship declarations in the first cut. With a config,
  `--readonly` is rejected; without one, behaviour is as today. Everything from the
  config lands in the regenerated `*Base`.
- **Not recommended:** true composite keys; generating document-shaped mutations.
  `salesOrderCreate(input)` over `Cart->write` stays hand-written; unmarked
  `ApiSchema` entries are never touched, so it sits among the generated ones.
- `cust_branch` needs nothing new: `$mapper->modelPrimaryKey = 'branchCode'`.
- Header/detail: do **not** use Anorm `hasMany` for order or transaction lines —
  relationships are single-column and would mix orders with quotations. Add a
  `lines` field in the once-only Type with a two-column `where`. Single-column
  relationships (customer → branches, crm) are safe.
- Use `$mapper->transformers` (dates, `BooleanTransform`) so models carry real
  types.
- The table prefix must come from ambient per-request state (a static company
  context set after auth): QueryBuilder constructs models as `new $class($pdo)`.
  With no company selected it must default to `0_` and not query, because
  generation runs with a `NullPdo`.

## Security: `DataMapper::read()` was injectable on Anorm 3.2.0 — fixed in 3.2.1

3.2.0 concatenated the key into SQL in `read()` and in the UPDATE branch of
`write()`; `delete()` bound. Fixed in **v3.2.1** (anorm #76, 2026-09-21), which binds
both. This module requires `saygoweb/anorm ^3.2.1`; that floor is what makes
`Model::read()` / `readOrThrow()` safe with a client-supplied id. anorm-graphql's
`ModelType` additionally loads by bound query with hostile-id tests.

## From the sgw_sales session (2026-09-21), for Release 2

- `sgw_sales` is on Anorm ^3.2 on `feature/Anorm3` (draft PR #7), not yet merged.
  Model constructors are `__construct(?\PDO $pdo = null)`; the prefix comes from the
  static `SGW_Sales\db\DB::init($tbpref)` set in `hooks_sgw_sales`.
- Extracting generation into a view-free service is **not** done.
- `en_US-demo.sql`'s fiscal years end in 2022: `Cart::write()` dies with "Column
  'fiscal_year' cannot be null" for a transaction dated today. Its stack adds years
  up to today (`ensure_fiscal_year` in `docker/fa-sgw-sales`); this one will need
  the same before any document write.
- Its stack uses ports 8110/3330/8111.

## sgw_sales generation service (from its session, 2026-09-21) — for Release 2

PR #7 (Anorm ^3.2.1) is merged to `master`. The view-free service is on
`feature/GenerateService`, draft PR #8, unmerged.

- `SGW_Sales\service\RecurringInvoiceService`:
  `due(bool $all = false)` yields `GenerateRecurringModel` (orderNo, reference, name,
  dtStart, dtEnd, dtLast, dtNext, auto, repeats, every, occur) — the generation list.
  `generate(int $orderNo, bool $email = true, ?\DateTime $today = null)` returns
  `GeneratedInvoice` (orderNo, invoiceNo, comment, dtNext, emailed); throws
  `RecurrenceNotFound` / `RecurrenceEnded`. **A not-yet-due order is invoiced** —
  gate on `due()` in the API. `RecurrenceSchedule` is pure date arithmetic.
- Preconditions: FA booted with a logged-in user, a fiscal year covering today,
  `$path_to_root`, and `hooks_sgw_sales` constructed (it calls `Anorm::connect` and
  sets the prefix). Proven under `session.inc`; **not** proven under a
  session.inc-free boot — Release 2's first integration test. Our `Bootstrap` must
  supply the db connection, `$Refs`, `$SysPrefs` and gettext, which it does.
- `emailed => true` means handed to FA's `rep107`, not delivered; failures ("no email
  contact defined") arrive in FA's message buffer — our `FaMessages` — not as
  exceptions. The mutation should return them.
- It is the one place the "one side only" rule bends: documents are written on FA's
  mysqli, `dt_next` on Anorm's PDO (`Anorm::pdo()`, sgw_sales' own connection, not
  our container's). Never wrap `generate()` in a PDO transaction.
