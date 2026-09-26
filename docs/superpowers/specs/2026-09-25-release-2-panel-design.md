# FrontAccounting GraphQL module — Release 2: Panel — design

Date: 2026-09-25
Status: approved design, pre-implementation. Revised 2026-09-26 after Checkpoint B
(Tasks 3–6): each change is marked *(revised)* with its reason.

Builds on Release 1, `docs/superpowers/specs/2026-09-21-foundation-design.md`
("the Foundation spec"). Everything it establishes holds unless this document says
otherwise (it changes one thing: `SalesType` is listed with `SA_SALESORDER`, §4.2): the Slim pipeline, JWT sessions, one company per request, `Guard`,
`FaModelType`, output capture, upstream FrontAccounting `master` with the fork
optional, and CI on {upstream, fork} × {PHP 7.4, 8.3}.

## 1. Purpose and scope

The `saygoweb.com-my` hosting panel needs to set up customers and take recurring
orders through the API. Release 2 delivers:

1. **Reference lookups**, read-only: payment terms, tax groups, sales areas,
   salespeople, locations, shippers, credit statuses, currencies, stock items (and
   sales types, from Release 1).
2. **Customers, branches and contacts**: create, update, delete.
3. **Sales orders**: create, update, delete (FrontAccounting's cancel), read with
   their lines.
4. **Recurring schedules** on sales orders, when `sgw_sales` is active for the
   company.

**Deferred to Release 3:** generating recurring invoices and emailing them, with
invoices, customer payments and allocations. `rep107.php` cannot be included
headless (Foundation spec §1, "What Release 2 inherits"), so emailing needs its own
design.

**Decided with the user, not revisited here:**

- A wholly missed recurrence period is rare and should not happen; `sgw_sales`'
  scheduling (from today, not from `dt_next`) is kept as it is.
- The schema is defined by `anorm-graphql` generation. Where a hand design and the
  generated shape disagree, generation wins; what generation cannot yet express is
  added to `anorm-graphql` (§6), not worked around here.

### Non-goals

- Quotations, deliveries, invoices, payments, allocations. Release 3.
- Invoice generation and email. Release 3.
- Editing FrontAccounting setup data (the lookup tables are read-only).
- Customer attachments, dimensions, and GL-account overrides on branches (company
  defaults are used, as the branch page does).

## 2. Architecture

**Reads are generated; writes are generated schema over hand-written FrontAccounting
services.**

- Every entity is an Anorm model in `src/Model`, made by `anorm make` and renamed to
  the Foundation spec §4.4 conventions, and gets its Type, Input(s), tests and
  `ApiSchema` entries from `anorm-graphql` (`bin/generate`).
- Lookups are generated `--readonly`.
- Writable entities (`Customer`, `Branch`, `Contact`, `SalesOrder`) are generated
  with `--mutations create-update` (§6.1). Their once-only Types route
  `resolveCreate`, `resolveUpdate` and `resolveDelete` to a service in
  `src/Fa/Service`, which calls FrontAccounting's own functions. **No generated
  write reaches a FrontAccounting table directly** (§2.2).
- Approaches rejected: driving FrontAccounting's pages headless with a synthetic
  `$_POST` (they render UI and read the session Foundation strips); writing the
  tables through Anorm models (it bypasses references, the audit trail, hooks,
  pricing and stock logic).

### 2.1 Services

`src/Fa/Service` holds the only code that calls FrontAccounting write functions:

| Service | FrontAccounting functions |
|---|---|
| `CustomerService` | `add_customer`, `update_customer`, `update_record_status`, `delete_customer`; on create, `add_branch`, `add_crm_person`, `add_crm_contact` per `auto_create_branch` |
| `BranchService` | `add_branch`, `update_branch`, `update_record_status`, `delete_branch`; `add_crm_person` / `add_crm_contact` for the branch contact |
| `ContactService` | `add_crm_person`, `update_crm_person`, `update_record_status` *(revised)*, `add_crm_contact`, `delete_crm_contacts`, `delete_crm_person` |
| `SalesOrderService` | `Cart`, `get_customer_details_to_order`, `add_to_cart`, `Cart::write`, `delete_sales_order`, `close_sales_order`, `get_kit_price` |
| `RecurringSchedule` | `db_query` on `sales_recurring`, inside the order's transaction |

Each service method:

1. Validates its input, porting the page's checks (`can_process()` and the line
   checks), and throws `BadInput` naming the field and, in a batch, the list index.
