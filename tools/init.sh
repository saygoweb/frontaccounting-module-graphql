#!/bin/sh
# Prepares this module in the FrontAccounting CI image after activation: a
# config_graphql.php if there is none (a random secret, insecure login allowed,
# debug on — never for production), and the users and roles the tests and the
# dev fixtures sign in as (tests/data/seed.sh). Run by tools/ci.sh, and by the
# CI package's development environments after they activate this module.
set -eu
: "${FA_ROOT:?run this inside the FrontAccounting CI image (docker/ci)}"
here="$(cd "$(dirname "$0")/.." && pwd)"
if [ ! -f "$here/config_graphql.php" ]; then
    secret="$(php -r 'echo bin2hex(random_bytes(24));')"
    printf "<?php\n\n/* Written by tools/init.sh for the CI image. Not for production:\n\tsee config_graphql.example.php. */\n\nreturn array(\n    'secret' => '%s',\n    'allow_insecure_login' => true,\n    'debug' => true,\n);\n" \
        "$secret" > "$here/config_graphql.php"
    echo "wrote config_graphql.php"
fi
sh "$here/tests/data/seed.sh"
