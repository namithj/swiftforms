#!/usr/bin/env bash
#
# Build the release ZIP: swiftforms.zip (no version in the file name, so that
# the "latest release" download URL stays stable).
#
#   bin/build-release.sh [output-dir]
#
# Steps, in a scratch copy of the plugin: install the dependencies (Strauss
# writes vendor-prefixed/), keep only production packages, build the JavaScript
# when there is a package.json, copy everything except what .distignore lists,
# zip it, then assert on the ZIP contents.
set -euo pipefail

PLUGIN_SLUG="swiftforms"
ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
OUT_DIR="${1:-${ROOT}/dist}"
PHP="${PHP:-php}"
VERSION="$( grep -oP '^\s*\*\s*Version:\s*\K[0-9.]+' "${ROOT}/${PLUGIN_SLUG}.php" )"
README_TAG="$( grep -oP '^Stable tag:\s*\K[0-9.]+' "${ROOT}/readme.txt" )"
STAGING_ROOT="$( mktemp -d )"

cleanup() {
	rm -rf "${STAGING_ROOT}"
}
trap cleanup EXIT

if [[ "${VERSION}" != "${README_TAG}" ]]; then
	echo "Version mismatch: plugin header ${VERSION} vs readme ${README_TAG}" >&2
	exit 1
fi

# Build in a scratch copy so the developer's vendor/ (with its dev packages)
# is never touched.
WORK_ROOT="${STAGING_ROOT}/work"
mkdir -p "${WORK_ROOT}"
rsync -a --exclude='/.git' --exclude='/.cache' --exclude='/dist' --exclude='/build' --exclude='/node_modules' --exclude='/vendor' --exclude='/vendor-prefixed' "${ROOT}/" "${WORK_ROOT}/"
cd "${WORK_ROOT}"

# Strauss is a dev dependency and runs from composer's post-install hook, so
# install with dev packages first (this writes vendor-prefixed/), then drop the
# dev packages without running the hooks again.
composer install --no-interaction --prefer-dist
composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

# Removing dev packages makes Composer reinstall the unprefixed frameworks that
# Strauss had deleted. Only the prefixed copies may ship: a plain copy would
# collide with another plugin's copy of the same libraries.
rm -rf vendor/pedalcms
composer dump-autoload --no-dev --optimize --no-scripts --no-interaction

if [[ ! -f vendor-prefixed/autoload.php ]]; then
	echo "vendor-prefixed/autoload.php is missing: Strauss did not run." >&2
	exit 1
fi

if [[ -f package.json ]]; then
	npm ci
	npm run build
else
	echo "No package.json yet: skipping the JavaScript build."
fi

mkdir -p "${OUT_DIR}"
OUT_DIR="$( cd "${OUT_DIR}" && pwd )"
ZIP_PATH="${OUT_DIR}/${PLUGIN_SLUG}.zip"
PACKAGE_ROOT="${STAGING_ROOT}/${PLUGIN_SLUG}"

mkdir -p "${PACKAGE_ROOT}"
rsync -a --exclude-from="${ROOT}/.distignore" "${WORK_ROOT}/" "${PACKAGE_ROOT}/"
rm -f "${ZIP_PATH}"
( cd "${STAGING_ROOT}" && zip -rq "${ZIP_PATH}" "${PLUGIN_SLUG}" )

ZIP_VERSION="$( unzip -p "${ZIP_PATH}" "${PLUGIN_SLUG}/${PLUGIN_SLUG}.php" | grep -oP '^\s*\*\s*Version:\s*\K[0-9.]+' )"
ZIP_README_TAG="$( unzip -p "${ZIP_PATH}" "${PLUGIN_SLUG}/readme.txt" | grep -oP '^Stable tag:\s*\K[0-9.]+' )"

if [[ "${ZIP_VERSION}" != "${VERSION}" || "${ZIP_README_TAG}" != "${VERSION}" ]]; then
	echo "Built zip version mismatch: plugin header ${ZIP_VERSION} vs readme ${ZIP_README_TAG} vs expected ${VERSION}" >&2
	exit 1
fi

ZIP_ENTRIES="$( unzip -Z1 "${ZIP_PATH}" )"
FAILED=0

# Must be in the ZIP: the namespaced frameworks and both autoloaders. build/ is
# required once the JavaScript build exists.
required=( "${PLUGIN_SLUG}.php" "readme.txt" "composer.json" "includes/" "vendor/autoload.php" "vendor-prefixed/autoload.php" "vendor-prefixed/pedalcms/" )
if [[ -f package.json ]]; then
	required+=( "build/" )
fi
for path in "${required[@]}"; do
	if ! grep -q "^${PLUGIN_SLUG}/${path}" <<< "${ZIP_ENTRIES}"; then
		echo "Missing from the release ZIP: ${path}" >&2
		FAILED=1
	fi
done

# Must not be in the ZIP: planning files, tests, sources, scripts, dev tooling.
forbidden=( "vendor/pedalcms/" ".ai/" "tests/" "src/" "bin/" ".github/" "node_modules/" "composer.lock" "vendor/bin/" "vendor/phpunit/" "vendor/squizlabs/" "vendor/brianhenryie/" "vendor-prefixed/pedalcms/cassette-cmf-block-builder/tests/" )
for path in "${forbidden[@]}"; do
	if grep -q "^${PLUGIN_SLUG}/${path}" <<< "${ZIP_ENTRIES}"; then
		echo "Must not be in the release ZIP: ${path}" >&2
		FAILED=1
	fi
done

# Every runtime file must survive packaging. rsync matches an unanchored
# .distignore pattern at any depth, so compare the shipped folders file by file.
while IFS= read -r relative_path; do
	if ! grep -qxF "${PLUGIN_SLUG}/${relative_path}" <<< "${ZIP_ENTRIES}"; then
		echo "Missing from the release ZIP: ${relative_path}" >&2
		FAILED=1
	fi
done < <( cd "${WORK_ROOT}" && find includes -type f | sort )

if (( FAILED > 0 )); then
	echo "The release ZIP failed its content checks — check .distignore." >&2
	exit 1
fi

# A smoke test: the packaged plugin must load without the dev dependencies.
"${PHP}" -r '
	define( "ABSPATH", "/" );
	require $argv[1] . "/vendor-prefixed/autoload.php";
	require $argv[1] . "/vendor/autoload.php";
	if ( ! class_exists( "SwiftForms\\Vendor\\PedalCMS\\CassetteCMFBlocks\\CassetteCMFBlocks" ) || ! class_exists( "SwiftForms\\Plugin" ) ) {
		fwrite( STDERR, "Packaged autoloaders do not resolve the plugin classes.\n" );
		exit( 1 );
	}
' -- "${STAGING_ROOT}/${PLUGIN_SLUG}"

echo "Built ${ZIP_PATH}"
echo "Verified ${VERSION} and $( grep -c . <<< "${ZIP_ENTRIES}" ) entries: vendor-prefixed/ present, .ai/ tests/ src/ bin/ and dev dependencies absent."
( cd "${OUT_DIR}" && sha256sum "${PLUGIN_SLUG}.zip" )
