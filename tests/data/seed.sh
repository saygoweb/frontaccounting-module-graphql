#!/bin/sh
# Load seed.sql into the FrontAccounting CI image's database, with SS_GRAPHQL
# and SA_GRAPHQL worked out from the extension id graphql has there. seed.sql
# is written for extension 1 (91136 / 91236), as docker/fa-graphql registers
# it; the CI image numbers modules in the order they are activated.
set -eu
here="$(cd "$(dirname "$0")" && pwd)"
ext="$(fa-ci-ext-id graphql)"
section=$(( (ext << 16) | (100 << 8) ))
area=$(( section | 100 ))
sed -e "s/91136/$section/g" -e "s/91236/$area/g" "$here/seed.sql" \
    | mariadb -h "$FA_DB_HOST" -u "$FA_DB_USER" -p"$FA_DB_PASSWORD" "$FA_DB_NAME"
echo "seeded: graphql is extension $ext (SS_GRAPHQL $section, SA_GRAPHQL $area)"