2. Runs inside one `FaTransaction` (§3.1) for the whole mutation call.
3. Converts `Date` values (ISO `YYYY-MM-DD`) to FrontAccounting's user date format
   before calling it, since its functions call `date2sql()` themselves, and back
   on read.
4. Casts the numeric fields FrontAccounting puts into SQL unquoted
   (`add_customer`'s `$discount`, `$pymt_discount`, `$credit_limit`, and the like).
5. Turns FrontAccounting's collected errors into `FaRejected` (§3.2).

The services need a logged-in `current_user` (for `user_pos()`, the audit trail and
references): the session gate provides it, so writes require a token, never
anonymous.

*(revised)* Two helpers were added in Task 5 that this section did not foresee.
`Bootstrap::includeFa($path)` includes one more FrontAccounting file after boot, as
though from file scope: the write functions (`sales_db.inc`, `crm_contacts_db.inc`,
...) are included only by a request that writes. `FaModelType` gains
`intId()`/`intIds()` — a client ID for an integer key must be a canonical positive
whole number, since MySQL casts `"5 anything"` to 5 — and `rowsById()`, which reads
a write's rows back through `resolveList` so `scope()` applies. The integer check is
shared as `IntKey::parse()`: every integer-keyed reference a service receives
(`customerId`, `salesTypeId`, `salesmanId`, ...) passes it before its existence
check, so `"1 x"` is `BAD_INPUT` naming the field, not an `INTERNAL` strict-mode
error (Checkpoint B review M-1). `rowsById()` is authorised by the write's own verb
— create, edit or delete — never the list area: a write's read-back of its own rows
is authorised by the write's area (the ledger's ruling; review M-3), so a role that
may write sales orders but not list them is not refused after its order committed.

*(revised)* `ContactService` also calls `update_record_status` (for `inactive`, as
the customer and branch services do), and when a contact update replaces its links
it calls `update_crm_person` with a `$type` that matches no link (`KEEP_LINKS`), so
FrontAccounting's `update_person_contacts()` leaves the links alone and the service
replaces only the customer and branch links itself (Checkpoint D review M-4 item 1).

### 2.2 Writes fail closed

