# FrontAccounting GraphQL module — Release 4: Extensions and recurring invoices — design

Date: 2026-09-28
Status: approved design, pre-implementation.

Builds on Releases 1–3 (`2026-09-21-foundation-design.md`,
`2026-09-25-release-2-panel-design.md`, `2026-09-26-release-3-billing-design.md`)
and machine tokens (Foundation spec §3.7). Everything they establish holds unless
this document says otherwise.

Two repositories take part: this module (`saygoweb/frontaccounting-module-graphql`)
and `sgw_sales` (`saygoweb/frontaccounting-module-sgw_sales`, `modules/sgw_sales`).

## 1. Purpose and scope

1. **An extension contract.** Other FrontAccounting extensions can add to this API
   without this module knowing about them, discovered per company through
   FrontAccounting's own hooks. Modelled on `imscp-graphql`'s extension hook, adapted
   to this module's code-first, generated schema and to writes that must share the
   core's transaction.
2. **`sgw_sales` as the pilot extension.** Release 2's recurrence (the `recurring`
   fields on sales orders) moves out of this module into `sgw_sales`, unchanged for
   clients. This module keeps no `sgw_sales` knowledge.
3. **Recurring invoice generation.** `sgw_sales`' extension lists the recurring orders
   that are due and generates their invoices, optionally emailing them.

**Decided with the user:**

- The extension code lives in the `sgw_sales` repository.
- Clients drive generation: the panel runs its own schedule and calls the API.
  `sgw_sales`' own page and cron keep working as they do.
- One generation service: `sgw_sales`' `RecurringInvoiceService` is hardened and used
  by both its page and the API.
- A wholly missed recurrence period stays rare; the existing scheduling rule is kept
  (Release 2 spec §1).

### Success

- This module's source contains no `sgw_sales`, `sales_recurring` or recurrence code.
- With `sgw_sales` active for a company, the API's recurrence fields are identical to
  Release 2's (names, types, behaviour); the panel changes nothing.
- A due recurring order can be invoiced (and emailed) through the API, once, safely on
  retry.

### Non-goals

- Server-side scheduled generation (a cron in FrontAccounting). The page's existing
  cron is untouched.
- Extensions adding to core types other than the sales order Type and inputs (the set
  is widened by later releases when a need appears).
- Changing `sgw_sales`' scheduling rules (missed periods, schedule from today).
- A composer package for the contract; see §2.1.

## 2. The extension contract (this module)

### 2.1 Discovery

After `FaSession` opens or enters a company, `ExtensionRegistry` calls
`hook_invoke_all('graphql_extensions', $registry)` over that company's active
FrontAccounting extensions. The hook method name is a plain string: an extension
loads none of this module's classes unless this module is serving the request, and
an extension inactive for the company contributes nothing. A request that opens no
company (anonymous `apiVersion`, `login` before the session) registers no extensions.

The contract's interfaces ship in this module (`FA\GraphQL\Extension\`), loaded by
its autoloader in the same FrontAccounting process. An extension guards its
registration with `interface_exists(FA\GraphQL\Extension\Extension::class)`; there is
no runtime composer dependency between the two repositories.

### 2.2 `interface Extension`

- `name(): string` — unique, e.g. `sgw_sales`.
- `contractVersion(): string` — the contract version it was built for (`1.0`). The
  loader refuses a different major version.
- `queryFields(ExtensionContext): array` and `mutationFields(ExtensionContext): array`
  — field definitions in the builder form `ApiSchema` uses, keyed by field name. Types
  are the extension's own classes (generated from its Anorm models where there are
  any, extending `FaModelType`; hand-written otherwise), resolved through the shared
  container.
- `typeFields(ExtensionContext): array` — `['SalesOrderType' => [name => field]]`:
  computed fields added to an extensible core type.
- `inputFields(ExtensionContext): array` — `['SalesOrderCreateInput' => [...],
  'SalesOrderUpdateInput' => [...]]`: nullable fields added to extensible core inputs.
- `participants(): array` — objects implementing core participant interfaces (§2.4).

### 2.3 `ExtensionContext`

The services an extension needs to follow this module's rules: the container,
`FaSession` (company, user, `isActive()`), `Guard`, `ServiceCall` and `FaTransaction`,
`DocumentLock`, `DateConversion`, `BadInput`/`FaRejected`, `InvoiceMailer`,
`Bootstrap::includeFa()`, and `FaIncludes`. Extensions are trusted code: they run in
the request's process, transaction and lock; the context makes the right thing easy,
it does not sandbox.

