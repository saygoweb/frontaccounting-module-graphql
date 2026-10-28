# Banking: bank reconciliation — design

Status: design approved in conversation 2026-10-28; this spec is for review before
the implementation plan. One release in this module, plus a small change in the MCP
module (`frontaccounting-module-mcp`).

## 1. Intent

Let Claude reconcile a bank account against a bank statement through MCP, as a
person does in FrontAccounting's *Banking → Reconcile Bank Account* screen
(`gl/bank_account_reconcile.php`, `SA_RECONCILE`).

The workflow it serves: Claude reads a statement (for example BNZ's PDF
`Bus_First_Transact-2026-09-30.pdf`: opening balance, lines, closing balance), then

1. asks for the account's reconciliation summary at the statement date and checks
   the statement's opening balance against FA's beginning balance;
2. lists the account's unreconciled bank entries up to the statement date;
3. matches statement lines to entries itself (amount, date, payee/memo);
4. ticks the matched entries reconciled to the statement date, giving the
   statement's closing balance;
5. checks that the difference is 0, and reports the statement lines it could not
   match and the entries left unticked.

What changes here is only `bank_trans.reconciled` and the account's last
reconciliation (`bank_accounts.last_reconciled_date`, `ending_reconcile_balance`),
exactly as the screen changes them. A statement reconciled through the API looks the
same in the screen, and the other way round.

### Non-goals

- Creating, editing or voiding bank payments, deposits or transfers. Unmatched
  statement lines are reported to the user; entering them is the roadmap's
  "General ledger and banking" release.
- Matching on the server. Claude matches; the API offers the primitives.
- Importing statements (sgw_import does that for CSV files).
- Reports (`SA_BANKREP`) and GL inquiries.

## 2. Verified today (2026-10-28, `main` b34a0b2)

| Need | Today | Change |
|---|---|---|
| Bank accounts readable | `bankAccountList`/`Count`, read-only, `BankAccountModel` | none |
| Bank entries readable | absent | **§3.1** |
| Mango filter on NULL | anorm's `MangoQueryParser`: `{"f": null}` → `IS NULL`, `{"f": {"$ne": null}}` → `IS NOT NULL`, `$exists` | none |
| Computed, filterable columns | `ComputedColumns` (`voided`, `outstanding`, ...) | add `ComputedColumns::bankTransVoided()` (`amount = 0`) for §3.1's `voided` |
| Reconciliation arithmetic | FA `get_max_reconciled($date, $account)` (`gl_db_bank_accounts.inc`) | reused (§3.2) |
| Setting a row reconciled | FA `update_reconciled_values($id, $value, $date, $end_balance, $account)` | reused (§3.3) |
| MCP tools from schema | `SchemaToolFactory`: one tool per root field; per-list `HINTS` in `GeneratedTool` | hint + annotation (§4) |
| MCP mutation annotations | `idempotentHint` true only for `*Update`, `destructiveHint` only for `*Delete` | `bankReconcile` idempotent (§4) |

## 3. GraphQL

### 3.1 `bankTransactionList`, `bankTransactionCount`

A read-only model over `bank_trans` (`BankTransactionModel`, generated
`--without-create --without-update --without-delete`, like `BankAccountModel`).
Access: `SA_BANKTRANSVIEW`.

```graphql
type BankTransactionType {
  id: ID!                 # bank_trans.id: the row a reconciliation ticks
  transType: Int          # FA transaction type (1 payment, 2 deposit, 4 transfer, 12 customer payment, 22 supplier payment, ...)
  transNo: ID             # the transaction's number within its type
  bankAccountId: ID       # bank_act
  reference: String       # ref
  date: Date              # trans_date
  amount: Float           # in the account's currency; negative leaves the account
  reconciled: Date        # the statement date it is reconciled to; null when not
  personType: Int         # person_type_id (0 misc, 2 customer, 3 supplier, 4 quick entry, ...)
  personId: String        # person_id: an id, or for misc a free-text name
  voided: Boolean!        # amount 0 (FA voids a bank entry by zeroing it); computed in SQL
  memo: String            # comments.memo_ for (transType, transNo)
  counterparty: String    # FA's payment_person_name(personType, personId, false): customer/supplier name, quick entry, misc name
}
```

Selector and sort fields: `id`, `transType`, `transNo`, `bankAccountId`, `reference`,
`date`, `amount`, `reconciled`, `personType`, `personId`, `voided`. `memo` and
`counterparty` are resolved per row and are not filterable (YAGNI; Claude matches on
them after listing).

The screen's "to tick" list for a statement dated D is then
`{"bankAccountId": A, "reconciled": null, "voided": false, "date": {"$lte": D}}`,
and a statement's lines are `{"bankAccountId": A, "reconciled": D}`.

### 3.2 `bankReconciliation`

