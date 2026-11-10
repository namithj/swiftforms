/**
 * Webpack config: extends the wp-scripts defaults.
 *
 * `wp-scripts build --experimental-modules` exports an array of configs: the
 * classic script build and the script-module build. With no block.json under
 * src/ (blocks are registered by the block builder, not by us) the default
 * entry discovery finds nothing, so every entry is declared here.
 *
 * - Script build: the editor script (`editor/index`, a classic script with an
 *   `index.asset.php`) and the two stylesheets (`style/form` for the front
 *   end, `style/editor` for the editor canvas). A stylesheet entry also gets
 *   its `-rtl.css` twin from the wp-scripts RTL plugin.
 * - Admin app (classic script): `admin/entries/index` (Forms → Entries). Forms → Settings
 *   is a cassette-cmf settings page with its own built app (no SwiftForms entry).
 * - Module build: the front-end view module (`view/index`).
 *
 * Output goes to build/ (git-ignored, shipped in the release ZIP).
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

const configs = Array.isArray( defaultConfig )
	? defaultConfig
	: [ defaultConfig ];

module.exports = configs.map( ( config ) => {
	// The module build is the one that emits ES modules.
	if ( config.experiments && config.experiments.outputModule ) {
		return {
			...config,
			entry: {
				'view/index': path.resolve( __dirname, 'src/view/index.js' ),
			},
		};
	}

	return {
		...config,
		entry: {
			'editor/index': path.resolve( __dirname, 'src/editor/index.js' ),
			'style/form': path.resolve( __dirname, 'src/style/form.css' ),
			'style/editor': path.resolve( __dirname, 'src/style/editor.css' ),
		},
	};
} );
