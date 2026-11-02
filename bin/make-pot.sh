#!/usr/bin/env bash
#
# Extract the translatable strings with WP-CLI and fail when that does not work.
#
# The .pot is not committed (the wordpress.org translation platform builds its own
# from the plugin source), so this writes to a temporary path unless you pass one.
# The languages/ folder ships so that the "Domain Path" header is true.
#
#   bin/make-pot.sh                  extract to .cache/swiftforms.pot and check it
#   bin/make-pot.sh languages/x.pot  extract to another file
#
# Needs WP-CLI (https://wp-cli.org). Choose another binary with WP.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

WP="${WP:-wp}"
OUT="${1:-.cache/swiftforms.pot}"

if ! command -v "${WP}" >/dev/null 2>&1; then
	echo "WP-CLI (${WP}) not found." >&2
	exit 1
fi

mkdir -p "$(dirname "${OUT}")"
rm -f "${OUT}"

"${WP}" i18n make-pot . "${OUT}" \
	--slug=swiftforms \
	--domain=swiftforms \
	--exclude=node_modules,vendor,vendor-prefixed,tests,build,dist,.cache

# A pot without the plugin's own strings means the extraction silently found nothing.
if [[ ! -s "${OUT}" ]] || ! grep -q 'msgid "Thank you. Your message has been sent."' "${OUT}"; then
	echo "The extracted .pot is missing or does not contain SwiftForms' own strings: ${OUT}" >&2
	exit 1
fi

echo "OK: $(grep -c '^msgid ' "${OUT}") strings in ${OUT}"
