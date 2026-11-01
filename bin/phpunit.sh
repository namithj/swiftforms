#!/usr/bin/env bash
#
# Run PHPUnit against a throwaway WordPress core, never the developer's own site.
#
# Unless WP_TESTS_ABSPATH is already set (CI sets it), a core is installed into
# .cache/wp-core/<version>/ (git-ignored) with bin/install-wp-core.sh, exactly as
# bin/test-user-configs.sh does. Choose another version with WP_CORE_VERSION.
# Choose another PHP binary with PHP (for example PHP=php8.2).
#
#   bin/phpunit.sh --exclude-group user-configs

set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

PHP="${PHP:-php}"

if [[ -z "${WP_TESTS_ABSPATH:-}" ]]; then
	WP_CORE_VERSION="${WP_CORE_VERSION:-latest}"
	if ! WP_TESTS_ABSPATH="$(bash bin/install-wp-core.sh "${WP_CORE_VERSION}" "${PWD}/.cache/wp-core/${WP_CORE_VERSION}")"; then
		echo "Could not install a throwaway WordPress core." >&2
		exit 1
	fi
	export WP_TESTS_ABSPATH
fi

exec "${PHP}" vendor/phpunit/phpunit/phpunit "$@"
