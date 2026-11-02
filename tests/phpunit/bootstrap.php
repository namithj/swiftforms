<?php
/**
 * Load the WordPress core test library with SwiftForms active.
 *
 * @package SwiftForms
 */

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// Lets Clock::freeze() and Random::seed() work.
define( 'SWIFTFORMS_TESTS', true );

if ( false === getenv( 'WP_PHPUNIT__TESTS_CONFIG' ) ) {
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Required by wp-phpunit.
	putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );
}

// wp-phpunit's multisite switch is read from this constant by its own bootstrap.
if ( getenv( 'WP_TESTS_MULTISITE' ) && ! defined( 'WP_TESTS_MULTISITE' ) ) {
	define( 'WP_TESTS_MULTISITE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Constant read by wp-phpunit.
}

$sf_test_lib_dir = getenv( 'WP_PHPUNIT__DIR' );
if ( false === $sf_test_lib_dir ) {
	$sf_test_lib_dir = dirname( __DIR__, 2 ) . '/vendor/wp-phpunit/wp-phpunit';
}

require_once $sf_test_lib_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__, 2 ) . '/swiftforms.php';
	}
);

require $sf_test_lib_dir . '/includes/bootstrap.php';

// A user-config run on the same database commits rows (its table DDL ends the test
// transaction), and a later suite must not see them: start from empty SwiftForms tables.
// No test transaction is open yet, so this runs with autocommit.
if ( class_exists( 'SwiftForms\Database' ) ) {
	$sf_test_suppress = $GLOBALS['wpdb']->suppress_errors();
	foreach ( SwiftForms\Database::tables() as $sf_test_table ) {
		$GLOBALS['wpdb']->query( 'DELETE FROM `' . $sf_test_table . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Own table; name built from $wpdb->prefix and a fixed list.
	}
	$GLOBALS['wpdb']->suppress_errors( $sf_test_suppress );
}