### 2.4 Participants

`interface SalesOrderParticipant` — called by `SalesOrderService` inside the order's
`FaTransaction`, in registration order:

- `validate(array $input, int $index): void` — before any write; throws `BadInput`.
- `isRelaxed(int $orderId, array $input): bool` — true when the order's header must
  stay editable after delivery and the delivered-quantity floor does not apply
  (replaces Release 2's `isRecurringOrder`; the core ORs the participants' answers).
- `afterCreate(int $orderId, array $input)`, `afterUpdate(int $orderId, array $input)`,
  `afterDelete(int $orderId)`, `afterClose(int $orderId)`.

A participant's exception rolls the whole mutation back — participants are part of the
write, not isolated from it (§2.5).

### 2.5 Loader rules

Checked every request by `ExtensionLoader`; a rejected extension, or a rejected part
of one, is dropped and logged (error log, `E_USER_WARNING`):

- An extension may not add a root field, type name, type field or input field that
  the core or an earlier extension already has. On a clash the later extension is
  dropped whole.
- `typeFields`/`inputFields` may target only types the core marks extensible:
  `SalesOrderType`, `SalesOrderCreateInput`, `SalesOrderUpdateInput`.
- Contributed input fields must be nullable.
- An unknown contract major version drops the extension.
- An exception while registering or collecting contributions drops that extension.
- An exception from a participant during a write is not caught by the loader; it fails
  the mutation (§2.4).

### 2.6 Schema assembly

`ApiSchema` (generated entries and hand-written ones, as today) appends the loaded
extensions' root query and mutation fields. `SalesOrderType`'s and the order inputs'
`fields()` append registered contributions. Introspection shows what is active for the
token's company. `apiVersion` remains the core's version; an extension's fields are
versioned by the extension.

## 3. `sgw_sales`' extension: recurrence

### 3.1 Location and registration

`sgw_sales/includes/GraphQL/`, namespace `SGW_Sales\GraphQL` (its existing PSR-4 map).
`hooks_sgw_sales::graphql_extensions($registry)` registers `SgwSalesExtension` when the
contract interface exists.

### 3.2 What moves

| From this module | To `sgw_sales` |
|---|---|
| `src/Fa/Service/RecurringSchedule.php` | `SGW_Sales\GraphQL\RecurrenceParticipant` (a `SalesOrderParticipant`) |
| `RecurrenceType`, `RecurrenceInputType`, `RecurrenceRepeatsType` | `SGW_Sales\GraphQL\Type\...` |
| the `recurring` field on `SalesOrderType` and the order inputs | `typeFields` / `inputFields` of `SgwSalesExtension` |
| `SalesOrderService`'s recurrence hooks | calls to registered participants |
| recurrence tests (`SalesOrderRecurrenceTest`, `RecurrenceColumnsTest`, the recurrence parts of the HTTP flow) | `sgw_sales/tests/GraphQL/` |

`RecurrenceParticipant` writes `sales_recurring` with FrontAccounting's `db_query` on
FrontAccounting's connection, inside the order's transaction — not through
`SalesRecurringModel`, which uses its own PDO connection and would break atomicity.
That is a second write path to the table inside `sgw_sales`, documented beside the
model.

### 3.3 Client compatibility

Field names, types, nullability and behaviour of `recurring` are identical to Release 2
(Release 2 spec §4.5), pinned by a schema snapshot test in `sgw_sales`. One deliberate
change: with `sgw_sales` inactive for a company, `recurring` is absent from that
company's schema rather than reading `null` and refusing input with `BAD_INPUT`
(extensions are per company).

### 3.4 Activation

`hooks_sgw_sales::activate_extension()` applies `update_1.0.sql` only; it also applies
`update_1.4.sql` (each cut at its `# Upgrade helpers` line, as the stack does).

## 4. Recurring invoice generation

### 4.1 The hardened service (`sgw_sales`)

`RecurringInvoiceService`, used by both `sgw_sales`' page and the API:

- `due(\DateTimeInterface $asOf)`: the recurring orders due on `asOf` (from
  `GenerateRecurringModel`, restricted to sales orders — `trans_type = 30` — which its
  join does not do today).
