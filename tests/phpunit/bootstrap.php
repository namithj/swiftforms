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
