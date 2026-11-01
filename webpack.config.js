/**
 * Webpack config: extends the @wordpress/scripts defaults.
 *
 * `wp-scripts build --experimental-modules` exports an array of configs: the
 * classic script build and the script-module build. With no block.json under
 * src/ yet (blocks are registered by the block builder, not by us) the default
 * entry discovery finds nothing, so the front-end view module is declared here.
 * Output goes to build/ (git-ignored, shipped in the release ZIP).
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

const configs = Array.isArray( defaultConfig )
	? defaultConfig
	: [ defaultConfig ];

module.exports = configs.map( ( config ) => {
	// The module build is the one that emits ES modules.
	if ( ! config.experiments || ! config.experiments.outputModule ) {
		return config;
	}

	return {
		...config,
		entry: {
			'view/index': path.resolve( __dirname, 'src/view/index.js' ),
		},
	};
} );
