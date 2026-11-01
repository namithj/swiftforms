/**
 * Playwright config for the SwiftForms e2e suite.
 *
 * Builds on the `@wordpress/scripts` defaults (admin login through
 * `@wordpress/e2e-test-utils-playwright`, storage state, one worker). The site
 * is started and reset by bin/run-e2e-configs.mjs (wp-env), so the default
 * webServer hook is switched off. WP_BASE_URL defaults to the wp-env tests
 * site on port 8889.
 */
const path = require( 'path' );
const { defineConfig } = require( '@playwright/test' );
const baseConfig = require( '@wordpress/scripts/config/playwright.config.js' );

module.exports = defineConfig( {
	...baseConfig,
	testDir: path.join( __dirname, 'tests/e2e' ),
	testMatch: '**/*.spec.js',
	webServer: undefined,
	use: {
		...baseConfig.use,
		baseURL: process.env.WP_BASE_URL || 'http://localhost:8889',
	},
	reporter: process.env.CI
		? [
				[ 'github' ],
				[
					'html',
					{ open: 'never', outputFolder: 'playwright-report' },
				],
			]
		: [ [ 'list' ] ],
} );
