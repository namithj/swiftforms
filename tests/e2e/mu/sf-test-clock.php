<?php
/**
 * Test-only mu-plugin: freezes the SwiftForms clock for the e2e run.
 *
 * Mapped into wp-content/mu-plugins/ by .wp-env.json. It does nothing unless
 * SWIFTFORMS_TEST_NOW (set in .wp-env.json) is defined, so it can never affect
 * a real site.
 *
 * @package SwiftForms
 */

if ( defined( 'SWIFTFORMS_TEST_NOW' ) ) {
	add_action(
		'plugins_loaded',
		static function () {
			if ( class_exists( '\SwiftForms\Clock' ) ) {
				\SwiftForms\Clock::freeze( (int) SWIFTFORMS_TEST_NOW );
			}
		},
		20
	);
}
