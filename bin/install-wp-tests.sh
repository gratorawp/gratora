#!/usr/bin/env bash
#
# Provision the WordPress integration-test environment for the `integration`
# PHPUnit suite. The test framework itself ships in vendor/wp-phpunit/wp-phpunit
# (a composer dependency), so this only needs to: download WP core, create the
# test database, and write a wp-tests-config.php that points at both.
#
# Usage:
#   bin/install-wp-tests.sh [db-name] [db-user] [db-pass] [db-host] [wp-version]
# Example (Homebrew MySQL, root/no-password):
#   bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1 latest
#
# Override locations with WP_CORE_DIR / WP_TESTS_DIR env vars (defaults under
# the system temp dir, matching tests/integration-bootstrap.php).
set -euo pipefail

DB_NAME=${1:-wordpress_test}
DB_USER=${2:-root}
DB_PASS=${3:-}
DB_HOST=${4:-127.0.0.1}
WP_VERSION=${5:-latest}

WP_CORE_DIR=${WP_CORE_DIR:-/tmp/wordpress}
WP_TESTS_DIR=${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}

# 1. WordPress core
if [ ! -f "${WP_CORE_DIR}/wp-load.php" ]; then
    echo "Downloading WordPress core (${WP_VERSION}) -> ${WP_CORE_DIR}"
    mkdir -p "${WP_CORE_DIR}"
    if [ "${WP_VERSION}" = "latest" ]; then
        ARCHIVE_URL="https://wordpress.org/latest.tar.gz"
    else
        ARCHIVE_URL="https://wordpress.org/wordpress-${WP_VERSION}.tar.gz"
    fi
    curl -sL "${ARCHIVE_URL}" | tar --strip-components=1 -xz -C "${WP_CORE_DIR}"
else
    echo "WordPress core already present at ${WP_CORE_DIR}"
fi

# 2. Test database (drop + recreate for a clean slate)
MYSQL=(mysql --protocol=tcp "-h${DB_HOST}" "-u${DB_USER}")
if [ -n "${DB_PASS}" ]; then
    MYSQL+=("-p${DB_PASS}")
fi
echo "Recreating database ${DB_NAME} on ${DB_HOST}"
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`${DB_NAME}\`; CREATE DATABASE \`${DB_NAME}\`;"

# 3. wp-tests-config.php (wp-phpunit supplies the includes; this wires DB + ABSPATH)
mkdir -p "${WP_TESTS_DIR}"
cat > "${WP_TESTS_DIR}/wp-tests-config.php" <<PHP
<?php
define( 'ABSPATH', '${WP_CORE_DIR}/' );
define( 'WP_DEFAULT_THEME', 'default' );

define( 'DB_NAME', '${DB_NAME}' );
define( 'DB_USER', '${DB_USER}' );
define( 'DB_PASSWORD', '${DB_PASS}' );
define( 'DB_HOST', '${DB_HOST}' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

\$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Dono Test Suite' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
PHP

echo "Ready: core=${WP_CORE_DIR} config=${WP_TESTS_DIR}/wp-tests-config.php db=${DB_NAME} (prefix wptests_)"