`FaModelType` implements `resolveCreate`, `resolveUpdate`, `resolveDelete` (and
`resolveUpsert`) to throw `Forbidden` ("this entity is written through
FrontAccounting; no write path is declared"). A writable Type overrides them to call
its service. A generated Type whose override is forgotten therefore refuses writes
instead of writing the table, the same way a missing `areas()` refuses to load.

### 2.3 The one-side rule

Every write in these mutations goes through FrontAccounting's mysqli connection —
the recurring row included — and nothing through the container's PDO. Reads may use
either. This keeps order, lines and schedule in one transaction, and it keeps the
Foundation spec §4.3 rule without exception.

## 3. FrontAccounting write plumbing

### 3.1 `FaTransaction`

`FaTransaction::run(callable $work)`: `begin_transaction()`, the work,
`commit_transaction()`; on any throwable, `cancel_transaction()` and rethrow.
FrontAccounting's `check_db_error` path rolls back without resetting
`$transaction_level`; `cancel_transaction()` resets it, so a later write in the same
request still issues `BEGIN`. FrontAccounting's own functions nest inside it
(`Cart::write` and `add_crm_person` call `begin_transaction()` themselves; only the
outermost level issues `BEGIN`/`COMMIT`).

A batch (`customerCreate(input: [...])`) is one `FaTransaction`: any refusal rolls
back every item, and the error names the index. *(revised)* "Any refusal" is every
`BAD_INPUT`, `FA_REJECTED` and `NOT_FOUND` an item raises — the services' own guards
and missing rows as well as FrontAccounting's messages (Checkpoint B review I-1: the
guards' refusals carried no index). A delete's read of its rows before it deletes
names the index of a missing id the same way.

### 3.2 Messages, errors and warnings

`FaMessages` keeps each message's level (the Foundation carry-over): `display_error`
(`E_USER_ERROR`), `display_warning` (`E_USER_WARNING`), `display_notification`
(`E_USER_NOTICE`).

- After a service call, any collected **error** becomes `FaRejected` carrying the
  messages in `extensions.messages` (the transaction is rolled back).
- **Warnings** do not fail the mutation; FrontAccounting's page aborts on any
  message, but an API returns them. They travel in the response's top-level
  `extensions.warnings: [String!]`, because the generated mutations return
  `[<Entity>Type!]!`, with no room for a payload. `GraphQLAction` adds it when
  non-empty.
- Notifications ("The customer has been added") are discarded.

### 3.3 `sgw_sales` detection

`FaSession::isActive(string $package): bool` is true when the extension's hooks are
installed for the current company (`isset($Hooks[$package])` after
`install_hooks()`). `RecurringSchedule` also requires the `sales_recurring` table to
have its 1.4 shape (`id`, unique `trans_no`); a missing table or column is
`FaRejected` with a message naming the missing upgrade (`update_1.4.sql`).
*(revised)* Tested without DDL (which would commit): the shape check reads
`information_schema` under the company's table prefix, and
`SalesOrderRecurrenceTest` points the company at a prefix with no
`sales_recurring` (Checkpoint D review M-2 item 3).

## 4. Schema

### 4.1 Generated conventions

Per entity, from `anorm-graphql` with `--mutations create-update` (§6.1):

```graphql
<entity>List(query: MangoInput): [<Entity>Type!]!
<entity>Create(input: [<Entity>CreateInput!]!): [<Entity>Type!]!
<entity>Update(input: [<Entity>UpdateInput!]!): [<Entity>Type!]!
<entity>Delete(id: [ID!]!): [<Entity>Type!]!
```

- `<Entity>CreateInput`: the key omitted; columns that are `NOT NULL` without a
  default are non-null.
- `<Entity>UpdateInput`: the key non-null, every other field optional; an omitted
  field is left unchanged, an explicit `null` is a value.
- Field names and types come from the models (Foundation spec §4.4: camelCase
  domain names; a foreign key ends in `Id` so it is an `ID`). *(revised)* The
  converse holds too: a column that is not a reference does not end in `Id`, or the
  generator types it `ID` — the customer's tax registration number (`tax_id`) is
  `taxNumber: String` (Checkpoint B review M-2). **Every model's key
  property is `id`**, mapped to its key column (`debtor_no`, `branch_code`,
  `order_no`, `stock_id`, ...), so every entity is addressed the same way; the
  tables below name the column. Dates are the `Date`
  scalar (§6.3).
- Once-only Inputs add fields through `fields()`; once-only Types add computed
  fields through `fields()`.

### 4.2 Lookups (generated `--readonly`)

| Entity | Table | Key | List area |
|---|---|---|---|
| `PaymentTerms` | `payment_terms` | `terms_indicator` | `SA_SALESORDER` |
| `TaxGroup` | `tax_groups` | `id` | `SA_SALESORDER` |
| `SalesArea` | `areas` | `area_code` | `SA_SALESORDER` |
| `Salesman` | `salesman` | `salesman_code` | `SA_SALESORDER` |
| `Location` | `locations` | `loc_code` (string) | `SA_SALESORDER` |
| `Shipper` | `shippers` | `shipper_id` | `SA_SALESORDER` |
| `CreditStatus` | `credit_status` | `id` | `SA_SALESORDER` |
| `Currency` | `currencies` | `curr_abrev` (string) | `SA_SALESORDER` |
| `StockItem` | `stock_master` | `stock_id` (string) | `SA_SALESORDER` |
| `SalesType` | `sales_types` | `id` | `SA_SALESORDER` *(changed from `SA_SALESTYPES`)* |

A role that takes orders reads what an order needs, without FrontAccounting's setup
areas (`SA_PAYTERMS`, `SA_CURRENCY`, ...), which grant editing in the web UI. The
seed role changes to match. `StockItem` exposes `inactive`, `noSale` and `mbFlag`
so a client can filter sellable items (`mbFlag != 'F'`, not inactive, not
`noSale`) with a Mango selector.

### 4.3 Customers, branches, contacts

| Entity | Table | Key | Areas |
|---|---|---|---|
| `Customer` | `debtors_master` | `debtor_no` | all verbs `SA_CUSTOMER` |
| `Branch` | `cust_branch` | `branch_code` | all verbs `SA_CUSTOMER` |
| `Contact` | `crm_persons` | `id` | all verbs `SA_CUSTOMER` |

- **`customerCreate`**: `add_customer` with the page's validation (name and ref
  non-empty, `creditLimit >= 0`, both discounts 0–100 in the API and stored as
  fractions, a unique ref). The currency defaults to the company currency, the
  credit limit to `default_credit_limit()`. `salesTypeId`, `paymentTermsId` and
  `creditStatusId` are required, because `get_customer_to_order` inner-joins them.
  The once-only `CustomerCreateInput` adds:
  - `branch: BranchDefaultsInput` (`salesmanId`, `salesAreaId`, `taxGroupId`,
    `locationId`, `shipperId`) — required when `auto_create_branch` is on, then a
    branch is created as the page does (name, ref and address from the customer; GL
    accounts from company preferences). *(revised)* `locationId`, not
    `locationCode`, and the customer's currency is `currencyId`, not `currency`:
    the generated names win, since the Foundation spec §4.4 convention names every
    reference `…Id` and `BranchDefaultsInput` reuses the Branch model's names.
    When `auto_create_branch` is off, `branch` and `contact` are refused: the
    customer is written alone, and branches and contacts are added with
    `branchCreate`/`contactCreate`;
  - `contact: ContactDetailsInput` (`phone`, `phone2`, `fax`, `email`) — the CRM
    person the page creates, linked to the customer and the branch.
  The created branch and contact are readable through `branches` and `contacts`
  computed fields on `CustomerType`.
