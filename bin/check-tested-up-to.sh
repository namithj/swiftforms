#!/usr/bin/env bash
#
# Release gate: fail when readme.txt "Tested up to" is behind the current
# WordPress major release (MAJOR.MINOR, e.g. 7.1) reported by WordPress.org.
#
#   bin/check-tested-up-to.sh

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

tested="$(sed -n 's/^Tested up to:[[:space:]]*\([0-9][0-9.]*\).*/\1/p' readme.txt | head -n 1)"
if [[ -z "${tested}" ]]; then
	echo "Could not read 'Tested up to' from readme.txt." >&2
	exit 1
fi

if ! response="$(curl -fsSL --max-time 30 "https://api.wordpress.org/core/version-check/1.7/")"; then
	echo "Could not reach the WordPress.org version-check API; cannot verify 'Tested up to'." >&2
	exit 1
fi

current="$(printf '%s' "${response}" | php -r '
	$data = json_decode( file_get_contents( "php://stdin" ), true );
	echo isset( $data["offers"][0]["current"] ) ? $data["offers"][0]["current"] : "";
')"
if [[ -z "${current}" ]]; then
	echo "The WordPress.org version-check API returned no current version." >&2
	exit 1
fi

# WordPress "major" is the first two parts of the version.
major() { cut -d. -f1,2 <<<"$1"; }
tested_major="$(major "${tested}")"
current_major="$(major "${current}")"

lowest="$(printf '%s\n%s\n' "${tested_major}" "${current_major}" | sort -V | head -n 1)"
if [[ "${tested_major}" != "${current_major}" && "${lowest}" == "${tested_major}" ]]; then
	echo "readme.txt says 'Tested up to: ${tested}', but the current WordPress release is ${current} (${current_major}). Test and bump it before releasing." >&2
	exit 1
fi

echo "OK: Tested up to ${tested} covers the current WordPress release ${current}."
