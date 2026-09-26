# FrontAccounting GraphQL module — Release 3: Billing — design

Date: 2026-09-26
Status: design written autonomously at the user's request ("continue with a spec,
implementation plan, and implement subagentic autonomously"); every decision the
user did not make is listed in §9 as a ruling, so it can be reviewed and reversed.

Builds on Release 1 (`2026-09-21-foundation-design.md`) and Release 2
(`2026-09-25-release-2-panel-design.md`): the Slim pipeline, JWT sessions, one
company per request, output capture, upstream FrontAccounting with the fork
optional, the generated schema (`anorm-graphql`, generation wins), writes only
through FrontAccounting services in one `FaTransaction` per mutation call,
`ServiceCall::each` batches, fail-closed `FaModelType`, message levels and
`extensions.warnings`, explicit dates.

## 1. Purpose and scope

The panel bills its customers through FrontAccounting. Release 3 delivers:

1. **Deliveries** of sales orders — whole or partial — and voiding them.
2. **Invoices** from one or more deliveries, or from an order in one step (deliver
   what remains, then invoice it), and voiding them.
3. **Customer payments**, with allocations to invoices, and voiding them.
4. **Allocations**: reading them, and reallocating a payment.
5. **Emailing invoices** through FrontAccounting's own invoice report (`rep107`).
6. Reads: deliveries, invoices and payments with their lines, amounts outstanding,
   and a customer's balance.

**Decided by the user:** generating recurring invoices (`sgw_sales`) is Release 4.

### Non-goals

- Recurring invoice generation (Release 4).
- Credit notes, direct invoices without an order, prepayment (deposit) invoices,
  editing posted deliveries or invoices, supplier-side documents.
- Downloading the invoice PDF through the API (§6 leaves room for it).

## 2. Architecture

As Release 2: every entity is an Anorm model in `src/Model` with a generated Type
and Inputs; writes go through services in `src/Fa/Service`.

- **`debtor_trans`** has the key `(type, trans_no, debtor_no)`; the practical
  identity is `(type, trans_no)`. Each document kind is its own entity over the same
  table, scoped by `type` with `ModelType::scope()`, key property `id` = `trans_no`:
  `Delivery` (13), `Invoice` (10), `CustomerPayment` (12). Lines are
  `DeliveryLine` / `InvoiceLine` over `debtor_trans_details`, scoped by
  `debtor_trans_type`, generated `--readonly`. `Allocation` over `cust_allocations`
  is `--readonly`.
- **Writes** — generated `<entity>Create` and `<entity>Delete` — route to
  `DeliveryService`, `InvoiceService`, `CustomerPaymentService`. **Delete voids**
  (FrontAccounting's `void_transaction`), documented on the field, as order delete
  cancels. **Update** exists only where it is meaningful: `customerPaymentUpdate`
  changes a payment's allocations and nothing else; `Delivery` and `Invoice` have no
  update mutation (§7.1: `anorm-graphql` 0.3 `--without-update`).
- **Emailing** is a hand-written mutation, `invoiceEmail(id: [ID!]!)`, because it is
  an action with no generated shape (§6).

### 2.1 Document numbers

FrontAccounting numbers a document `MAX(trans_no)+1` per type without a lock, so
two concurrent writes can take the same number. Every document write in these
services first takes a MySQL named lock, `GET_LOCK('fa_graphql_docs_<company>',
10)`, held until the `FaTransaction` ends (released in its `finally`); failure to
get it within 10 s is `FaRejected` ("FrontAccounting is busy; try again").

### 2.2 Dates and fiscal years

Every document date is explicit in the input. Deliveries, invoices and payments are
checked with FrontAccounting's `is_date_in_fiscalyear()` (stricter than orders: the
current fiscal year, or any open year for a user with `SA_MULTIFISCALYEARS`; never
on or before the GL closing date). Refusals are `BAD_INPUT` on the date field.

### 2.3 Exchange rates