- **`customerUpdate`**: `update_customer` with omitted fields kept; `inactive`
  through `update_record_status`. A currency change is refused once the customer
  has transactions or sales orders.
- **`customerDelete`**: refused while the customer has transactions, sales orders
  or branches (the page's guards); then `delete_customer`.
- **`branchCreate` / `branchUpdate` / `branchDelete`**: as the branch page —
  `name` and `ref` non-empty; salesman, area, tax group, location and shipper
  required on create; GL accounts from company preferences; an optional `contact`
  creates the branch's CRM person; delete refused while the branch has
  transactions or orders. *(revised)* Every new branch gets its CRM person, as the
  page makes one. The page names a customer's first branch's person "Main Branch"
  and leaves a later branch's blank; a blank-named person is no use to a client, so
  a later branch's person, when `contact.name` is not given, is named after the
  branch.
- **`contactCreate` / `contactUpdate` / `contactDelete`**: CRM persons. The
  once-only Inputs add `links: [ContactLinkInput!]` (`entity: CUSTOMER | BRANCH`,
  `id`, `category: GENERAL | ORDER | DELIVERY | INVOICE`); on update, `links`
  replaces the set when given. `ContactType` gets a computed `links` field.
  *(revised)* **A `Contact` is a customer's or a branch's contact**: a CRM person
  with at least one `crm_contacts` link of type `customer` or `cust_branch` in one
  of the four system categories — the links this API models. FrontAccounting's
  CRM persons are shared with suppliers (and custom categories), whose pages guard
  them with `SA_SUPPLIER`; `SA_CUSTOMER` must not reach them (Checkpoint B review
  C-1, reproduced with the demo's supplier contacts). So, as FrontAccounting's own
  CRM editor (`includes/ui/contacts_view.inc`) confines itself to its class:
  - `contactList`, `CustomerType.contacts` and every read-back list only persons
    with such a link;
  - `contactUpdate` and `contactDelete` of a person without one are `NOT_FOUND`;
  - `links` on update replaces only the customer and branch links in the modelled
    categories; a supplier's link, or a custom category's, is kept;
  - `contactDelete` removes the person's customer and branch links, then deletes
    the person only when no link at all is left (the editor's `db_delete()`); a
    person who is still, say, a supplier's contact is kept, with that link. The
    returned rows are read before the delete; their computed `links`, resolved
    after it, are empty.
  `links` shows only the modelled links, and a create or update may give only the
  four categories. The person's own fields (name, phone, ...) are shared: an update
  changes them for every link, as the editor does. The review's M-5 is kept in
  passing: the person is read with its own `SELECT`, not `get_crm_person()`, which
  auto-vivifies `false` into an array (deprecated in PHP 8.1).

### 4.4 Sales orders

`SalesOrder` is `sales_orders` scoped to `transType = 30` (`ModelType::scope()` in
the once-only Type), key column `order_no`. Reading needs `SA_SALESTRANSVIEW`; create,
update and delete need `SA_SALESORDER`.

- **Lines.** `SalesOrderLine` (`sales_order_details`) is generated `--readonly` for
  its Type and `--input-only` (§6.2) for its `SalesOrderLineCreateInput` /
  `SalesOrderLineUpdateInput`. `SalesOrderType` gets a computed `lines` field
  (`qtyDelivered` from `qty_sent`, `qtyInvoiced` from `invoiced`); the order
  Inputs get `lines`. *(revised)* An order's location field is `locationId`, like
  every other reference (§4.3 records this for branches, not for orders; Checkpoint
  C review M-3 item 6).
  *(revised)* FrontAccounting 2.4's `add_sales_order()` never writes
  `sales_orders.contact_email`, so `email` is dropped from `SalesOrderCreateInput`
  and `SalesOrderUpdateInput` (`SERVER_SET`): a field this API never writes would
  mislead a client who set it (Checkpoint C review M-3 item 1). Only `phone`
  travels.
- **`salesOrderCreate`**: `new Cart(ST_SALESORDER, 0)`, the document date set
  explicitly from `orderDate` (never `new_doc_date()`), then
  `get_customer_details_to_order`, then the input's overrides (price list, payment
  terms, location, shipper, delivery details, due date defaulting to `orderDate`
  plus `default_delivery_required_by`), the reference (`$Refs->get_next` unless
  given, then `$Refs->is_valid`), lines, `Cart::write(1)`. Validation ports
  `can_process()` and `check_item_data()`: at least one line; delivery details
  and `deliveryDate >= orderDate` unless cash-sale terms; `0 < prepaymentAmount <=
  total` for prepaid terms; currency rates for the order date; quantities `>= 0`,
  discount 0–100, price `>= 0` for stock items; the item exists. A line's price
  defaults to `get_kit_price()` for the price list; a kit expands into its
  components as `add_to_order` does (ported: the original reads `$_POST`).
  *(revised)* On cash-sale terms FrontAccounting ignores the delivery details
  entirely — `deliveryDate`, `customerRef`, `deliverTo`, `deliveryAddress`,
  `phone`, `shipperId` and `prepaymentAmount` — not only the `deliveryDate >=
  orderDate` check named above, and so does the API: `copy_to_cart()` takes the
  point of sale's location instead and skips every one of these fields (Checkpoint
  C review M-3 item 2).
- **`salesOrderUpdate`**: `version` is required (the once-only
  `SalesOrderUpdateInput` makes it non-null); the service locks the order row
  (`SELECT ... FOR UPDATE`) and refuses a mismatch with `FaRejected` ("the order
  was changed by someone else; read it again"). Then `new Cart(ST_SALESORDER,
  <id>)`, the patch applied, `Cart::write()`; the new version is read back
  (`write()` returns nothing on update). `lines`, when given, replaces the set:
  lines with an `id` update, lines without add, omitted lines are deleted. Refused:
  deleting a delivered line, a quantity below `qtyDelivered`, an empty line list,
  and — once any line is delivered or invoiced — changing the customer, branch,
  price list, order date, payment terms or prepayment. For a **recurring order**
  (one with a schedule) the header stays editable and the quantity floor does not
  apply, as `sgw_sales` does: each generated invoice raises `qtyDelivered`.
  *(revised)* `sales_orders.version` is `tinyint unsigned`: FrontAccounting
  connects with `sql_mode = STRICT_ALL_TABLES`, so the column holds at most 255
  changes to one order, deliveries included. *(revised)* The service refuses a
  write once the stored version is already 255 — the 256th write, the first that
  could not be stored — with `FA_REJECTED` ("this order has reached FrontAccounting's edit
  limit of 255 versions; create a new order") before attempting it, in place of
  the `INTERNAL` "out of range" error FrontAccounting's own strict mode would
  otherwise raise, which would also leave the order permanently unable to be
  edited again (Checkpoint C review M-1).
  *(revised)* Two more of the page's guards apply to both update and delete,
  before anything is written (`sales_order_entry.php` :104-112 and
  `check_is_editable()`, `includes/data_checks.inc` :646-659): an open prepaid
  order (invoices or payments against prepayment terms) is `FA_REJECTED`; and an
  order another user created, when the caller lacks `SA_EDITOTHERSTRANS`, is
  `FORBIDDEN` (Checkpoint D review M-4 item 2).
- **`salesOrderDelete`**: FrontAccounting's cancel, documented on the field. An
  order with no deliveries is deleted (`delete_sales_order`), its schedule deleted
  in the same transaction; an order with deliveries is closed (`close_sales_order`:
  quantities set to what was delivered) and its schedule gets `end = today` when
  that is earlier than its end. Returns the orders as they were.
  *(revised)* The version is not checked on delete: the generated
  `salesOrderDelete(id: [ID!]!)` takes only ids (generation wins, §1). The order row
  is locked (`SELECT … FOR UPDATE`) inside the transaction, so the deliveries check
  and the delete or close are one step. An order that is closed rather than deleted
  is reported in `extensions.warnings`.
- *(revised)* **`salesOrderLineList(query: MangoInput): [SalesOrderLineType!]!`**
  exists: the generated read-only `SalesOrderLine` Type's list, read with
  `SA_SALESTRANSVIEW` and scoped to `trans_type = 30`, like the orders it belongs
  to. It has no mutations; lines are written through their order (Checkpoint D
  review M-4 item 4).

### 4.5 Recurrence

When `FaSession::isActive('sgw_sales')`:

- The order Inputs get `recurring: RecurrenceInput` — `start: Date!`, `end: Date`,
  `repeats: MONTH | YEAR!`, `every: Int!` (`>= 1`), `day: Int` (1–31, monthly),
  `monthDay: String` (`MM-DD`, yearly), `auto: Boolean = true` — mapped onto
  `sales_recurring` (`dt_start`, `dt_end`, `repeats`, `every`, `occur`, `auto`).
  On update, `recurring` given sets or changes the schedule; to end one, set `end`.
  *(revised)* `every` is `>= 1` **and `<= 127`**: `sales_recurring.every` is
  `tinyint(4)`, so 128 and above is `BAD_INPUT`, not FrontAccounting's own error
  (Checkpoint C review M-3 item 5).
- `SalesOrderType` gets `recurring: Recurrence` (`start`, `end`, `next`, `repeats`,
  `every`, `day`, `monthDay`, `auto`), null when there is none. *(revised)* `next`
  is never set from an input: it is `sgw_sales`' own state (`dt_next`, when the
  next invoice is due), computed by `sgw_sales` itself and read back unchanged
  here. It is kept across an update unless the rhythm changes — `start`, `repeats`,
  `every` or `day`/`monthDay` — matching `sgw_sales`' own page, which recomputes
  `next` only then (Checkpoint C review M-3 item 4).
- `RecurringSchedule` writes the row with `db_query` inside the order's
  `FaTransaction`, so order and schedule commit or roll back together. It is not
  `sgw_sales`' `SalesRecurringModel`, which writes on its own PDO connection outside
  the transaction.

When `sgw_sales` is not active, `recurring` in an input is `BadInput` ("recurring
orders need the sgw_sales extension"), and `recurring` reads null. The schema is the
same either way. *(revised)* The two relaxations `salesOrderUpdate` grants a
recurring order (§4.4: the header stays editable, and the quantity floor does not
apply) follow `isRecurringOrder()`, which in turn follows `read()`: with `sgw_sales`
inactive, an order that still has a `sales_recurring` row loses both relaxations,
the same as one with no schedule at all, because `read()` returns null and
`recurring` on the Type reads null too. Nothing is corrupted — `delete()`/`end()`
still act on the row whenever the table has its 1.4 shape — but a long-running
recurring order whose invoicing quantities were raised by `sgw_sales` can no longer
have a line re-sent at its original quantity while the extension is off; the client
has to omit `lines` (Checkpoint C review M-3 item 7, and the judgement in the
Checkpoint C review).

## 5. Errors

| Code | When |
|---|---|
| `BAD_INPUT` | A field fails this module's validation; `extensions.field` names it, and `extensions.index` the batch item |
| `NOT_FOUND` | An update or delete names a row that does not exist (in the order's scope, for orders; in the Contact scope, §4.3, for contacts); `extensions.index` the batch item *(revised)* |
| `FA_REJECTED` | FrontAccounting refused (its messages in `extensions.messages`), a stale order version, a guard (customer with orders), `sgw_sales`' table not upgraded; `extensions.index` the batch item. *(revised)* A guard of this module's own carries its message in `extensions.messages` too, so a client reads `messages` the same way for every refusal (Checkpoint B review M-4) |
| `FORBIDDEN` | `Guard` refused; or a write reached a Type with no declared write path (§2.2); or *(revised)* a sales-order update or delete names an order another user created and the caller lacks `SA_EDITOTHERSTRANS` (§4.4) |

`extensions.warnings` (top level) carries FrontAccounting's warnings when the
mutation succeeded.

## 6. `anorm-graphql` 0.2

Co-developed as `--type-base` was: each change in the `anorm-graphql` repository
with its own tests, a checkpoint review, then tag `v0.2.0` on the user's OK to push.
Default behaviour and output stay byte-identical.

### 6.1 `--mutations upsert|create-update`

Default `upsert` (today's output). With `create-update`, a writable entity gets
`<entity>Create(input: [<Entity>CreateInput!]!)` and
`<entity>Update(input: [<Entity>UpdateInput!]!)` in place of `<entity>Upsert`, with
the Inputs of §4.1 (generation-gap: `Base/<Entity>CreateInputBase` regenerated,
`<Entity>CreateInput` once-only with the `fields()` hook; likewise Update).
`ModelType` gains `resolveCreate` and `resolveUpdate` (a create with a key, or an
update of a missing key, is a client-safe error). Generated tests exercise create
and update. Nullability of the Create input comes from the model: a property with
no PHP default whose column is `NOT NULL` is non-null — the model states it (for
example with a `@required` docblock tag); the generator does not read the database.

### 6.2 `--input-only <names>`

For the named entities, generate only the Input(s) (`Create`/`Update` under
`create-update`), with no Type mutations and no `ApiSchema` entries, alongside a
`--readonly` Type. Used for `SalesOrderLine`.

### 6.3 `Date` scalar

0.1 maps every declared type other than `int`, `float` and `bool` to `String`, so
dates are strings. 0.2 maps a property declared `\DateTimeInterface` (or a subtype)
to a `Date` scalar shipped in the runtime: ISO `YYYY-MM-DD` out and in, invalid
input a client-safe error. Datetime columns stay `String` unless a later release
needs them. The scalar is one shared instance,
`\Anorm\GraphQL\Type\DateType::instance()`: a schema may hold only one type named
`Date`, and generated Types build their fields without the container, so it is never
registered in the container. Hand-written date fields (the `recurring` fields, line
dates) use the same instance.

## 7. Testing and test data

- **Fiscal years.** `docker/fa-graphql db load` adds fiscal years up to the current
  year after the dataset, as `sgw_sales`' stack does (`ensure_fiscal_year`).
- **Unit.** `FaTransaction` (cancel on throw, nesting), `FaMessages` levels,
  `extensions.warnings`, the date conversion, `FaModelType`'s fail-closed writes,
  the recurrence mapping (`day`/`monthDay` to `occur` and back).
- **Integration** (FrontAccounting in-process):
  - each service's create, update and delete, with every validation ported from
    the pages, and the guards;
  - customer create with and without `auto_create_branch`;
  - orders: defaults from customer and branch, price defaults, kits expanded,
    reference, a stale version refused, delivered-line and frozen-header rules
    (a delivery written in the test with FrontAccounting's own functions),
    delete versus close;
  - a mid-batch refusal rolls the whole batch back, and a following write in the
    same request still commits (`$transaction_level` reset);
  - recurrence written and rolled back with its order, deleted with a deleted
    order, ended with a closed one, the recurring relaxations, and `BadInput`
    when `sgw_sales` is inactive;
  - `ModelTypeTestCase` for every generated entity, its writes going through the
    overridden resolvers.
- **HTTP.** The panel's flow: create a customer with its branch and contact, create
  an order with lines and a recurrence, read it back with `lines` and `recurring`,
  update it with its version, a stale update refused, delete it (deleted, and a
  delivered one closed). Lookups listed by a role holding only the order areas.
- **Matrix.** Every suite on {upstream, fork} × {PHP 7.4, 8.3}: `Cart` differs
  between them (Foundation spec §1).

## 8. Build order

Each step leaves CI green.

1. `anorm-graphql` 0.2: §6.1–6.3; checkpoint; tag `v0.2.0` on the user's OK.
2. Write plumbing: `FaTransaction`, `FaMessages` levels, `extensions.warnings`, date
   conversion, `FaModelType`'s fail-closed writes, `FaSession::isActive`.
3. Lookups, generated `--readonly`; `SalesType` to `SA_SALESORDER`; seed.
4. Customers, branches, contacts: models, generation, services. Checkpoint.
5. Sales orders: model and scope, lines, `SalesOrderService`, kits, version.
6. Recurrence: `RecurringSchedule`, the `recurring` fields. Checkpoint.
7. The HTTP flow, fiscal years in the stack, README. Final checkpoint.
