# Inventory: items and prices — design

Status: approved by the pricing session 2026-10-25 (with §3.3's `currency` and §3.2's editable note added), for review by the saygoweb.com-my pricing session
(Panel spec `2026-10-24-accounting-pricing-design.md`, "spec 2"; this is its
"spec 1"). Built in three releases, in order: **A** (unblocks Panel PR #132),
**B** (for Panel spec 3, the Admin pricing UI), **C** (batched quotes).

## 1. Intent

FrontAccounting is the source of truth for every price the Panel shows or bills.
The Panel reads prices live and stores none. This module has to let it:

- ask FA what an item costs for a customer, or on a price list, computed by FA's
  own `get_price()` (A);
- switch an existing order line to another item and have FA reprice it, without
  losing the line's id (A);
- later, maintain items, price lists and prices from the Panel (B).

Owner's model (Panel spec §1.2): one FA stock item per product (`DOM-<TLD>`, one
per hosting plan); per-reseller pricing by FA sales types (price lists), shared
between resellers; Retail is the base list (`base_sales`); with no price on a
customer's list FA's fallback applies (base price × the list's factor); NZD only.

## 2. Verified today (2026-10-25, `main` a6f5b76)

| Need | Today | Change |
|---|---|---|
| Order line reads carry `unitPrice` | `SalesOrderLine.unitPrice` is in the type (`sales_order_details.unit_price`) | none; a test pins it (A) |
| New line without `unitPrice` is priced from the customer's list | `addLine()` → `get_kit_price($stock, cart currency, cart sales_type, price_factor, date)` | none; a test pins it |
| Unchanged line sent with `unitPrice` keeps it | `replaceLines()` takes the given price | none; a test pins it |
| Unchanged line sent with its `id` and no `unitPrice` keeps its stored price | `replaceLines()` falls back to `$line->price` | none; a test pins it |
| `lines` replaces the order's lines | yes (omitted lines are deleted unless delivered) | none |
| An existing line may change `stockId` | **refused**: "A line's item cannot change: remove the line and add another." | **A, §3.2** |
| `priceQuote` | absent | **A, §3.1** |
| `customerUpdate` sets `salesTypeId` | yes (`CustomerService`, checked against `sales_types`) | none (B relies on it) |
| `stockItemList` exposes `categoryId`, `editable`, `inactive` | yes (`StockItemModel`) | none |
| `salesTypeList` | yes, read-only, `SA_SALESORDER` | B adds writes |

## 3. Release A

### 3.1 `priceQuote`

```graphql
type PriceQuote {
  stockId: String!
  price: Float          # null: FA has no price for the item on this list or the base list
  currency: String!     # the customer's currency, else the company's home currency
  salesTypeId: ID!      # the price list the quote is for
}

priceQuote(stockId: String!, customerId: ID, salesTypeId: ID): PriceQuote!
```

The list and currency:

| Given | List | Currency |
|---|---|---|
| `customerId` | the customer's `debtors_master.sales_type` | the customer's `curr_code` |
| `salesTypeId` | that list | home currency |
| both | `salesTypeId` (what would this customer pay on that list) | the customer's |
| neither | the base list (`get_base_sales_type()`) | home currency |

The price is `get_price($stockId, $currency, $listId, null, Today())`. It is FA's
function, called as order entry calls it. The factor comes from the list, so the
fallback, rounding (`round_to`) and currency conversion are all FA's. Kits are
outside this API: `stockId` must be a `stock_master` row.

**Null, not 0.** `get_price()` returns 0 both for "priced at 0" and for "no price".
The module tells them apart with a guard that repeats `get_price()`'s *selection*
conditions, but none of its arithmetic. It answers `null` when none of these holds:

1. a `prices` row for the item on the list or the base list, in the currency or
   the home currency (the four lookups `get_price()` tries);
2. the item has no `prices` row in either currency at all, and the company's
   `add_pct` is not −1 (FA then prices from cost: that is a price, and FA would
   invoice it).

When one holds, `price` is `get_price()`'s result, 0 included. The guard sits in one
small class (`Fa\Service\PriceQuoteService::hasPrice()`), and a comment cites the
`get_price()` lines it mirrors. The integration tests compare it with `get_price()`
on every branch (own list, base × factor, home currency, add_pct, none).

Errors:

- unknown `stockId` → NOT_FOUND "There is no item '<id>'.";
- unknown `customerId` → NOT_FOUND;
- unknown `salesTypeId` → NOT_FOUND;
- neither id given and no base list set (`base_sales` ≤ 0) → BAD_INPUT "No base
  price list is set."

Inactive items and lists are still quoted: the Audit prices existing lines.

**Access:** `SA_SALESORDER` ("Sales orders edit"), the area order entry needs to
price a line, and the one `salesTypeList` and `stockItemList` already use. The
`sgwpanel` role holds it, since it creates and updates orders; Release A's
deploy check confirms that on live (§6).

### 3.2 `salesOrderUpdate`: changing a line's item

A line sent with its `id` and a different `stockId` (case-insensitive compare, as
today) is switched in place:

- **Id kept.** The cart line is rebuilt as a `line_details` for the new item, with
  the same `id`. `update_sales_order()` updates by id, so it writes
  `sales_order_details.stk_code` to the same row.
- **Price.** The given `unitPrice` when sent. Otherwise
  `get_kit_price($new, cart currency, cart sales_type, price_factor, date)`, the
  same call `addLine()` makes, so the line is priced from the customer's list.
- **Description.** The given `description` when sent and the new item is editable.
  Otherwise the new item's description (the `line_details` constructor's rule).
  **So an item whose description is not editable replaces the line's description
  with its own.** The Panel matches lines back to domains and plans by description
  ("Domain Registration - <domain>", "Hosting - <name>"), so every `DOM-*` and plan
  item must be editable (`stock_master.editable` = 1). Release B's
  `stockItemCreate` makes items editable; the items created by hand for Release
  A's rollout (Panel spec §10 step 2) must be created editable too (§6).
