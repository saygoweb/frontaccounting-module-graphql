# Docker test stack

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
| FrontAccounting | cloned into the image at build time from `FA_REPO` / `FA_REF` — upstream `master` by default |
| this checkout | bind-mounted at `/var/www/html/modules/graphql`, so an edit is live on the next request |
| `config.php`, `config_db.php`, `lang/installed_languages.inc` | written by the entrypoint, into the image's FA tree |
| the two `installed_extensions.php` | written by the entrypoint on every start, with this module **registered and active for company 0** — so `hooks.php` is loaded and `SA_GRAPHQL` exists, as it would after Setup → Install/Activate Extensions |
| `vendor/` | installed by `up`, into your checkout, owned by you |

Nothing is written into your checkout except `vendor/` and `composer.lock`.

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

## Anorm

`docker/fa-graphql anorm [args]` runs Anorm's generator in the container with
`--host=db --user=$DB_USER` already given. `anorm make` can only *prompt* for a
password (`-p`), so it wants a terminal:

    docker/fa-graphql anorm make fa_graphql 0_debtors_master -p \
        -m src/Model/ -n 'FA\GraphQL\Model'

## anorm-graphql before it has a release

Set `ANORM_GRAPHQL_PATH` in `docker/.env` to a host checkout of
saygoweb/anorm-graphql and run `docker/fa-graphql up`. It is mounted read-only at
`/opt/anorm-graphql`, where a composer `path` repository can find it:

    "repositories": [{"type": "path", "url": "/opt/anorm-graphql", "options": {"symlink": true}}]

A symlinked path package does not bring its own `vendor/`; this module's
`composer.json` has to satisfy its requirements.

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
docker/fa-graphql docker/docker-entrypoint.sh` if `core.fileMode` is false.
