<?php
/**
 * Test-only mu-plugin: freezes the SwiftForms clock for the e2e run.
 *
 * Mapped into wp-content/mu-plugins/ by .wp-env.json (and symlinked on a local test site). It does nothing unless
 * SWIFTFORMS_TEST_NOW (set in .wp-env.json, or in the wp-config.php of the local test site) is defined, so it can
 * never affect a real site.
 *
 * The clock stands still at SWIFTFORMS_TEST_NOW, unless the file tests/e2e/.state/now (written by the Playwright
 * specs and by bin/run-e2e-configs.mjs) holds a Unix time: then the clock stands at that moment, read again on every
 * request. That is how a spec moves time (an old page, an expired token) without restarting the site.
 *
 * @package SwiftForms
 */

if ( defined( 'SWIFTFORMS_TEST_NOW' ) ) {
	add_action(
		'plugins_loaded',
		static function () {
			if ( ! class_exists( '\SwiftForms\Clock' ) ) {
				return;
			}
			$now  = (int) SWIFTFORMS_TEST_NOW;
			$file = WP_CONTENT_DIR . '/plugins/swiftforms/tests/e2e/.state/now';
			if ( is_readable( $file ) ) {
				$moved = (int) trim( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test file.
				if ( $moved > 0 ) {
					$now = $moved;
				}
			}
			\SwiftForms\Clock::freeze( $now );
		},
		20
	);
}