- **Quantity, discount.** The given ones, else the line's.
- **Checks.** `checkLine()` on the new item, as for any line. The below-cost warning
  applies as on a new line.
- **Refused:**
  - a new code that is a kit or a foreign item code (it would expand into several
    lines, and the id could not be kept): BAD_INPUT "A line can only change to a
    stock item, not a kit." on `lines.N.stockId`;
  - a line with deliveries (`qty_done ≠ 0`), unless the order is relaxed (a
    recurring order, `isRelaxed()`): BAD_INPUT "Line <id> has been delivered: its
    item cannot change." Past deliveries keep their own item, so on a recurring
    order the next delivery is on the new item.

Every other line rule is unchanged (§2).

### 3.3 Reads

`SalesOrder` already has `salesTypeId` (`sales_orders.order_type`). It gains
`currency: String!`, the customer's `curr_code`. FA keeps no currency on the order:
`read_sales_order()` takes the customer's, and FA refuses to change a customer's
currency once it has transactions. Together they are the list and currency a
switched line is repriced on (§3.2: the cart's list is the order's, with that
list's factor, not the customer's current list). So the Panel quotes
`priceQuote(stockId, customerId, salesTypeId: <the order's salesTypeId>)` (§3.1's
"both" row) and gets the price a switch will charge, even after the customer has
moved to another list. One difference remains: the switch prices at the order's date
and the quote at today's. Only the exchange rate depends on the date, so this
matters only for a foreign-currency customer, and never under NZD only (P10).

Tests pin `salesOrderList { salesTypeId currency lines { id stockId description
quantity unitPrice } }`, and `salesOrderLineList` likewise, after a switch.

### 3.4 Panel parity

The Panel's `FakeFaClient` already encodes this behaviour. Its `effectiveSalesType()`
lets `salesTypeId` win over `customerId`, which is §3.1's "both" row. It quotes in
one fixed currency instead of the customer's, which is the same thing while
everything is NZD (P10).

