#!/usr/bin/env bash
#
# Package a plugin zip and a blueprint into an offline Playground bundle.
#
# The blueprint is assets/blueprints/blueprint.json with its plugin step pointed
# at the bundled zip instead of the "latest release" URL.
#
# Usage: bin/build-playground-bundle.sh <plugin-zip> <output-bundle>

set -euo pipefail

if [[ $# -ne 2 ]]; then
	echo "Usage: $0 <plugin-zip> <output-bundle>" >&2
	exit 2
fi

PLUGIN_ZIP="$(realpath -m "$1")"
OUTPUT_BUNDLE="$(realpath -m "$2")"
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_ROOT="$(mktemp -d)"

cleanup() {
	rm -rf "${BUILD_ROOT}"
}
trap cleanup EXIT

if [[ ! -f "${PLUGIN_ZIP}" ]]; then
	echo "Plugin zip not found: ${PLUGIN_ZIP}" >&2
	exit 1
fi

cd "${REPO_ROOT}"

mkdir -p "$(dirname "${OUTPUT_BUNDLE}")"
cp "${PLUGIN_ZIP}" "${BUILD_ROOT}/swiftforms.zip"

node -e '
	const fs = require( "fs" );
	const blueprint = JSON.parse( fs.readFileSync( "assets/blueprints/blueprint.json", "utf8" ) );
	let swapped = 0;
	for ( const step of blueprint.steps ) {
		if ( step.step === "installPlugin" ) {
			step.pluginData = { resource: "bundled", path: "/swiftforms.zip" };
			step.options = { ...step.options, targetFolderName: "swiftforms" };
			swapped++;
		}
	}
	if ( swapped !== 1 ) {
		throw new Error( "Expected exactly one installPlugin step in assets/blueprints/blueprint.json." );
	}
	fs.writeFileSync( process.argv[ 1 ], JSON.stringify( blueprint, null, 2 ) + "\n" );
' "${BUILD_ROOT}/blueprint.json"

rm -f "${OUTPUT_BUNDLE}"
(cd "${BUILD_ROOT}" && zip -rq "${OUTPUT_BUNDLE}" blueprint.json swiftforms.zip)
ZIP_ENTRIES="$(unzip -Z1 "${OUTPUT_BUNDLE}")"

for required_file in blueprint.json swiftforms.zip; do
	if ! grep -qxF "${required_file}" <<< "${ZIP_ENTRIES}"; then
		echo "Playground bundle is missing ${required_file}." >&2
		exit 1
	fi
done

echo "Built ${OUTPUT_BUNDLE}"
