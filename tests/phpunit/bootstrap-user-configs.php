<?php
/**
 * Bootstrap for the user-config regression group (R00).
 *
 * Same as bootstrap.php, plus the safeguard that keeps the group away from the
 * developer's real site: WP_TESTS_ABSPATH must be set explicitly, and it must
 * not be a WordPress root that contains this plugin. bin/test-user-configs.sh
 * points it at a throwaway core in .cache/wp-core/. (wp-tests-config.php has no
 * default core either, but this group also writes posts, users and themes, so
 * it checks again.)
 *
 * @package SwiftForms
 */

define( 'SWIFTFORMS_USER_CONFIGS_BOOTSTRAP', true );

$sf_test_abspath = getenv( 'WP_TESTS_ABSPATH' );
if ( false === $sf_test_abspath || '' === $sf_test_abspath ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- WordPress is not loaded yet.
	fwrite( STDERR, "Refusing to run: WP_TESTS_ABSPATH is not set.\nThe user-config group must run against a throwaway WordPress core, never the real site.\nUse bin/test-user-configs.sh (composer test:configs), or point WP_TESTS_ABSPATH at a core installed with bin/install-wp-core.sh.\n" );
	exit( 1 );
}

$sf_test_real_abspath = realpath( $sf_test_abspath );
$sf_test_plugin_dir   = realpath( dirname( __DIR__, 2 ) );
if ( false === $sf_test_real_abspath || 0 === strpos( $sf_test_plugin_dir . '/', $sf_test_real_abspath . '/' ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- WordPress is not loaded yet.
	fwrite( STDERR, "Refusing to run: WP_TESTS_ABSPATH contains this plugin, so it looks like a real site.\n" );
	exit( 1 );
}

require __DIR__ . '/bootstrap.php';
