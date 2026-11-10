<?php
/**
 * WP-CLI entry used by the e2e runner (bin/run-e2e-configs.mjs).
 *
 *   wp eval-file wp-content/plugins/swiftforms/tests/user-configs/apply-cli.php <slug>
 *
 * Loads one config and applies it with the same loader the PHPUnit suite uses.
 * It does not reset the database: the runner did that (wp db reset, core install)
 * just before. It prints {slug, posts: {key: {id, post_type, edit_url}}} to stdout
 * and also writes it to tests/e2e/.state/<slug>.json for the Playwright spec.
 *
 * It does not depend on PHPUnit. The test environment (mail capture, blocked
 * HTTP, frozen time, example.org URLs) is NOT installed: wp-env is a real site
 * the browser talks to.
 *
 * @package SwiftForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/loader.php';

// "wp eval-file" passes positional arguments as $args; fall back to argv.
$sf_test_cli_slug = isset( $args[0] ) ? (string) $args[0] : (string) end( $GLOBALS['argv'] );

$sf_test_cli_config = SF_User_Config_Loader::load( $sf_test_cli_slug );
SF_User_Config_Loader::apply( $sf_test_cli_config, '', false );

$sf_test_cli_state = [
	'slug'  => $sf_test_cli_slug,
	'posts' => SF_User_Config_Loader::posts_summary(),
];
$sf_test_cli_json  = wp_json_encode( $sf_test_cli_state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";

$sf_test_cli_dir = dirname( __DIR__ ) . '/e2e/.state';
if ( ! is_dir( $sf_test_cli_dir ) && ! mkdir( $sf_test_cli_dir, 0777, true ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
	WP_CLI::error( 'Could not create ' . $sf_test_cli_dir );
}
file_put_contents( $sf_test_cli_dir . '/' . $sf_test_cli_slug . '.json', $sf_test_cli_json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

echo $sf_test_cli_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON for the runner, not HTML.
