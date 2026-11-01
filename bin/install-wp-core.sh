#!/usr/bin/env bash
#
# Download a WordPress core and print its path.
#
#   bin/install-wp-core.sh [version] [destination]
#
# version: "latest" (default), "trunk" or "nightly" (the nightly build), an exact
# version such as 6.7.2, or a bare minor such as 6.7, which resolves to the
# newest patch release of that minor.
set -euo pipefail

VERSION="${1:-latest}"
DEST="${2:-${RUNNER_TEMP:-/tmp}/wordpress}"

if [[ -f "${DEST}/wp-settings.php" ]]; then
	echo "${DEST}"
	exit 0
fi

ARCHIVE_KIND="tar"
if [[ "${VERSION}" == "latest" ]]; then
	ARCHIVE_URL="https://wordpress.org/latest.tar.gz"
elif [[ "${VERSION}" == "trunk" || "${VERSION}" == "nightly" ]]; then
	ARCHIVE_URL="https://wordpress.org/nightly-builds/wordpress-latest.zip"
	ARCHIVE_KIND="zip"
else
	if [[ "${VERSION}" =~ ^[0-9]+\.[0-9]+$ ]]; then
		# Newest patch of the minor, from the list of releases WordPress.org knows.
		VERSION="$(curl -fsSL "https://api.wordpress.org/core/stable-check/1.0/" | php -r '
			$minor  = $argv[1];
			$data   = json_decode( file_get_contents( "php://stdin" ), true );
			$best   = null;
			foreach ( is_array( $data ) ? array_keys( $data ) : [] as $candidate ) {
				$candidate = (string) $candidate;
				if ( $candidate === $minor || 0 === strpos( $candidate, $minor . "." ) ) {
					if ( null === $best || version_compare( $candidate, $best, ">" ) ) {
						$best = $candidate;
					}
				}
			}
			if ( null === $best ) {
				fwrite( STDERR, "No release found for " . $minor . "\n" );
				exit( 1 );
			}
			echo $best;
		' -- "${VERSION}")"
	fi
	ARCHIVE_URL="https://wordpress.org/wordpress-${VERSION}.tar.gz"
fi

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TMP_DIR}"' EXIT
mkdir -p "${DEST}"
if [[ "${ARCHIVE_KIND}" == "zip" ]]; then
	curl -fsSL "${ARCHIVE_URL}" -o "${TMP_DIR}/wordpress.zip"
	unzip -q "${TMP_DIR}/wordpress.zip" -d "${TMP_DIR}/unzipped"
	cp -a "${TMP_DIR}/unzipped/wordpress/." "${DEST}/"
else
	curl -fsSL "${ARCHIVE_URL}" -o "${TMP_DIR}/wordpress.tar.gz"
	tar -xzf "${TMP_DIR}/wordpress.tar.gz" -C "${DEST}" --strip-components=1
fi
test -f "${DEST}/wp-settings.php"
echo "${DEST}"
