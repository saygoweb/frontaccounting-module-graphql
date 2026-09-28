#!/bin/sh
# graphql's CI. Runs inside the FrontAccounting CI image (docker/ci in
# cambell-prince/frontaccounting), from this module's directory, on the demo
# dataset, with sgw_sales activated alongside. It checks what docker/fa-graphql
# ci checks against its own stack: lint, analyze, the suites, sgw_sales'
# GraphQL suite, and the default-company report.
set -eu
: "${FA_ROOT:?run this inside the FrontAccounting CI image (docker/ci/plugin-test.sh)}"
export FA_DB_PREFIX="${FA_DB_PREFIX:-0_}"
export FA_GRAPHQL_URL="${FA_URL%/}/modules/graphql/"

echo "==> lint"
composer run lint
composer run cs:check

echo "==> analyze"
composer run analyze

echo "==> config and seed"
if [ ! -f config_graphql.php ]; then
    secret="$(php -r 'echo bin2hex(random_bytes(24));')"
    printf "<?php\n\n/* Written by tools/ci.sh for the CI image. Not for production:\n\tsee config_graphql.example.php. */\n\nreturn array(\n    'secret' => '%s',\n    'allow_insecure_login' => true,\n    'debug' => true,\n);\n" \
        "$secret" > config_graphql.php
fi
sh tests/data/seed.sh

echo "==> phpunit"
# Apache creates tmp/faillog.php during activation; SessionPipelineTest's touch() with a time needs ownership.
rm -f "$FA_ROOT/tmp/faillog.php"
composer run test

if [ -f ../sgw_sales/phpunit-graphql.xml ]; then
    echo "==> sgw_sales' GraphQL suite"
    php vendor/bin/phpunit -c ../sgw_sales/phpunit-graphql.xml --fail-on-skipped
fi

# ReportDefaultCompanyTest needs a company other than 0 to be the default.
# config_db.php and company/1 are put back whatever happens.
cfg="$FA_ROOT/config_db.php"
c1="$FA_ROOT/company/1"
mark=second-company-test
second_company_remove() {
    if grep -q "$mark" "$cfg"; then
        mv "$cfg.before-second-company" "$cfg"
    else
        rm -f "$cfg.before-second-company"
    fi
    if [ -f "$c1/.$mark" ]; then rm -rf "$c1"; fi
    rm -rf "$c1.$mark"
}
second_company_add() {
    if [ -d "$c1" ] && [ ! -f "$c1/.$mark" ]; then
        echo "company/1 exists and was not made by tools/ci.sh: refusing" >&2
        exit 3
    fi
    cp "$cfg" "$cfg.before-second-company"
    sed -i 's/^\$def_coy = [0-9]*;/$def_coy = 1;/' "$cfg"
    printf '%s\n' "/* $mark: tools/ci.sh */" \
        '$db_connections[1] = array_merge($db_connections[0], array("name" => "Second (test)"));' >> "$cfg"
    rm -rf "$c1.$mark"
    cp -R "$FA_ROOT/company/0" "$c1.$mark"
    touch "$c1.$mark/.$mark"
    mv "$c1.$mark" "$c1"
    php -r '
        $installed_extensions = array();
        include $argv[1];
        foreach ($installed_extensions as $k => $e)
            if ($e["package"] === "graphql") $installed_extensions[$k]["active"] = false;
        file_put_contents($argv[1], "<?php\n\n\$installed_extensions = " . var_export($installed_extensions, true) . ";\n");' \
        "$c1/installed_extensions.php"
}

echo "==> as the default company of two"
trap second_company_remove EXIT
second_company_remove
second_company_add
composer run test -- --fail-on-skipped --filter ReportDefaultCompanyTest
trap - EXIT
second_company_remove
echo "==> all checks passed"