- `generate(int $orderNo, \DateTimeInterface $invoiceDate): GeneratedInvoice` — in
  one FrontAccounting transaction:
  - refuse an order that is not due on `invoiceDate`, closed, or whose schedule has
    ended (`RecurrenceEnded`), or unknown (`RecurrenceNotFound`);
  - checks ported from Release 3: fiscal year for the date, exchange rate, customer on
    hold, negative stock;
  - deliver and invoice through FrontAccounting's `Cart`, with explicit dates (never
    `prepare_child`'s today) and quantities per period as today;
  - advance `dt_next` with `db_query` in the same transaction.

  A retry after success finds the order not due and bills nothing twice.
- Emailing is no longer inside `generate()`. The page emails through `rep107` after
  `generate()` as before; the API uses this module's `InvoiceMailer` (§4.2).

### 4.2 API (the extension)

- **`recurringDueList(asOf: Date): [RecurringDue!]!`** — `orderId`, `customerId`,
  `branchId`, `reference`, `customerRef`, `next`, `repeats`, `every`, `day`,
  `monthDay`, `end`. Hand-written Type (a join; no model to generate from). Area
  `SA_SALESTRANSVIEW`.
- **`recurringGenerate(input: [RecurringGenerateInput!]!): [RecurringGenerateResult!]!`**
  — input `{orderId: ID!, date: Date!, email: Boolean = false}`; result `{orderId,
  invoiceId, deliveryId, next, email: InvoiceEmailResult, error: {code, message}}`.
  Areas `SA_SALESDELIVERY` and `SA_SALESINVOICE`; emailing also as `invoiceEmail`.
  - **Items are independent**: each is its own `FaTransaction` under the document lock;
    a failed item reports `error` and the rest continue. This departs from the core's
    atomic batches (Release 2 spec §3.1) on purpose: a billing run over many orders
    must not be all-or-nothing.
  - Emails are sent after each item commits.
- The seeded "GraphQL Panel" role (machine tokens) is unchanged: it can list what is
  due only if it holds `SA_SALESTRANSVIEW` (it does) and cannot generate. A client that
  generates needs a role holding `SA_SALESDELIVERY` and `SA_SALESINVOICE`; granting
  them to the panel's production user is an operator decision.

### 4.3 Emailing for every company

`bin/fa-report` installs the target company's hooks before logging in, so the report
child logs in for any company where this module is active, not only where it is active
in the default company (Release 3 spec §6, the multi-company caveat).

## 5. Testing and CI

- **This module:** contract tests with a `FakeExtension` (registration, every loader
  rule, isolation of a throwing extension, participants rolling back the order's
  transaction, per-company activation); the schema without `sgw_sales` has no
  `recurring`; the stack keeps installing `sgw_sales`, so the HTTP suite covers
  recurrence and generation through the real extension.
- **`sgw_sales`:** service tests on its own stack (`docker/fa-sgw-sales`); extension
  tests in `tests/GraphQL/`, run inside this module's stack against the bind-mounted
  `sgw_sales` checkout (`SGW_SALES_PATH`) by a new `docker/fa-graphql test-extension
  sgw_sales`.
- **Both CIs run the extension tests**: this module's CI with `sgw_sales` pinned at a
  tag; `sgw_sales`' CI against this module's `main`.
- The matrix stays {upstream, fork} × {PHP 7.4, 8.3}.

## 6. Merge order

1. This module: the contract, loader and participants, with its own recurrence still
   in place.
2. `sgw_sales`: the extension, taking recurrence over; tagged.
3. This module: recurrence removed; `sgw_sales` pinned at that tag.
4. `sgw_sales`: the hardened service and generation; tagged. This module: the
   `fa-report` fix, the HTTP flow, docs; pin updated.

The panel sees no gap: at every step exactly one side serves `recurring`.

## 7. Build order

1. This module: contract, `ExtensionRegistry`, `ExtensionLoader`, `ExtensionContext`,
   `SalesOrderParticipant` wiring in `SalesOrderService`. Checkpoint A.
2. `sgw_sales`: extension skeleton, recurrence moved in, snapshot test,
   `activate_extension` fix. This module: recurrence removed, `test-extension`, CI
   pin. Checkpoint B (panel flow unchanged).
3. `sgw_sales`: hardened `RecurringInvoiceService`; page adapted; tests on its stack.
4. `sgw_sales` extension: `recurringDueList`, `recurringGenerate` with email. This
   module: `bin/fa-report` company fix. Checkpoint C.
5. HTTP flow (order → due → generate and email → paid); README and roadmap. Final
   checkpoint.
