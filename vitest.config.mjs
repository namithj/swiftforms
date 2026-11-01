import { defineConfig } from 'vitest/config';

// Only our own unit tests: never the frameworks' tests under vendor*/ and never the Playwright specs.
export default defineConfig( {
	test: {
		include: [ 'tests/js/**/*.test.js' ],
	},
} );
