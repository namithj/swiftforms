#!/usr/bin/env bash
#
# Writes a single version number into every version-bearing published file.
#
# SwiftForms carries its version in three places that must never drift apart:
#   1. the plugin header in swiftforms.php  (what WordPress shows)
#   2. the SWIFTFORMS_VERSION constant        (asset cache-busting)
#   3. Stable tag in readme.txt                (what WordPress.org serves)
#
# package.json carries no version on purpose: it is private and never published.
#
# Usage:
#   bin/set-version.sh <version>          Write <version> into every file.
#   bin/set-version.sh --check <version>  Verify every file already reads <version>.
#   bin/set-version.sh --check-current    Verify the files agree with each other.
#
# <version> may be given as "1.0.0" or "v1.0.0"; a single leading "v" is
# stripped and the normalized form is written everywhere.

set -euo pipefail

MODE=''
VERSION=''

case "${1:-}" in
	--check)
		MODE='check'
		VERSION="${2:-}"
		;;
	--check-current)
		MODE='check-current'
		;;
	'')
		echo "Usage: $0 <version> | --check <version> | --check-current" >&2
		exit 2
		;;
	*)
		MODE='write'
		VERSION="${1}"
		;;
esac

# Normalize: strip a single leading "v" (v1.0.0 -> 1.0.0).
VERSION="${VERSION#v}"

if [[ "${MODE}" != 'check-current' && ! "${VERSION}" =~ ^[0-9]+\.[0-9]+\.[0-9]+([-.][0-9A-Za-z.]+)?$ ]]; then
	echo "Invalid version '${VERSION}'. Expected X.Y.Z (optionally with a suffix), e.g. 1.0.0 or v1.0.0." >&2
	exit 1
fi

# Resolve the repository root from this script's location so it works from anywhere.
REPO_ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
cd "${REPO_ROOT}"

PLUGIN_MAIN="$( grep -lE '^[[:space:]]*\*[[:space:]]*Plugin Name:' ./*.php | head -n 1 || true )"
if [[ -z "${PLUGIN_MAIN}" ]]; then
	echo "Unable to detect the plugin main file in the repository root." >&2
	exit 1
fi
PLUGIN_MAIN="$( basename "${PLUGIN_MAIN}" )"

# check_synced_versions [expected]
# Fails when the three files disagree with each other, or with [expected].
check_synced_versions() {
	local expected="${1:-}"
	local header constant readme

	header="$( grep -oP '^\s*\*\s*Version:\s*\K\S+' "${PLUGIN_MAIN}" | head -n 1 || true )"
	constant="$( grep -oP "define\( 'SWIFTFORMS_VERSION', '\K[^']+" "${PLUGIN_MAIN}" | head -n 1 || true )"
	readme="$( grep -oP '^Stable tag:\s*\K\S+' readme.txt | head -n 1 || true )"

	if [[ -z "${header}" || -z "${constant}" || -z "${readme}" ]]; then
		printf 'Could not read a version from every file: plugin header=%s, SWIFTFORMS_VERSION=%s, readme stable tag=%s\n' \
			"${header:-<missing>}" "${constant:-<missing>}" "${readme:-<missing>}" >&2
		exit 1
	fi

	if [[ "${header}" != "${constant}" || "${header}" != "${readme}" ]]; then
		printf 'Version mismatch: plugin header=%s, SWIFTFORMS_VERSION=%s, readme stable tag=%s\n' \
			"${header}" "${constant}" "${readme}" >&2
		exit 1
	fi

	if [[ -n "${expected}" && "${expected}" != "${header}" ]]; then
		printf 'Version mismatch: expected=%s, plugin header=%s, SWIFTFORMS_VERSION=%s, readme stable tag=%s\n' \
			"${expected}" "${header}" "${constant}" "${readme}" >&2
		exit 1
	fi

	printf 'Version %s is consistent across %s and readme.txt.\n' "${header}" "${PLUGIN_MAIN}"
}

if [[ "${MODE}" == 'check' ]]; then
	check_synced_versions "${VERSION}"
	exit 0
fi

if [[ "${MODE}" == 'check-current' ]]; then
	check_synced_versions
	exit 0
fi

# apply <file> <sed-expression> <verify-grep-pattern>
# Runs the substitution, then fails loudly if the expected value is not present
# afterwards, so a pattern that silently matched nothing cannot ship.
apply() {
	local file="$1" expression="$2" verify="$3"

	[[ -f "${file}" ]] || { echo "Expected file '${file}' not found." >&2; exit 1; }

	sed -i -E "${expression}" "${file}"

	if ! grep -qE "${verify}" "${file}"; then
		echo "Failed to set the version in ${file} (pattern did not match)." >&2
		exit 1
	fi

	echo "  ${file} -> ${VERSION}"
}

echo "Setting version to ${VERSION}"

# Plugin header:  * Version: 1.0.0
apply "${PLUGIN_MAIN}" \
	"s/^([[:space:]]*\*[[:space:]]*Version:[[:space:]]*).*/\1${VERSION}/" \
	"^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*${VERSION}[[:space:]]*$"

# Runtime constant:  define( 'SWIFTFORMS_VERSION', '1.0.0' );
apply "${PLUGIN_MAIN}" \
	"s/(define\\( 'SWIFTFORMS_VERSION', ')[^']*('[[:space:]]*\\);)/\\1${VERSION}\\2/" \
	"define\\( 'SWIFTFORMS_VERSION', '${VERSION}' \\);"

# WordPress readme:  Stable tag: 1.0.0
apply "readme.txt" \
	"s/^(Stable tag:[[:space:]]*).*/\1${VERSION}/" \
	"^Stable tag:[[:space:]]*${VERSION}[[:space:]]*$"

check_synced_versions "${VERSION}"
echo "Done."
