#!/usr/bin/env bash
#
# Build a throwaway WordPress for the browser tests without Docker (the local stand-in for wp-env).
#
#   bin/setup-e2e-local-site.sh <dir>
#
# Environment (all optional):
#   WP_VERSION   latest (default), 6.8, ...         the WordPress to download
#   WP_BASE_URL  http://localhost:8889 (default)    where the site will be served
#   DB_HOST DB_USER DB_PASSWORD DB_NAME             defaults: db, root, root, swiftforms_e2e (dropped and created)
#
# It downloads WordPress into <dir>, writes a wp-config.php with the clock constant (SWIFTFORMS_TEST_NOW), installs core, and
# links in this plugin, the three fixture themes and the clock mu-plugin (tests/e2e/mu/sf-test-clock.php). Then:
#
#   cd <dir> && wp server --host=localhost --port=8889 &            # serve it
#   SF_E2E_WP_PATH=<dir> npm run test:e2e:configs                  # S12 and S13 for every config
#   SF_E2E_NOW_FILE=$PWD/tests/e2e/.state/now WP_BASE_URL=http://localhost:8889 npx playwright test   # the other specs
#
# The database is dropped and created: give it a name nothing else uses.

set -euo pipefail

DIR="${1:?usage: bin/setup-e2e-local-site.sh <dir>}"
PLUGIN="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WP_VERSION="${WP_VERSION:-latest}"
BASE_URL="${WP_BASE_URL:-http://localhost:8889}"
DB_HOST="${DB_HOST:-db}"
DB_USER="${DB_USER:-root}"
DB_PASSWORD="${DB_PASSWORD:-root}"
DB_NAME="${DB_NAME:-swiftforms_e2e}"

mysql -h"${DB_HOST}" -u"${DB_USER}" -p"${DB_PASSWORD}" -e "DROP DATABASE IF EXISTS \`${DB_NAME}\`; CREATE DATABASE \`${DB_NAME}\` CHARACTER SET utf8mb4"
rm -rf "${DIR}"
mkdir -p "${DIR}"
cd "${DIR}"
if [[ "${WP_VERSION}" == "latest" ]]; then
	wp core download --force
else
	wp core download --version="${WP_VERSION}" --force
fi
wp config create --dbname="${DB_NAME}" --dbuser="${DB_USER}" --dbpass="${DB_PASSWORD}" --dbhost="${DB_HOST}" --skip-check --extra-php <<'PHP'
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'SWIFTFORMS_TEST_NOW', 1795082400 );
define( 'WP_ENVIRONMENT_TYPE', 'local' );
PHP
wp core install --url="${BASE_URL}" --title="SwiftForms tests" --admin_user=admin --admin_password=password --admin_email=admin@example.org --skip-email
mkdir -p wp-content/mu-plugins
ln -sfn "${PLUGIN}" wp-content/plugins/swiftforms
for theme in sf-test-block sf-test-classic sf-test-block-files; do
	ln -sfn "${PLUGIN}/tests/user-configs/themes/${theme}" "wp-content/themes/${theme}"
done
ln -sfn "${PLUGIN}/tests/e2e/mu/sf-test-clock.php" wp-content/mu-plugins/sf-test-clock.php
wp plugin activate swiftforms
wp rewrite structure '/%postname%/' --hard
echo "Site ready in ${DIR}. Serve it with: cd ${DIR} && wp server --host=localhost --port=$(printf '%s' "${BASE_URL}" | sed -E 's#.*:([0-9]+).*#\1#')"
