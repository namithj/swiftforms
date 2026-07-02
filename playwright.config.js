/**
 * Playwright configuration for SwiftForms E2E tests.
 *
 * Extends the @wordpress/scripts default (admin login global-setup, storage
 * state, wp-env web server on the tests instance) and points the runner at the
 * plugin's own E2E specs.
 */

const baseConfig = require( '@wordpress/scripts/config/playwright.config' );

module.exports = {
	...baseConfig,
	testDir: './tests/e2e',
};
