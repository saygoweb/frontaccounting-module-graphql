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

*(revised)* As built (final review M-3): the generated `deliveryDelete`,
`invoiceDelete` and `customerPaymentDelete` carry no description (introspection gives
none, as for Release 2's `salesOrderDelete`); that delete voids is documented in the
README and in the Type classes' docblocks instead of on the field.

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

*(revised)* As built (final review M-3): `orderId` is a stored field (the
`debtor_trans.order_` column), not computed; `lines` is computed as above; and a
computed `voided` was added (whether `deliveryDelete` has voided the delivery; its row
stays).

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

*(revised)* As built (Release 3 Task 4):

- **The one-step path delivers with reference `'auto'`**, as FrontAccounting's own
  direct invoice does (`sales/includes/cart_class.inc`, `write()`), through
  `DeliveryService::create($input, autoReference: true)` — a PHP parameter; a
  client's `reference: "auto"` is refused. An `'auto'` reference is never saved to
  `refs` (`includes/references.inc` :358-361). So `invoiceDelete` of a one-step
  invoice voids its delivery too (`void_sales_invoice()`,
  `sales_invoice_db.inc` :249-256: one parent delivery whose reference is `auto`),
  and the order is back as it was.
- **Default freight: each delivery adds its `ov_freight` only while none of its
  lines has been invoiced** (`SUM(qty_done) = 0`, read in the invoice's
  transaction), so a delivery invoiced in parts charges its freight once
  (Checkpoint B I-1). This rule is our own. The page pre-fills the first
  delivery's freight (`read_sales_trans()`), or for a batch the sum when the
  company's `accumulate_shipping` is on (`set_delivery_shipping_sum()`,
  `customer_invoice.php` :230-241, called at :606-608); when its field is empty it
  charges nothing once any line has been invoiced (`any_already_delivered()`,
  :589-599); and a person sees and corrects the value. An API default nobody sees
  sums per delivery, whatever `accumulate_shipping` says, and skips a delivery
  already partly billed. A given `freight` (not negative) wins on the deliveries
  path; on the one-step path it is the delivery's freight, and so the invoice's.
- **Per-line quantities are a hand-written input**,
  `lines: [InvoiceLineQuantityInput!]` with `InvoiceLineQuantityInput {
  deliveryLineId: ID!, quantity: Float! }`: no generated Input fits "which
  delivery line, how much" (Release 2 spec §1 allows a hand-written shape where
  generation cannot express one). Lines not named are invoiced in full. `lines` is
  refused on the one-step path, which invoices everything remaining.
- **"Same currency" is covered by "same customer"**: FrontAccounting ties a currency
  to the customer account (`debtors_master.curr_code`), so the check is same
  customer and branch.
- **Prepaid orders are refused** (prepayment invoices are a non-goal, §1), and so
  are cash-sale terms (ruling 6) and prepayment terms (`days_before_due = -1`),
  whether the terms come from the delivery or are given as `paymentTermsId`.
