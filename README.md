# FrontAccounting GraphQL module

A GraphQL API for [FrontAccounting](https://frontaccounting.com/), delivered as a
module (extension) that lives at `modules/graphql` inside a FrontAccounting tree.

**Status: skeleton.** The endpoint answers `{ apiVersion }` and nothing else. It
does not yet start a FrontAccounting session or touch the database.

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