```graphql
type BankReconciliation {
  bankAccountId: ID!
  statementDate: Date!
  beginningBalance: Float!   # sum of amounts reconciled before statementDate
  reconciledAmount: Float!   # sum of amounts reconciled to statementDate
  endingBalance: Float!      # beginningBalance + reconciledAmount
  accountTotal: Float!       # sum of all the account's amounts
  lastReconciledDate: Date   # bank_accounts.last_reconciled_date
  storedEndingBalance: Float # bank_accounts.ending_reconcile_balance, when lastReconciledDate = statementDate
  difference: Float          # storedEndingBalance - endingBalance; null without a stored balance. 0 when done.
  statementDates: [Date!]!   # the account's distinct reconciled dates, newest first
}

bankReconciliation(bankAccountId: ID!, statementDate: Date!): BankReconciliation!
```

Access: `SA_RECONCILE`. The sums are `get_max_reconciled()`'s, called in-process so
they agree with the screen to the cent. An unknown account is `NOT_FOUND`.

`difference` follows the screen: ending balance (the statement's closing balance,
as stored) less beginning balance less reconciled amount.

### 3.3 `bankReconcile`

```graphql
input BankReconcileInput {
  bankAccountId: ID!
  statementDate: Date!
  endingBalance: Float!      # the statement's closing balance
  reconcile: [ID!]           # bank_trans ids to tick to statementDate
  unreconcile: [ID!]         # bank_trans ids to untick from statementDate
}

bankReconcile(input: BankReconcileInput!): BankReconciliation!
```

Access: `SA_RECONCILE`. One FA transaction: everything or nothing. Each id goes
through `update_reconciled_values()`, which also records `statementDate` and
`endingBalance` as the account's last reconciliation, as the screen does on each
tick. Called with neither list, it only records the ending balance (the screen's
"enter the statement balance first" step).

Refused as `BAD_INPUT`, naming every offending id in one message (nothing
written):

- an id that is not a bank entry of `bankAccountId`;
- a voided entry (amount 0; the screen does not list them);
- `reconcile`: an entry dated after `statementDate` (the screen lists only
  `trans_date <= statementDate`);
- `reconcile`: an entry reconciled to a *different* date (unreconcile it from that
  statement first; this keeps closed statements from changing by accident);
- `unreconcile`: an entry reconciled to a *different* date;
- the same id in both lists.

Ticking an entry already reconciled to `statementDate`, and unticking an entry
that is not reconciled, change nothing, so repeating a call is safe (idempotent).

Returns the summary of §3.2 after the change.

## 4. MCP

The tools come from the schema, so the release adds `bank_transaction_list`,
`bank_transaction_count`, `bank_reconciliation` and `bank_reconcile` with no tool
code. Changes in `frontaccounting-module-mcp`:

- `GeneratedTool::HINTS['bankTransactionList']`: *"Unreconciled up to a statement:
  selector {"bankAccountId": A, "reconciled": null, "voided": false, "date":
  {"$lte": "<statement date>"}}. A statement's entries: {"reconciled":
  "<statement date>"}."*
- Annotations: `bankReconcile` is `idempotentHint: true` (a generated mutation
  named in an explicit idempotent list, beside the `*Update` rule).
- `docs/CONNECTING.md`: a short "Reconciling a statement" section with §1's five
  steps.
- Catalog test: the four tools exist for a role with `SA_BANKTRANSVIEW` and
  `SA_RECONCILE`, and not otherwise.

## 5. Errors

| Case | Error |
|---|---|
| Role lacks `SA_BANKTRANSVIEW` / `SA_RECONCILE` | `FORBIDDEN`, as other fields |
| Unknown `bankAccountId` | `NOT_FOUND` |
| §3.3 refusals | `BAD_INPUT` with the ids and reasons |
| FA error inside `update_reconciled_values` | `FA_REJECTED`, transaction rolled back |

## 6. Delivery

- GraphQL: one PR on `feature/bank-reconciliation`; minor version bump; README's
  field list and `ROADMAP-2026-09.md` (reconciliation moves out of "Later").
- MCP: one PR after the GraphQL release; requires the GraphQL version that has
  these fields (tools simply do not appear with an older one).
- bms deploys both through the usual extension-green dispatch.

## 7. Testing

Integration tests on the demo company, in CI on {cp, upstream} × {PHP 7.4, 8.3},
rows written by tests removed after:

- `bankTransactionList`: fields map to `bank_trans`; `reconciled: null` and
  `reconciled: D` selectors; `voided`; `memo` and `counterparty` for a customer
  payment, a supplier payment, a quick entry and a misc payee; count agrees with
  list.
- `bankReconciliation`: beginning, reconciled, ending and account total equal
  `get_max_reconciled()` for the same account and date; `difference` and
  `storedEndingBalance` follow `bank_accounts`; `statementDates` newest first.
- `bankReconcile`: ticks and unticks; records the last reconciliation; each
  refusal in §3.3 writes nothing; repeating a call changes nothing; a failure
  part-way rolls back.
- Access: each field refused without its security area.
- Screen parity: after `bankReconcile`, the screen's SQL
  (`get_sql_for_bank_account_reconcile`) shows the same rows ticked.
- MCP: catalog test and hint (§4).
