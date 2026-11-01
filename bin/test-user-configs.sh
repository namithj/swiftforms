#!/usr/bin/env bash
#
# Run the R00 user-config regression group: the numbered configurations in
# tests/user-configs/, one after another, each in its own PHPUnit process,
# then the run-wide (global) snapshots.
#
#   bin/test-user-configs.sh            run everything
#   bin/test-user-configs.sh 00 15      run only configs whose slug starts with 00 or 15 (no global snapshots)
#   SF_UPDATE_SNAPSHOTS=1 bin/test-user-configs.sh   regenerate snapshots (never in CI)
#
# WordPress core: unless WP_TESTS_ABSPATH is already set (CI sets it), a
# throwaway core is installed into .cache/wp-core/<version>/ (git-ignored) with
# bin/install-wp-core.sh, so the real site's wp-content never loads. Choose
# another version with WP_CORE_VERSION and another PHP binary with PHP (for
# example PHP=php8.2). The database settings come from the WP_TESTS_DB_*
# variables (defaults in tests/phpunit/wp-tests-config.php).
#
# Config 19 (multisite) runs with WP_TESTS_MULTISITE=1.

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

if [[ "${SF_UPDATE_SNAPSHOTS:-0}" == "1" && ( -n "${CI:-}" || -n "${GITHUB_ACTIONS:-}" ) ]]; then
	echo "SF_UPDATE_SNAPSHOTS=1 is forbidden in CI." >&2
	exit 1
fi

PHPUNIT=("${PHP}" vendor/phpunit/phpunit/phpunit --bootstrap tests/phpunit/bootstrap-user-configs.php --group user-configs --colors=never)

# The first PHPUnit process installs the test tables; the rest reuse them.
unset WP_TESTS_SKIP_INSTALL
unset WP_TESTS_MULTISITE

slugs=()
for file in tests/user-configs/[0-9][0-9]-*.php; do
	slug="$(basename "${file}" .php)"
	if [[ $# -gt 0 ]]; then
		wanted=0
		for prefix in "$@"; do
			[[ "${slug}" == "${prefix}"* ]] && wanted=1
		done
		[[ ${wanted} -eq 0 ]] && continue
	fi
	slugs+=("${slug}")
done

total=${#slugs[@]}
if [[ ${total} -eq 0 ]]; then
	echo "No user config matches: $*" >&2
	exit 1
fi
failed=0
passed=0
start=${SECONDS}
last_mode="single"

# Run one PHPUnit filter and report PASS or FAIL. Output is shown only on failure.
# $3 is "multi" for a multisite run; switching between modes reinstalls the tables.
run_filter() {
	local label="$1" filter="$2" mode="${3:-single}" out status
	if [[ "${mode}" != "${last_mode}" ]]; then
		unset WP_TESTS_SKIP_INSTALL
		last_mode="${mode}"
	fi
	if [[ "${mode}" == "multi" ]]; then
		out="$(WP_TESTS_MULTISITE=1 "${PHPUNIT[@]}" --filter "${filter}" 2>&1)"
	else
		out="$("${PHPUNIT[@]}" --filter "${filter}" 2>&1)"
	fi
	status=$?
	export WP_TESTS_SKIP_INSTALL=1
	# A run that executed nothing, or skipped or marked tests risky, is not a pass.
	if [[ ${status} -eq 0 ]] && grep -Eq 'No tests executed|Skipped: [0-9]|Risky: [0-9]|Warnings: [0-9]|incomplete, skipped, or risky' <<<"${out}"; then
		status=1
	fi
	if [[ ${status} -eq 0 ]]; then
		echo "${label} … PASS"
		passed=$((passed + 1))
	else
		echo "${label} … FAIL"
		sed 's/^/    /' <<<"${out}"
		failed=$((failed + 1))
	fi
}

index=0
for slug in "${slugs[@]}"; do
	index=$((index + 1))
	mode="single"
	[[ "${slug}" == 19-* ]] && mode="multi"
	run_filter "$(printf '[%02d/%02d] %s' "${index}" "${total}" "${slug}")" "/::test_user_config with data set \"${slug}\"\$/" "${mode}"
done

if [[ $# -eq 0 ]]; then
	run_filter "[--/${total}] global snapshots (hooks, REST routes, schema, blocks)" '/::test_global_snapshots$/' "single"
fi

echo
echo "${passed} passed, ${failed} failed in $((SECONDS - start))s."
[[ ${failed} -eq 0 ]]