## 4. Release B (Panel spec 3)

### 4.1 Items

- `stockItemCreate(input: [StockItemCreateInput!]!)`: `stockId` (required),
  `description` (required), `categoryId` (required), `longDescription`, `inactive`,
  and three optional overrides: `taxTypeId`, `units`, `editable`. Created as FA's item
  page creates an item (inventory/manage/items.php): tax type, units, `mb_flag`,
  sales/COGS/inventory/adjustment/WIP accounts, dimensions, `no_sale` and
  `no_purchase` come from the category (`get_item_category()`), through FA's
  `add_item()`. The overrides win over the category's defaults, as the page lets them.
  `editable` defaults to true (the page's default is false), because the Panel's lines
  carry their own descriptions (§3.2).
- The code is checked as the page checks it: not empty, none of space, `'`, `+`, `"`,
  `&` or tab, and not already an item or kit code (`get_item_kit()`). It must also fit
  `stock_master.stock_id` (20 characters). Each of these is BAD_INPUT on `stockId`. An
  unknown category, a fixed-asset category (`dflt_mb_flag` 'F'), an unknown tax type
  or an unknown unit is BAD_INPUT on its field; so is an empty description.
- `stockItemUpdate(input: [StockItemUpdateInput!]!)`: `id`, `description`,
  `longDescription`, `categoryId`, `inactive`, `taxTypeId`, `units`, `editable`,
  through `update_item()`, which rewrites every column: the ones not given keep the
  row's values. Changing the category does not re-default anything (the page
  re-defaults only on a new item). `inactive` is set on `stock_master` and
  `item_codes`, as the page sets it. An unknown id is NOT_FOUND.
- Access: `SA_ITEM` for both. `stockItemList` stays as it is (`SA_SALESORDER`).
  No delete: FA refuses to delete an item with transactions, and `inactive` covers
  retiring one.

### 4.2 Price lists

- `salesTypeCreate` / `salesTypeUpdate`: `name`, `factor` (default 1.0),
  `taxIncluded`, `inactive`, through `add_sales_type()` / `update_sales_type()`, with
  `inactive` through `update_record_status()`. An empty name, or a factor that is not
  a number ≥ 0, is BAD_INPUT, as the page refuses them; so is a name another list
  has (`sales_types.sales_type` is unique). Access `SA_SALESTYPES`. No delete.
- `salesTypeList` gains `isBase: Boolean!` (the list is `base_sales`). This also
  answers "which list is the base".

### 4.3 Prices

- `priceList(query: MangoInput): [Price!]!` and `priceCount`, over `prices`:
  `{ id, stockId, salesTypeId, currency, price }`. Access `SA_SALESPRICE`.
- `priceSet(input: [PriceSetInput!]!): [Price!]!`, with `PriceSetInput { stockId:
  String!, salesTypeId: ID!, currency: String!, price: Float! }`. It upserts by
  (`stockId`, `salesTypeId`, `currency`) through `add_item_price()` /
  `update_item_price()`. The item (a stock item, not a kit), list and currency must
  exist, else BAD_INPUT on the field; a price below 0 is BAD_INPUT, as the page's
  `check_num('price', 0)`. Codes are stored as `stock_master` and `currencies` spell
  them.
- `priceDelete(input: [PriceKeyInput!]!): [Price!]!`, with `PriceKeyInput { stockId:
  String!, salesTypeId: ID!, currency: String! }`. It deletes by that key
  (`delete_item_price()`) and returns the rows removed. A key with no row is
  NOT_FOUND.
- Access `SA_SALESPRICE`. Batches are atomic, as every mutation is.
- Set explicit prices on any list whose `taxIncluded` differs from the base list's.
  FA's fallback (base × factor, §3.1) does not convert between tax-included and
  tax-excluded prices: with no price of its own, a tax-excluded list charges the
  tax-included base figure times its factor, now without tax added on top.