FrontAccounting reports a missing rate through `display_error` and then uses 1.0.
Every service asserts the rates it needs up front (customer currency on the
document date; for payments also the bank account's currency) and refuses with
`BAD_INPUT`.

## 3. Deliveries

`Delivery` (`debtor_trans` type 13). Areas: list `SA_SALESTRANSVIEW`, create
`SA_SALESDELIVERY`, delete `SA_VOIDTRANSACTION`.

**`deliveryCreate`** — input: `orderId`, `orderVersion` (the order's version, checked
under `FOR UPDATE` as order updates do), `date`, `reference` (default: next),
`dueDate` (invoice dead-line; default the order's delivery date), `locationId`,
`shipperId`, `freight`, `comments`, `lines: [{orderLineId, quantity}]` (default:
every line's remaining quantity), `closeOrder: Boolean = false` (FrontAccounting's
"cancel any quantity not delivered": the order's remaining quantities are closed).

Port of `customer_delivery.php`: `new Cart(ST_SALESORDER, [$orderId], true)`; refuse
nothing to deliver, a prepaid order without a deferred income account, an order not
released (prepayment not received), a customer on hold; `adjust_shipping_charge`;
date and reference; per line `0 <= quantity <= remaining`; something to deliver
(items or freight); negative stock through `check_qoh()` unless the company allows
it; exchange rate; `Cart::write(closeOrder ? 0 : 1)`. A delivery bumps the order's
`version` (counts toward its 255 limit, Release 2 §4.4).

**`deliveryDelete`** voids: refused once any quantity has been invoiced; otherwise
`void_transaction(ST_CUSTDELIVERY, ...)` with today's date, which restores the
order's delivered quantities and stock.

`DeliveryType` gets computed `lines` (`qtyInvoiced` from `qty_done`) and
`orderId`.

## 4. Invoices

`Invoice` (`debtor_trans` type 10). Areas: list `SA_SALESTRANSVIEW`, create
`SA_SALESINVOICE`, delete `SA_VOIDTRANSACTION`.

**`invoiceCreate`** — exactly one source per input item:

- `deliveryIds: [ID!]` — one or more deliveries of the same customer, branch and
  currency (checked; FrontAccounting's page assumes it). Port of
  `customer_invoice.php`: `new Cart(ST_CUSTDELIVERY, [...], true)`; per line
  `quantity` (default: remaining) `0 <= q <= remaining`; freight; something to
  invoice. `check_qoh()` is not called (the page doesn't).
- `orderId` + `orderVersion` — **invoice an order in one step**: deliver every
  remaining line (as `deliveryCreate` with defaults) and invoice that delivery, in
  one `FaTransaction`. This is what the panel uses.

Common: `date`, `reference` (default next), `dueDate` (default
`get_invoice_duedate(terms, date)` recomputed after the date is set — never
`prepare_child`'s today), `paymentTermsId` (optional; switching to cash terms is
refused — cash sales are out of scope), `comments`. Fiscal year, reference validity,
customer on hold, exchange rate. `Cart::write()` → `write_sales_invoice`, which also
moves payments allocated to the order onto the invoice (`reallocate_payments`).

**`invoiceDelete`** voids: refused while the invoice has allocations (deallocate the
payment first) or has been credited; otherwise `void_transaction(ST_SALESINVOICE,
...)`, which also voids an auto-created delivery and restores the delivery's
invoiced quantities.

`InvoiceType` gets computed `lines`, `total`, `outstanding` (`total - alloc`),
`deliveryIds`, `orderId`, `voided`.

## 5. Payments and allocations

`CustomerPayment` (`debtor_trans` type 12). Areas: list `SA_SALESTRANSVIEW`, create
`SA_SALESPAYMNT`, update (allocations) `SA_SALESALLOC`, delete `SA_VOIDTRANSACTION`.

**`customerPaymentCreate`** — input: `customerId`, `branchId` (optional when the
customer has no branch requirement, as the page allows), `bankAccountId`, `date`,
`reference` (default next), `amount` (customer currency, `> 0`), `discount`
(default 0), `bankAmount` (bank currency, default `amount` when the currencies
match; required otherwise), `charge` (bank currency, `>= 0`, `!= amount`; needs a
bank charge account), `memo`, `allocations: [{invoiceId, amount}]`.
Port of `customer_payments.php`: `write_customer_payment(...)` then an
`allocation` cart (`new allocation(ST_CUSTPAYMENT, $no, $customerId, PT_CUSTOMER)`)
with every item's `current_allocated` set explicitly — never FrontAccounting's
auto-allocation of the remainder — and `write()`. Allocation checks ported from
`check_allocations()` (which reads `$_POST`): each `>= 0`, each `<= ` the invoice's
outstanding, the invoice belongs to the customer, total `<= amount + discount +
allocation_settled_allowance()`. The bank account's lookup entity `BankAccount`
(`bank_accounts`, read-only, `SA_SALESPAYMNT` list) is added for the panel.

**`customerPaymentUpdate`** — only `allocations` may be given (any other field is
`BAD_INPUT`: "a posted payment cannot be changed; void it and enter it again"). The
payment's allocations are replaced by the list (an empty list deallocates it),
through the same cart with every item set explicitly.

**`customerPaymentDelete`** voids: `void_transaction(ST_CUSTPAYMENT, ...)`, which
clears its allocations; refused by FrontAccounting's bank-balance check when voiding
would overdraw the account.

`CustomerPaymentType` gets computed `allocations`, `unallocated`,
`bankAccountId`, `bankAmount`, `charge`. `Allocation` (read-only list,
`SA_SALESTRANSVIEW`) exposes `fromType`, `fromId`, `toType`, `toId`, `amount`,
`date`. `CustomerType` gets computed `balance` (FrontAccounting's
`get_customer_details` balance and overdue buckets).

**Exchange variations.** Clearing an allocation does not reverse FrontAccounting's
exchange-variation GL, so reallocating a foreign-currency payment can post
variations twice. `customerPaymentUpdate` is refused for a payment whose currency
differs from the company currency when it already has allocations (§9).

## 6. Emailing invoices

**`invoiceEmail(id: [ID!]!): [InvoiceEmailResult!]!`** — hand-written (an action,
no generated shape); area `SA_SALESTRANSVIEW` plus `SA_SALESINVOICE`. Result per
invoice: `id`, `sent: Boolean!`, `recipient: String`, `messages: [String!]!`.

FrontAccounting's invoice report cannot be included in the API process: `rep107.php`
includes `session.inc` (both upstream and the fork), which starts a session, logs in
from `$_POST`, installs `output_html` and may `exit`. **It runs in a PHP CLI child
process** (`bin/fa-report`), after the mutation's transaction has committed:

- The API starts `php bin/fa-report 107 <company> <login> <from> <to> email` with
  `proc_open`, a 60 s timeout, and no shell interpolation (argument array).
- The child: requires the module's autoloader; sets `VerifiedIdentity` for the
  company and login (so `hooks_graphql::authenticate` accepts the login without a
  password, exactly as the API's own session does); populates `$_POST` with
  FrontAccounting's login fields and the report parameters (`REP_ID`, `PARAM_0..7`);
  changes directory to `reporting/` (upstream's relative `$path_to_root`); includes
  `reporting/prn_redirect.php`'s target through FrontAccounting's
  `find_custom_file('/reporting/rep107.php')`, so a company's or extension's rep107
  override is used; and prints one JSON line with FrontAccounting's messages
  (captured from `$messages`) before exiting.
- The child is a CLI script: `.htaccess` denies `bin/`, it refuses to run under a
  web SAPI, and it takes no secrets. Anyone who can run it can already run PHP as
  the web user.
- Recipients are FrontAccounting's (the branch's or customer's `invoice` contact,
  else `general`), plus the company BCC. `sent` is true when FrontAccounting reports
  "has been sent by email"; otherwise `messages` carries why (for example "no email
  contact defined").
- `invoiceEmail` takes no lock and writes nothing itself; FrontAccounting's report
  may write a PDF to `company/N/pdf_files` and removes it after sending.

**Mail in the stack.** The docker stack adds a mail catcher: `sendmail_path` for
PHP (web and CLI) points at a script that writes each message to
`tmp/mail/<timestamp>-<n>.eml`; `docker/fa-graphql mail` lists them. Tests assert on
those files. Production uses the server's own `sendmail_path`.

## 7. `anorm-graphql` 0.3 (pre-approved: code, tag and push 0.3.x)

### 7.1 `--without-update <names>` and `--without-delete <names>`

With `--mutations create-update`, the named entities get no `<entity>Update` (or
`<entity>Delete`) mutation and no Update input (default: all generated). Used for
`Delivery` and `Invoice` (no update).

### 7.2 Anything the plan finds

Any other generator change the implementation needs (for example the raw-condition
scope deferred from Release 2) is made as a 0.3.x release rather than worked
around, under the same pre-approval.

## 8. Testing

- Fiscal years (Release 2's `db load`) cover today; the stack gains the mail
  catcher and `sendmail_path`.
- **Integration:** each service: create with defaults and with every override;
  every ported validation and guard; partial deliveries and remaining quantities;
  `closeOrder`; invoice from one delivery, from several (same branch; mixed refused),
  from an order in one step; due dates from payment terms; payment with and without
  allocations; over-allocation refused; reallocation and deallocation; foreign
  currency (rate missing refused; bank amount required); voids and their guards;
  GL balanced after each write (sum of `gl_trans` for the document is 0); the
  document-number lock (two concurrent creates get distinct numbers). Tests clean
  up everything they create (the Release 2 `FaOrderRows` pattern, extended to
  `debtor_trans`, `debtor_trans_details`, `gl_trans`, `stock_moves`,
  `bank_trans`, `cust_allocations`, `trans_tax_details`, `comments`, `refs`,
  `audit_trail`, `voided`).
- **Email:** `bin/fa-report` in a child process sends one message to the catcher
  with the invoice attached; a branch without an email contact returns
  `sent: false` with FrontAccounting's message; the web SAPI refusal.
- **HTTP:** the billing flow: order → `invoiceCreate(orderId)` → `invoiceEmail` →
  `customerPaymentCreate` with allocation → invoice `outstanding` 0 and customer
  `balance` 0 → `customerPaymentUpdate` deallocates → `customerPaymentDelete` voids
  → `invoiceDelete` voids.
- **Matrix:** {upstream, fork} × {PHP 7.4, 8.3}; `prepare_child` and `rep107` differ
  between upstream and the fork.

## 9. Rulings (decisions made autonomously)

Each is a decision the user delegated; each names what it costs if wrong.

1. **Scope:** credit notes, direct invoices without an order, prepayment invoices
   and editing posted documents are out. — Cost: the panel cannot credit or deposit
   through the API yet.
2. **One entity per document type over `debtor_trans`, scoped by `type`.** —
   Cost: none known; generation handles it with 0.2's `scope()`.
3. **Delete voids** (as order delete cancels), with FrontAccounting's void guards,
   plus: an invoice with allocations must be deallocated first. — Cost: a client
   must deallocate before voiding an invoice.
4. **No update** for deliveries and invoices (0.3 `--without-update`);
   `customerPaymentUpdate` changes allocations only. — Cost: corrections are void
   and re-enter.
5. **Invoice from an order in one step** is part of `invoiceCreate` (the panel's
   flow), besides invoicing deliveries. — Cost: one more input shape to validate.
6. **Cash-term invoices are refused** (FrontAccounting would create and allocate a
   payment automatically). — Cost: cash sales need a separate payment call.
7. **A named MySQL lock serialises document writes** per company (§2.1). — Cost:
   writes from the API queue behind each other (and wait up to 10 s).
8. **Explicit allocations only**; FrontAccounting's automatic allocation of a
   remainder is never used. — Cost: the client decides every allocation.
9. **Reallocating a foreign-currency payment that already has allocations is
   refused** (exchange-variation GL is not reversed by FrontAccounting). — Cost:
   such a payment must be voided and re-entered to change its allocations.
10. **Emailing runs `rep107` in a CLI child process** after commit, honouring
    report overrides, rather than porting `print_invoices()`. — Cost: a process per
    call and a CLI entry point to keep secure; failures surface as `sent: false`.
11. **The stack's mail catcher** writes `.eml` files; no mailpit container. —
    Cost: no web UI for mail in development.
12. **Area codes** (upstream `access_levels.inc`): `SA_SALESTRANSVIEW` 3073,
    `SA_SALESDELIVERY` 3076, `SA_SALESINVOICE` 3077, `SA_SALESPAYMNT` 3080,
    `SA_SALESALLOC` 3081, `SA_VOIDTRANSACTION` 769 (section `SS_SPEC` 768). The
    seed's GraphQL role gains them.

## 10. Build order

1. `anorm-graphql` 0.3.0: `--without-update`, `--without-delete`. Checkpoint A;
   tag and push (pre-approved).
2. Plumbing: the document-number lock, fiscal-year and exchange-rate helpers,
   `FaIncludes::billing()`, `void_transaction` wrapper, cleanup helpers for billing
   tables, seed areas, `BankAccount` lookup.
3. Deliveries: model, generation, `DeliveryService` (create, void).
4. Invoices: model, generation, `InvoiceService` (from deliveries, from an order,
   void). Checkpoint B.
5. Payments and allocations: models, generation, `CustomerPaymentService` (create
   with allocations, reallocate, void), `Allocation`, customer `balance`.
   Checkpoint C.
6. Emailing: `bin/fa-report`, `invoiceEmail`, the stack's mail catcher.
7. The billing flow over HTTP; README. Checkpoint D (final).
