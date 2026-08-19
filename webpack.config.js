const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

/**
 * The default @wordpress/scripts webpack config's `entry` is itself a
 * *function* (webpack's dynamic-entry feature) that scans every
 * `block.json` under `src/**` and derives entries from its
 * editorScript/script/viewScript/render.php references — so field blocks
 * never need a hand-maintained entry list. Spreading it as a plain object
 * (`{ ...defaultConfig.entry }`) silently discards that function and its
 * scan, so it must be called and merged with instead.
 */
module.exports = {
	...defaultConfig,
	entry: async () => {
		const blockEntries =
			typeof defaultConfig.entry === 'function'
				? await defaultConfig.entry()
				: defaultConfig.entry;

		return {
			...blockEntries,
			'editor/index': path.resolve(
				process.cwd(),
				'src/editor/index.js'
			),
		};
	},
};
