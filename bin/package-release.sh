#!/usr/bin/env bash
# Builds a production-only SwiftForms ZIP from the current committed source.
set -euo pipefail

root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
artifact_dir="${root_dir}/artifacts"
stage_dir="$(mktemp -d)"
plugin_dir="${stage_dir}/swiftforms"

cleanup() {
	rm -rf "${stage_dir}"
}
trap cleanup EXIT

command -v composer >/dev/null || { echo "composer is required" >&2; exit 1; }
command -v npm >/dev/null || { echo "npm is required" >&2; exit 1; }
command -v zip >/dev/null || { echo "zip is required" >&2; exit 1; }

version="$(awk -F': ' '/^ \* Version:/{print $2; exit}' "${root_dir}/swiftforms.php")"
if [[ -z "${version}" || "${version}" == *-dev ]]; then
	echo "Set a non-development plugin version before packaging." >&2
	exit 1
fi

mkdir -p "${artifact_dir}" "${plugin_dir}"
git -C "${root_dir}" archive --format=tar HEAD | tar -x -C "${plugin_dir}"
npm --prefix "${root_dir}" run build
cp -R "${root_dir}/build" "${plugin_dir}/build"
composer --working-dir="${plugin_dir}" install --no-dev --classmap-authoritative --no-interaction --prefer-dist
find "${plugin_dir}" -type f \( -name '*.map' -o -name '.phpunit.result.cache' \) -delete

archive_name="swiftforms-${version}.zip"
archive_path="${artifact_dir}/${archive_name}"
rm -f "${archive_path}" "${archive_path}.sha256" "${archive_path}.inventory.txt"
( cd "${stage_dir}" && zip -X -qr "${archive_path}" swiftforms )
unzip -l "${archive_path}" | tee "${archive_path}.inventory.txt"
if unzip -l "${archive_path}" | grep -Eq '/(node_modules|tests|\.github|\.vscode|vendor/(phpunit|squizlabs|wp-phpunit))/'; then
	echo "Release archive contains development files." >&2
	exit 1
fi

sha256sum "${archive_path}" > "${archive_path}.sha256"
echo "Created ${archive_path}"
