# FrontAccounting GraphQL module — Roadmap, September 2026

Where the module stands at the end of September 2026, and what comes next. The
designs behind what is built are in `docs/superpowers/specs/`; the plans that built
them are in `docs/superpowers/plans/`.

## Where it stands

All of the following is merged on `main` and green in CI on {upstream FrontAccounting
`master`, `cambell-prince/frontaccounting` `master-cp`} × {PHP 7.4, 8.3}.

| Release | Delivered | Spec |
|---|---|---|
| Foundation (1.0, 1.1) | Slim 4 endpoint; JWT access and rotating refresh tokens; FrontAccounting loaded in-process as the token's user; one company per request; every response JSON with FrontAccounting's output captured; runs on upstream FrontAccounting with the fork optional | `2026-09-21-foundation-design.md` |
| Release 2 — Panel | Reference lookups; customers, branches and contacts; sales orders; `sgw_sales` recurring schedules on orders | `2026-09-25-release-2-panel-design.md` |
| Release 3 — Billing | Deliveries; invoices from deliveries or an order in one step; customer payments and allocations; voids; emailing invoices through FrontAccounting's `rep107` | `2026-09-26-release-3-billing-design.md` |
| Release 4 — Extensions and recurring invoices | An extension contract discovered through FrontAccounting's hooks (root fields, contributions to the sales order Type and inputs, write participants in the core's transaction); `sgw_sales` as the first extension, serving recurrence and recurring invoice generation (`recurringDueList`, `recurringGenerate` with email) | `2026-09-28-release-4-extensions-recurring-design.md` |
| Machine tokens | Long-lived, revocable bearer tokens for server-to-server clients, issued by `bin/fa-token` | Foundation spec §3.7 |
| Dev fixtures | `HDOM`/`HGEN1` hosting items and an example reseller for development | — |

The first client, the `saygoweb.com-my` hosting panel, runs its reseller billing
(Release 1 of the panel) against this API with a machine token.

`saygoweb/anorm-graphql`, which generates the schema, reached 0.3.0 alongside this
module: create/update mutations, input-only entities, a `Date` scalar,
`--without-update`/`--without-delete`.

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

## Later: beyond AR

Not started; each is its own release-sized design.

- **Accounts payable** — suppliers, purchase orders, receipts, supplier invoices and
  credits, supplier payments and allocations.
- **General ledger and banking** — journal entries, bank deposits and payments not
  tied to customers, transfers, reconciliation, the chart of accounts, fiscal years,
  GL inquiries (trial balance).
- **Inventory** — item and price maintenance, stock adjustments and transfers, stock
  on hand by location (items are read-only today).
- **Other FrontAccounting modules** — manufacturing (work orders), fixed assets,
  dimensions, tax reports.

## Platform

- **Read-only customer access.** `SA_CUSTOMER` guards listing and writing alike, so a
  token that can list customers can also change them. A separate list area would let
  a read-only client exist.
- **Idempotency keys** on create mutations, if clients need server-enforced
  deduplication (the panel serialises and checks `customerRef` itself for now).
- **Webhooks / change notifications** for clients that poll today.
- **Several companies in one request** (one company per request is deliberate; revisit
  only with a concrete need).
- `anorm-graphql` **1.0.0** once the module stops needing generator changes.
- **More extensible core types** for extensions (today: the sales order Type and
  inputs), when an extension needs them.

## Deferred hardening

Recorded as Minors by the checkpoint reviews; none blocks current use.

- A raw-condition scope in `anorm-graphql`, replacing the contact scoping's bound id
  list (which nears MySQL's parameter limit only with a very large CRM) and fixing
  `contactList` with an empty `{}` selector returning `INTERNAL`.
- A company column on machine-token rows (defence in depth for companies that share a
  database and prefix); a test that activation creates a missing table.
- `bin/fa-report`: the login-throttle-file restore race, and FrontAccounting fatal
  text (with file paths) passed through verbatim.
- Tests for the checks the Release 3 spec §8 lists as "checked in code, not by tests".
- Refresh-token rows the test suite leaves behind.

## Operating notes

- **Existing installs:** re-activate the GraphQL extension per company (Setup →
  Install/Activate Extensions) so `activate_extension()` creates the module's tables
  (`graphql_refresh_token`, `graphql_machine_token`).
- **Machine tokens:** issue per client with `bin/fa-token`, run as the web server's
  user, for a dedicated FrontAccounting user whose role holds only what the client
  uses; rotate by issuing a new token and revoking the old.
- **Behind a reverse proxy:** restore the client address into `REMOTE_ADDR`, or
  FrontAccounting's login throttle is shared by every client.
