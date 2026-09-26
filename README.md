# FrontAccounting GraphQL module

A GraphQL API for [FrontAccounting](https://frontaccounting.com/), delivered as a
module (extension) that lives at `modules/graphql` inside a FrontAccounting tree.

**Status: Release 2.** Lookups, customers, branches, contacts and sales orders with
recurring schedules. Generating and sending recurring invoices is Release 3.

## Calling the API

Requirements: FrontAccounting 2.4 — upstream `master` (tested at 2.4.20), or the
`cambell-prince/frontaccounting` fork; `config_graphql.php` copied
from `config_graphql.example.php` with a secret of at least 32 bytes; the extension
activated; and a role that holds **GraphQL API access** plus whatever sales areas the
client needs. No role has it until you grant it, not even System Administrator.

Sign in, keep the pair, send the access token as a bearer token:

```php
$http = new GuzzleHttp\Client(['base_uri' => 'https://fa.example.com/modules/graphql/']);

$gql = function (string $query, array $variables = [], ?string $token = null) use ($http): array {
    $response = $http->post('', [
        'headers' => $token ? ['Authorization' => "Bearer $token"] : [],
        'json' => ['query' => $query, 'variables' => (object) $variables],
        'http_errors' => false,
    ]);
    return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
};

[, $body] = $gql(
    'mutation ($u: String!, $p: String!) { login(user: $u, password: $p) { accessToken expiresIn refreshToken } }',
    ['u' => 'panel', 'p' => getenv('FA_PASSWORD')]
);
$pair = $body['data']['login'];

[$status, $body] = $gql('{ me { login areas } }', [], $pair['accessToken']);
```

- The access token lasts 15 minutes. On **HTTP 401**, call `tokenRefresh` with the
  refresh token and retry once.
- A refresh token is **single use**: every `tokenRefresh` returns its successor —
  store it. Presenting a used one revokes all of that user's refresh tokens; sign in
  again with the password.
- HTTP 403 means the user's role lacks GraphQL API access.
- Errors carry `extensions.code`: `UNAUTHENTICATED`, `FORBIDDEN`, `BAD_INPUT`,
  `NOT_FOUND`, `FA_REJECTED` (with FrontAccounting's own messages in
  `extensions.messages`), `INTERNAL`.
- `login` is refused over plain HTTP unless `allow_insecure_login` is set:
  FrontAccounting stores passwords as unsalted MD5.
- One company per request. `login` takes `company` (default 0), and a refresh token
  is `<company>.<secret>`; a request that has opened one company — by its bearer
  token or by an earlier field — is refused `UNAUTHENTICATED` for any other. Send a
  separate request per company.
- The module answers `POST` at its directory (or `…/index.php`). Anything else is
  JSON too: 405 for another method, 404 for another path.

Generated lists take an optional Mango query:

```php
[, $body] = $gql(
    'query ($q: MangoInput) { salesTypeList(query: $q) { id } }',
    ['q' => ['selector' => json_encode(['id' => 1])]],
    $pair['accessToken']
);
```

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
        'branch' => ['salesmanId' => 1, 'salesAreaId' => 1, 'taxGroupId' => 1, 'locationId' => 'DEF', 'shipperId' => 1],
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

## Generating Types

Types are generated from the Anorm models in `src/Model` by
[`saygoweb/anorm-graphql`](https://github.com/saygoweb/anorm-graphql); only what
FrontAccounting demands is hand-written (spec §4.5). `src/Model` is the folder the
generator scans, so it holds **only** models meant to be API surface; internal
models live in `src/Auth/Model`.

Run on the host — no database and no container needed, only PHP and this module's
`vendor/`:

```bash
bin/generate --dry-run   # show what would change
bin/generate             # write Types, Inputs, tests and ApiSchema.php entries
```

- `src/Type/<Entity>/Base/*` is regenerated every run: never edit it.
- `src/Type/<Entity>/<Entity>Type.php` is written once and is yours. It must declare
  `areas()` — which FrontAccounting security area each verb needs — or it will not
  load: `FaModelType` makes an unmapped verb `FORBIDDEN`.
- Read-only entities are listed in `bin/generate` (`READONLY`).
- `ApiSchema.php` entries led by `// anorm-graphql` belong to the generator; the rest
  (`apiVersion`, `me`, the auth mutations) are hand-written and left alone.

**Co-developing anorm-graphql.** It is at `0.x` and changes alongside this module.

- Generating with a local checkout, on the host:
  `ANORM_GRAPHQL_CHECKOUT=../../../anorm-graphql bin/generate`.
- Running a local checkout in the container: set `ANORM_GRAPHQL_PATH` in
  `docker/.env`, `docker/fa-graphql up`, then — locally only, never committed:

  ```bash
  docker/fa-graphql composer config repositories.local '{"type": "path", "url": "/opt/anorm-graphql", "options": {"symlink": true}}'
  docker/fa-graphql composer update saygoweb/anorm-graphql
  ```

  Before committing, `docker/fa-graphql composer config --unset repositories.local`
  and `docker/fa-graphql composer update saygoweb/anorm-graphql`, so `composer.lock`
  points at the tagged release. While the symlink is in place, host generation must
  use `ANORM_GRAPHQL_CHECKOUT`: the symlink resolves only inside the container.

## How it is being built

1. [Anorm](https://github.com/saygoweb/anorm) models, generated from the
   FrontAccounting database with `anorm make` and then given camelCase domain names
   in place of the schema's abbreviations (`debtor_no`, `br_name`, ...).
2. GraphQL Types, Inputs, tests and `src/ApiSchema.php` entries generated from those
   models by `anorm-graphql make`.
3. Hand-written code only where FrontAccounting demands it: authentication,
   composite-key tables, and transactional writes that must go through FA's own
   functions rather than straight into a table.

`docs/spec-inputs.md` records what is known so far that the specification has to
deal with.

## Layout

| | |
| --- | --- |
| `hooks.php` | `hooks_graphql` — FrontAccounting's extension contract; declares the `SA_GRAPHQL` security area |
| `index.php`, `.htaccess` | the endpoint: every request under `modules/graphql/` is routed to `index.php` |
| `src/` | `FA\GraphQL\` (PSR-4) |
| `tests/Unit` | no FrontAccounting, no database, no web server |
| `tests/Http` | through Apache; needs the docker stack or an install (`FA_GRAPHQL_URL`) |
| `docker/` | a throwaway FrontAccounting with this checkout plugged into it — see `docker/README.md` |

## Developing

    docker/fa-graphql init      # pick host ports that are free here
    docker/fa-graphql up        # build, boot, seed FA's demo data, composer install
    docker/fa-graphql test
    docker/fa-graphql lint      # php -l, phpcs PSR-12
    docker/fa-graphql analyze   # PHPStan level 5
    docker/fa-graphql ci        # all of it, from a fresh build

    curl -H 'Content-Type: application/json' -d '{"query": "{ apiVersion }"}' \
        http://localhost:8100/modules/graphql/

The tasks themselves are composer scripts (`composer test`, `lint`, `cs:check`,
`analyze`, `ci`), so they run the same on a host with its own PHP.

## Installing into FrontAccounting

Clone into `modules/graphql`, run `composer install --no-dev`, then install and
activate the extension under Setup → Install/Activate Extensions, and grant
"GraphQL API access" to the roles that should have it.

**Behind a reverse proxy.** `login` enforces FrontAccounting's own failed-login
throttle (`login_delay`, `login_max_attempts`, `tmp/faillog.php`), and that throttle
is keyed on `REMOTE_ADDR` alone — across all users and all companies, and shared with
the web UI's login page. Behind a reverse proxy every client has the proxy's address,
so a few failed logins from anyone lock **every** API and web login out for
`login_delay` seconds. `trust_proxy` does not help: it changes only what this module
reads from `X-Forwarded-*`, never the address FrontAccounting keys its throttle on.
Either have the web server restore the client address into `REMOTE_ADDR` (Apache
`mod_remoteip`, nginx `real_ip`) for the FrontAccounting vhost, or accept the shared
lockout.
