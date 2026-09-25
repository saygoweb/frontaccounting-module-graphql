# FrontAccounting GraphQL module

A GraphQL API for [FrontAccounting](https://frontaccounting.com/), delivered as a
module (extension) that lives at `modules/graphql` inside a FrontAccounting tree.

**Status: Foundation.** Authentication, the FrontAccounting session and Type
generation work end to end. One generated entity so far: `SalesType`, read-only.

## Calling the API

Requirements: the `cambell-prince/frontaccounting` fork; `config_graphql.php` copied
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
