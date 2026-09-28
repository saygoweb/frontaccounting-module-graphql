# Docker test stack

CI no longer uses this stack: it runs `tools/ci.sh` in the FrontAccounting CI
image (see the README's Tests section). This stack is for development.

A throwaway FrontAccounting install with this module plugged into it — Apache +
mod_php + MariaDB — for developing the GraphQL API without a FrontAccounting
checkout, a PHP, or a database on your own machine.

    docker/fa-graphql init      # pick host ports that are free here
    docker/fa-graphql up        # build, boot, seed, install composer deps
    docker/fa-graphql test      # run the PHPUnit suite
    docker/fa-graphql lint      # php -l, then phpcs PSR-12
    docker/fa-graphql analyze   # PHPStan

`up` prints the URLs. `docker/fa-graphql help` lists every command.

The design, and most of the driver script, comes from the sibling stack in
`modules/api/docker`; its README explains the reasoning at more length (the Debian
trixie + sury base, why xdebug is unloaded rather than idle, why `up` rather than
`restart` after changing `XDEBUG_MODE`). What follows is what is particular to
this one.

## How it fits together

| | |
| --- | --- |
| FrontAccounting | cloned into the image at build time from `FA_REPO` / `FA_REF` — upstream [`FrontAccountingERP/FA`](https://github.com/FrontAccountingERP/FA) `@ master` by default. The [`cambell-prince/frontaccounting`](https://github.com/cambell-prince/frontaccounting) fork (`master-cp`) works too; CI builds both |
| `sgw_sales` | cloned into `/var/www/html/modules/sgw_sales` from `SGW_SALES_REPO` / `SGW_SALES_REF` (`master` by default) the same way, with `composer install --no-dev` run in it; `SGW_SALES_ACTIVE` (default `true`) registers it as extension 2 |
| this checkout | bind-mounted at `/var/www/html/modules/graphql`, so an edit is live on the next request |
| `config.php`, `config_db.php`, `lang/installed_languages.inc` | written by the entrypoint, into the image's FA tree |
| the two `installed_extensions.php` | written by the entrypoint on every start, with this module **registered and active for company 0** — so `hooks.php` is loaded and `SA_GRAPHQL` exists, as it would after Setup → Install/Activate Extensions — and `sgw_sales` alongside it when `SGW_SALES_ACTIVE=true` |
| `config_graphql.php` | written by the entrypoint into this checkout **only if absent**, with a generated 48-byte secret and `allow_insecure_login => true`. Gitignored; never written into production the same way — see `config_graphql.example.php` |
| `vendor/` | installed by `up`, into your checkout, owned by you |

Nothing is written into your checkout except `vendor/`, `composer.lock` and
`config_graphql.php`.

Apache listens on **8000** inside the container, as in the api stack. `tests/Http`
reaches it there through `FA_GRAPHQL_URL`, which compose sets. On the host it is
`HTTP_PORT`, 8100 by default.

## Ports

Defaults are clear of the other stacks on a machine that runs them all:

| stack | http | db | phpMyAdmin |
| --- | --- | --- | --- |
| FrontAccounting (`docker/fa`) | 8080 | 3307 | 8081 |
| `modules/api` | 8090 | 3309 | 8091 |
| Anorm, anorm-graphql | — | 3316–3319 | 8096–8099 |
| **this one** | **8100** | **3320** | **8101** |

## Datasets

`docker/fa-graphql db load <what>` and `db reset <what>`:

| what | source | login |
| --- | --- | --- |
| `demo` (default) | FrontAccounting's `sql/en_US-demo.sql`, from the image | admin / password |
| `new` | FrontAccounting's `sql/en_US-new.sql`, from the image | admin / password |
| a path | any `.sql` or `.sql.gz` on the host | — |

`demo` is the default because it has customers, suppliers, items and transactions
to query. This repository ships no fixture of its own yet.
`docker/fa-graphql db dump` writes a gzipped dump back out.

After the dataset, `db load` also applies each active extension's
`sql/update_*.sql` (this module's, and `sgw_sales`'s `update_1.0.sql` +
`update_1.4.sql` when `SGW_SALES_ACTIVE=true`, cut at their `# Upgrade helpers`
line), then `tests/data/seed.sql`. That seed adds a `GraphQL API` role holding
`SA_GRAPHQL` (role 2's areas, plus `SA_SALESORDER` and `SA_CUSTOMER` — section
3072, areas 3075 and 3074 — appended when missing), a user in it, a user without
`SA_GRAPHQL`, and a role that may only take orders:

| user | password | role |
| --- | --- | --- |
| `apitest` | `password` | `GraphQL API` (System Administrator's areas, `SA_GRAPHQL`, `SA_SALESORDER`, `SA_CUSTOMER`) |
| `noapi` | `password` | System Administrator (no `SA_GRAPHQL`) |
| `apiorders` | `password` | `GraphQL Orders` (`SA_GRAPHQL`, `SA_SALESTRANSVIEW`, `SA_SALESORDER` only) |
| `sgwpanel` | none (unusable) | `GraphQL Panel` (`SA_GRAPHQL`, `SA_SALESTRANSVIEW`, `SA_CUSTOMER`, `SA_SALESORDER` only) — signs in with a machine token: `docker/fa-graphql exec bin/fa-token issue --company 0 --user sgwpanel --days 365 --label dev` |

The whole sequence is idempotent, so a repeated `db load` (or `up` against an
already-seeded volume) is safe.

## Dev fixtures

`docker/fa-graphql db fixtures` gives the dev stack realistic hosting-billing data,
for developing against and for the panel app to build against, without touching
`tests/data/seed.sql` or anything the test suites load:

    docker/fa-graphql db fixtures

It applies `tests/data/dev-fixtures.sql` (idempotent, like any other dataset): the
`yr` item unit, and two service items shaped like FrontAccounting's own `add_item()`
would write them — `HDOM` "Domain Registration" and `HGEN1` "Hosting", category 4
("Services"), with USD prices (29.00 and 80.00) on both demo sales types. No EUR
price rows: FrontAccounting converts the home-currency price at the exchange rate for
a EUR customer's order date (`sales/includes/sales_db.inc` `get_price()`).

Then `docker/fixtures.php` creates example documents **through the GraphQL API
itself**, signed in as `apitest` — not a backdoor into FrontAccounting, and not
reachable over HTTP (`.htaccess` denies everything under `docker/`): a reseller
customer ("Example Hosting Reseller Ltd", ref `EXAMPLE-RESELLER`) with its default
branch and a `billing@example.com` contact, and two yearly recurring sales orders
shaped like the live data (`customerRef` `sgw-hosting-1001` / `-1002`, `HGEN1` +
`HDOM` lines). The first is invoiced in one step and paid in full, allocated — a
settled example; the second is left open, undelivered. Idempotent: if a customer
with ref `EXAMPLE-RESELLER` already exists, nothing is created again — it is read
back and reported instead.

## sgw_sales

The recurring-sales module this API drives in Release 2, and the other
extension whose hooks share a process with ours. `SGW_SALES_REPO` / `SGW_SALES_REF`
default to [`saygoweb/frontaccounting-module-sgw_sales`](https://github.com/saygoweb/frontaccounting-module-sgw_sales)
`@ master`, which has been on Anorm ^3.2.1 since its PR #7 — the Anorm 1.6 before
that could not be loaded beside this module's. `SGW_SALES_ACTIVE=false` leaves it
cloned but unregistered (and its tables unloaded). `SGW_SALES_PATH`, like
`ANORM_GRAPHQL_PATH`, bind-mounts a host checkout over the clone in the image, for
working on both repositories at once — it needs its own `composer install --no-dev`
(`docker/fa-graphql exec composer install --no-dev -d /var/www/html/modules/sgw_sales`).
A relative `SGW_SALES_PATH` (or `ANORM_GRAPHQL_PATH`) is taken from where you run
`docker/fa-graphql` and made absolute.
`docker/fa-graphql test-extension sgw_sales` runs sgw_sales' GraphQL suites against
whatever is mounted or cloned; CI clones `SGW_SALES_REF` (`master`), and sgw_sales'
own CI checks this module out at `GRAPHQL_REF` (`main`). sgw_sales deploys first —
a module older than Release 4 has no extension loader, so the extension is inert there — and
each company then re-activates `sgw_sales` (its `update_1.4.sql`) and this module.
See the README's *Merging and deploying Release 4*.

## Anorm

`docker/fa-graphql anorm [args]` runs Anorm's generator in the container with
`--host=db --user=$DB_USER` already given. `anorm make` can only *prompt* for a
password (`-p`), so it wants a terminal:

    docker/fa-graphql anorm make fa_graphql 0_debtors_master -p \
        -m src/Model/ -n 'FA\GraphQL\Model'

## Co-developing anorm-graphql

`saygoweb/anorm-graphql` normally resolves from its `vcs` repository at the tagged
release (`^0.1`, `composer.json`) — nothing in this section is needed for ordinary
use of the stack.

Set `ANORM_GRAPHQL_PATH` in `docker/.env` to a host checkout of
saygoweb/anorm-graphql and run `docker/fa-graphql up`. It is mounted read-only at
`/opt/anorm-graphql`, where a composer `path` repository can find it:

    "repositories": [{"type": "path", "url": "/opt/anorm-graphql", "options": {"symlink": true}}]

A symlinked path package does not bring its own `vendor/`; this module's
`composer.json` has to satisfy its requirements.

**Generation (`bin/generate`) runs on the host, not in the container**: it needs
only PHP and this module's `vendor/`, no database. With a local anorm-graphql
checkout, point host generation at it with `ANORM_GRAPHQL_CHECKOUT` (for example
`ANORM_GRAPHQL_CHECKOUT=../../../anorm-graphql bin/generate`) rather than
`ANORM_GRAPHQL_PATH`: the `/opt/anorm-graphql` symlink this section sets up resolves
only inside the container.

## Mail

Nothing the stack sends leaves it. PHP's `sendmail_path` — for Apache and the CLI
alike (`docker/php.ini`) — is `docker/fa-mail-catcher`, which keeps each message,
headers, body and attachments, as one `.eml` file in `/var/mail-catcher` inside the
container. `invoiceEmail` runs FrontAccounting's invoice report (`rep107`) in a PHP
CLI child (`bin/fa-report`), and what it "sent" lands there; the email tests read
and delete only the files they caused.

    docker/fa-graphql mail              # list, newest first
    docker/fa-graphql mail show <file>  # print one message
    docker/fa-graphql mail clear        # delete them all

The catcher is part of the image, so a stack built before it needs
`docker/fa-graphql up --build`; until then the email tests are skipped. The seed
(`tests/data/seed.sql`) gives the demo company a From: address,
`accounts@example.com`, when it has none. A live server keeps its own
`sendmail_path`.

## A second PHP version

The environment wins over `docker/.env`, so this gives a second stack beside the
7.4 one rather than replacing it:

    PHP_VERSION=8.3 COMPOSE_PROJECT_NAME=fa-graphql-83 HTTP_PORT=8105 DB_PORT=3325 \
        docker/fa-graphql up --build

## Debugging

`display_errors` is off because every response is JSON. Errors go to the
container log and to FrontAccounting's own `tmp/errors.log`:

    docker/fa-graphql logs app        # Apache, PHP errors
    docker/fa-graphql logs errors     # FrontAccounting's error log

`docker/fa-graphql` has to be executable in git: `git update-index --chmod=+x
docker/fa-graphql docker/docker-entrypoint.sh docker/fa-mail-catcher bin/fa-report`
if `core.fileMode` is false.
