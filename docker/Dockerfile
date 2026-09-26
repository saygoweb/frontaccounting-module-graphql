# Test/development image for the FrontAccounting GraphQL module.
#
# The module is not an application: it is an extension that only runs inside a
# FrontAccounting checkout, at modules/graphql. So the image supplies the host
# application — FrontAccounting is cloned to /var/www/html at build time — and
# this repository is bind-mounted over /var/www/html/modules/graphql at run time.
# The sibling stack in modules/api/docker is where this design comes from.
#
# Base and PHP source follow the sibling stack in the FrontAccounting repo
# (docker/Dockerfile there): plain Debian trixie with PHP from Ondřej Surý's
# repository, because the official php:7.4-* images are built on bullseye, whose
# LTS ended on 2026-08-31, and no 7.4 image on trixie exists or will.
FROM debian:trixie-slim

# Any version sury publishes for trixie: 7.4, 8.0 … 8.4. 7.4 is the floor Anorm
# and anorm-graphql support and the newest FrontAccounting 2.4.x is known to run
# on, so it is the default; 8.x checks the other end.
ARG PHP_VERSION=7.4
ARG INSTALL_XDEBUG=true

ENV DEBIAN_FRONTEND=noninteractive

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        gnupg \
        ; \
    install -d -m 0755 /etc/apt/keyrings; \
    curl -fsSL https://packages.sury.org/php/apt.gpg \
        -o /etc/apt/keyrings/sury-php.gpg; \
    echo "deb [signed-by=/etc/apt/keyrings/sury-php.gpg] https://packages.sury.org/php/ trixie main" \
        > /etc/apt/sources.list.d/sury-php.list; \
    apt-get clean; \
    rm -rf /var/lib/apt/lists/*

# mysqli is required (FA's includes/db/connect_db_mysqli.inc); gd is used by
# reporting/; mbstring, xml and zip are what composer expects. pdo_mysql, which
# Anorm connects through, comes in the same php-mysql package as mysqli.
# mariadb-client backs `fa-graphql db shell`.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        apache2 \
        mariadb-client \
        unzip \
        zip \
        libapache2-mod-php${PHP_VERSION} \
        php${PHP_VERSION}-cli \
        php${PHP_VERSION}-common \
        php${PHP_VERSION}-curl \
        php${PHP_VERSION}-gd \
        php${PHP_VERSION}-mbstring \
        php${PHP_VERSION}-mysql \
        php${PHP_VERSION}-xml \
        php${PHP_VERSION}-zip \
        ; \
    if [ "${INSTALL_XDEBUG}" = "true" ]; then \
        apt-get install -y --no-install-recommends php${PHP_VERSION}-xdebug; \
    fi; \
    apt-get clean; \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ---------------------------------------------------------------------------
# FrontAccounting itself
# ---------------------------------------------------------------------------
#
# Upstream FrontAccounting master by default: the module runs on an unmodified
# core. The cambell-prince fork is supported too, and CI builds both:
#
#   FA_REPO=https://github.com/cambell-prince/frontaccounting.git FA_REF=master-cp
#
# A shallow clone needs a branch or tag name; a bare commit sha will not work.
#
# Changing either needs `fa-graphql up --build`; the clone is a build step, so a
# running container keeps the tree it was built with.
ARG FA_REPO=https://github.com/FrontAccountingERP/FA.git
ARG FA_REF=master
RUN set -eux; \
    rm -rf /var/www/html; \
    git clone --depth 1 --branch "${FA_REF}" "${FA_REPO}" /var/www/html; \
    git -C /var/www/html log -1 --format='FrontAccounting %h %ad %s' --date=short; \
    git config --system --add safe.directory /var/www/html

# sgw_sales — the recurring-sales module this API drives in Release 2, and the
# other extension whose hooks share a process with ours. master is on Anorm ^3.2.1
# since its PR #7; the Anorm 1.6 before that could not be loaded beside ours.
# Release 2 will want feature/GenerateService (PR #8) until that merges.
# vendor/ is not in its repository.
ARG SGW_SALES_REPO=https://github.com/saygoweb/frontaccounting-module-sgw_sales.git
ARG SGW_SALES_REF=master
RUN set -eux; \
    git clone --depth 1 --branch "${SGW_SALES_REF}" "${SGW_SALES_REPO}" /var/www/html/modules/sgw_sales; \
    cd /var/www/html/modules/sgw_sales; \
    COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction --no-progress; \
    git config --system --add safe.directory /var/www/html/modules/sgw_sales

# Debian keeps a conf.d per SAPI rather than the single one the official php
# images have, and the CLI (phpunit, composer, php -l) must see the same
# settings as Apache.
COPY php.ini /etc/php/${PHP_VERSION}/apache2/conf.d/zz-fa-graphql.ini
COPY php.ini /etc/php/${PHP_VERSION}/cli/conf.d/zz-fa-graphql.ini

# Mail from the stack never leaves it: PHP's sendmail_path (php.ini) is this
# script, which keeps every message as a file for the tests. Writable by Apache
# (www-data) and by the CLI user alike, and not sticky, so either can clear it.
COPY fa-mail-catcher /usr/local/bin/fa-mail-catcher
RUN set -eux; \
    chmod 0755 /usr/local/bin/fa-mail-catcher; \
    mkdir -p /var/mail-catcher; \
    chmod 0777 /var/mail-catcher

# Port 8000, not 80, to match the sibling modules/api stack. tests/Http reaches
# it as http://localhost:8000 (FA_GRAPHQL_URL), because phpunit runs inside this
# container. The host port is mapped to it.
COPY apache-fa-graphql.conf /etc/apache2/sites-available/fa-graphql.conf
RUN set -eux; \
    printf 'Listen 8000\n' > /etc/apache2/ports.conf; \
    a2dissite 000-default; \
    a2ensite fa-graphql; \
    a2enmod rewrite

# FrontAccounting writes into its own tree at runtime — tmp/, company/<n>/js_cache
# for generated JavaScript, pdf_files, backup. .gitignore keeps most of them out
# of a clone, so Apache would meet a missing directory rather than an unwritable
# one.
RUN set -eux; \
    mkdir -p /var/www/html/tmp \
             /var/www/html/company/0/js_cache \
             /var/www/html/company/0/pdf_files \
             /var/www/html/company/0/backup \
             /var/www/html/company/0/images \
             /var/www/html/modules/graphql

# Apache runs as www-data remapped to the host user's ids, so anything written
# into the bind-mounted checkout — vendor/ above all — stays owned by you on the
# host rather than by root. Skipped when they already match, because usermod
# refuses to assign an id the account already holds.
ARG USER_UID=1000
ARG USER_GID=1000
RUN set -eux; \
    if [ "$(id -u www-data)" != "${USER_UID}" ] || [ "$(id -g www-data)" != "${USER_GID}" ]; then \
        groupmod -o -g "${USER_GID}" www-data; \
        usermod -o -u "${USER_UID}" -g "${USER_GID}" www-data; \
    fi; \
    chown -R www-data:www-data /var/www/html/tmp /var/www/html/company

# phpenmod/phpdismod in the entrypoint need to know which version to act on.
ENV PHP_VERSION=${PHP_VERSION}
ENV FA_ROOT=/var/www/html

WORKDIR /var/www/html/modules/graphql

EXPOSE 8000

COPY docker-entrypoint.sh /usr/local/bin/fa-graphql-entrypoint
RUN chmod +x /usr/local/bin/fa-graphql-entrypoint

ENTRYPOINT ["fa-graphql-entrypoint"]
CMD ["apache2ctl", "-D", "FOREGROUND"]