### 4.4 Customer's list

`customerUpdate(input: [{id, salesTypeId}])` works today. B adds no change; a test
pins it.

## 5. Release C

`priceQuotes(items: [PriceQuoteInput!]!): [PriceQuote!]!`, with `PriceQuoteInput {
stockId: String!, customerId: ID, salesTypeId: ID }`. It is the batched form of
§3.1: same rules, same access, results in input order. One bad item fails the
call, as other batches do, and the error carries the index. At most 200 items.

## 6. Delivery

- Each release is one PR on its own branch, merged when CI is green, then
  released (`fa:*` scripts and the release workflow). Release A is v1.3.0.
- Release A's deploy check, on live before the Panel deploys: the `sgwpanel`
  user's role holds `SA_SALESORDER`, and `priceQuote(stockId: "HGEN1")` answers.
- Release A's rollout note (README and release notes): items the Panel switches
  lines to must have editable descriptions (§3.2).
  The pricing session is told the version and the PR link.
- Release B's access: the panel's role needs `SA_ITEM` and `SA_SALESPRICE` to write
  items and prices (`SA_SALESTYPES` only to add or edit lists). Granting them is the
  user's call, in FA's role editor on live; the test seed grants them to its
  "GraphQL Panel" role so the HTTP tests run as the Panel will. The live login is
  `sgw-panel`.
- README: the coverage table gains a "Pricing" row (A), then "Items and price
  lists" (B). The roadmap's "inventory maintenance" gap narrows.

## 7. Testing

Integration (against FA's demo company and fixtures; the fixtures gain a price
list with a factor and an override):

- `priceQuote`: own-list price; base × factor; home-currency fallback; add_pct;
  no price → null; a 0 price → 0; customer's currency and list; `salesTypeId`
  over the customer's list; base list when neither id is given; each NOT_FOUND;
  no base list → BAD_INPUT; without `SA_SALESORDER` → FORBIDDEN.
- `salesOrderUpdate`: a switch keeps the id; it reprices from the customer's list;
  it keeps a given price; it takes the new item's description; a kit is refused;
  a delivered line is refused, but allowed on a relaxed (sgw_sales recurring)
  order; unchanged lines keep their price with or without `unitPrice`; a new line
  is priced from the list; a switch on an order whose customer has since moved to
  another list is repriced from the order's list, and `priceQuote(customerId,
  salesTypeId: <order's list>)` returns the same figure; a switch to a non-editable
  item takes the item's description.
- `salesOrderList`: `currency` is the customer's currency.
- HTTP: the Panel's exact query document (§3.1) through the endpoint with a
  machine token.
- C gets its own test section when it is planned.

Release B (integration, demo company):

- `stockItemCreate`: a service item in category 4 takes the category's tax type,
  units, accounts and `mb_flag`, with `editable` true; each override wins; each
  BAD_INPUT of §4.1; `inactive: true` is set on `stock_master` and `item_codes`; a
  batch with one bad item writes nothing.
- `stockItemUpdate`: a description change keeps every other column; a category change
  keeps the accounts; `inactive` both ways; an unknown id is NOT_FOUND.
- `salesTypeCreate` / `salesTypeUpdate`: defaults, each BAD_INPUT, `inactive`;
  `isBase` is true only for the `base_sales` list.
- `priceSet`: an insert, then the same key again updates the row (one row, new price);
  a code in another case is stored as `stock_master` spells it; each BAD_INPUT;
  `priceQuote` returns the set price afterwards. `priceDelete`: returns the removed
  row; a missing key is NOT_FOUND and the batch removes nothing.
- `priceList` / `priceCount` filter by `stockId` and `salesTypeId`.
- `customerUpdate(salesTypeId)` moves a customer to another list, and `priceQuote`
  for that customer then uses it.
- Access: each mutation without its area is FORBIDDEN.
- HTTP, with the panel's machine token: the Panel's own shapes, four items created in
  category 4, prices set on two lists, a customer moved to a list and quoted.