- The inputs also carry `shipperId` and `freight`; `customerId`, `branchId`, the
  price list, amounts, rate and `taxIncluded` come from the deliveries. There is
  **no `invoiceUpdate`** (generated `--without-update`): a posted invoice is voided
  and entered again. Computed fields as built: `lines`, `total` (FrontAccounting's
  Total: items + tax + freight + freight tax + discount), `outstanding`,
  `deliveryIds` (the deliveries its lines' `src_id` point at), `voided`; `orderId`
  is a stored column (`order_`), not computed.

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

*(revised)* As built (Release 3 Task 5, Checkpoint C):

- **`bankAmount` in one currency.** When the customer's currency is the bank
  account's, `bankAmount` may be omitted (it is `amount`) or given equal to `amount`
  (compared at `user_price_dec()`); any other value is `BAD_INPUT` on `bankAmount`
  (with the batch item's index), and nothing is written. FrontAccounting's page
  shows `bank_amount` only when the currencies differ (`customer_payments.php`
  :363-366) and otherwise posts the amount (:246); a different bank amount would book
  the gap to the exchange-variation account on a payment with no exchange
  (Checkpoint C I-1). When the currencies differ, `bankAmount` stays required.
- **Reallocation replaces invoice allocations only.** The API allocates to invoices
  (`trans_type_to` 10). A payment's allocations to anything else — a sales-order
  prepayment, a journal, a bank payment, made in FrontAccounting's UI — are kept at
  their current amounts by `customerPaymentUpdate` (an empty list removes the invoice
  allocations and keeps those), and they count towards the total checked against
  `amount + discount + allocation_settled_allowance()` (Checkpoint C M-1). Voiding
  the payment still clears every allocation, as FrontAccounting does.
- **`CustomerPayment` computed fields as built:** `allocations`, `unallocated`,
  `bankAccountId`, `bankAmount`, `charge`, and also `memo` (the payment's
  `comments` memo, or null) and `voided` (a voided payment's row stays, its amounts
  zeroed). `customerPaymentDelete` returns each payment as it was before the void,
  read under the document lock.
- **`CustomerType.balance`** is FrontAccounting's `get_customer_details(id, null,
  false)` — `$all = false`, as `customer_inquiry.php` shows it; an unallocated
  payment counts as negative. That call returns no row for a customer with nothing
  unallocated (its WHERE drops the LEFT JOIN's null row), so `balance` is then zeros
  in the customer's currency; it is null only when the `$all = true` call has no row
  either (payment terms or credit status missing). `balance` needs
  `SA_SALESTRANSVIEW` besides the customer's own `SA_CUSTOMER`; without it the field
  is null with a `FORBIDDEN` error while the rest of the customer is read.

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

*(revised)* As built (Release 3 Task 6, final review M-1 and M-2):

- **The catcher** (`docker/fa-mail-catcher`) writes each message to
  `/var/mail-catcher/<timestamp>-<pid>-<random>.eml` inside the app container, not
  `tmp/mail/`; `docker/fa-graphql mail [list|show <f>|clear]` reads that directory.
- **The child's arguments** are `bin/fa-report 107 <company> <login> <invoice-no>
  email` — one invoice per child (the report's from and to are both that invoice),
  started with `proc_open` and an argument array. The company is the request's
  (`CompanyContext`), the login the verified token's user; nothing else the client
  sends reaches the arguments except invoice numbers, which are parsed as integers
  first. The child checks all five arguments and refuses anything else.
- **The PHP binary** is chosen by `InvoiceMailer::phpBinary()`: under the CLI,
  `PHP_BINARY`; under a web SAPI (mod_php's `PHP_BINARY` is empty or the web server
  itself) the CLI beside this PHP — `PHP_BINDIR/php<major>.<minor>`, then
  `PHP_BINDIR/php`, then `php` from `PATH`.
- **The child includes `reporting/prn_redirect.php` itself** (after `chdir` to
  `reporting/`), which resolves the report through `find_custom_file()`. The result
  is the last stdout line starting with `FA_REPORT_RESULT `; everything else the child
  prints is ignored.
- **`sent`** is decided by message level, not by matching FrontAccounting's text:
  true when the child reported at least one notice and no warning or error (and did
  not time out). `recipient` is the last email address in a notice. No result line,
  or an unreadable one, is `sent: false` with "The report process ended without a
  result." (plus the timeout, when it was stopped). A result with no messages at all
  — FrontAccounting stopped before the report, as `session.inc` does, silently, when
  it refuses the login (an unknown or inactive user, an unknown company, or the
  multi-company case below) — is `sent: false` with "The report process did not run:
  FrontAccounting refused the login for this company and user (see the server
  log)."
- **Every id is validated before any email runs.** An id that is not an integer is
  `BAD_INPUT`, an unknown invoice `NOT_FOUND`, a voided one `FA_REJECTED` — each for
  the whole call, before any child starts, so no invoice of the list is emailed. The
  call is refused (a `LogicException`) inside an open transaction.
- **A log line per unsent invoice:** `InvoiceMailer` writes one `error_log` line —
  `graphql: invoice <no> not emailed; child exit <code|timeout>; stdout …; stderr …`
  (the last 2 KB of each) — for every invoice whose `sent` is false.
- **PDFs are left when not sent.** FrontAccounting removes the report's PDF only after
  mailing it. An unsent invoice's PDF stays in `company/N/pdf_files` under a random
  24-character name until FrontAccounting's `End()` sweeps files older than 180 s on
  a later report run (`reporting/includes/pdf_report.inc`); the web UI's own reports
  leave theirs the same way. No cleanup of its own.
- **Multi-company caveat.** Before signing in, `session.inc` installs the *default*
  company's extension hooks. The child's password-less sign-in goes through
  `hooks_graphql::authenticate`, so it works only when the graphql extension is
  active in the default company as well as the target one; otherwise FrontAccounting
  refuses the login and every invoice is `sent: false` with the "did not run" message
  above. Installing the target company's hooks first is left for later.

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

*(revised)* As built (final review I-1 and M-4):

- **The document lock over HTTP** (`tests/Http/BillingFlowTest`):
  `testConcurrentPaymentsGetDistinctNumbers` holds the lock while two
  `customerPaymentCreate` requests (two customers, sent together with `curl_multi`)
  are both seen waiting on it in the process list, then releases it: both commit with
  distinct numbers. `testEveryDocumentWriteWaitsForTheLockAndIsRefusedAsBusy` holds it
  while all seven document writes (`deliveryCreate`/`Delete`, `invoiceCreate`/`Delete`,
  `customerPaymentCreate`/`Update`/`Delete`) are sent at once: all seven are seen
  waiting, and each answers `FA_REJECTED` "FrontAccounting is busy; try again." after
  its 10 s. Each request is one FrontAccounting would refuse, so a resolver without the
  lock writes nothing and fails the test by name. `DocumentLockTest` covers
  `DocumentLock` itself.
- **Checked in code, not by tests.** These ported checks are implemented, and
  evidenced by reading the code, but no test exercises them yet (carried to Release 4,
  final review M-12):
  - the refusals of a prepaid order without a deferred income account and of an order
    not released (prepayment not received) (`DeliveryService::create`);
  - the delivery's default `dueDate` (the order's delivery date);
  - cash or prepayment terms that come from the delivery (rather than from a given
    `paymentTermsId`) refused on invoicing;
  - a missing exchange rate refused at service level (only the `BillingChecks` helper
    is tested, in `BillingPlumbingTest`);
  - the company BCC on emailed invoices.

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
