#!/bin/sh
# Example hosting-billing data for a development environment: the HDOM/HGEN1
# items and prices (tests/data/dev-fixtures.sql), then a reseller customer, its
# recurring orders, an invoice and a payment created through the GraphQL API
# itself (tools/fixtures.php). Idempotent. From the FrontAccounting checkout:
#   docker/ci/plugin-dev.sh --env graphql exec --dir modules/graphql sh tools/dev-fixtures.sh
set -eu
: "${FA_ROOT:?run this inside the FrontAccounting CI image (plugin-dev.sh exec)}"
here="$(cd "$(dirname "$0")/.." && pwd)"
mariadb -h "$FA_DB_HOST" -u "$FA_DB_USER" -p"$FA_DB_PASSWORD" "$FA_DB_NAME" < "$here/tests/data/dev-fixtures.sql"
echo "loaded tests/data/dev-fixtures.sql"
php "$here/tools/fixtures.php" "${FA_URL%/}/modules/graphql/"
