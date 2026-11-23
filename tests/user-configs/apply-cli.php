<?php
/**
 * WP-CLI entry used by the e2e runner (bin/run-e2e-configs.mjs).
 *
 *   wp eval-file wp-content/plugins/blockforms/tests/user-configs/apply-cli.php <slug>
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
 * @package BlockForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/loader.php';

// "wp eval-file" passes positional arguments as $args; fall back to argv.
$bf_test_cli_slug = isset( $args[0] ) ? (string) $args[0] : (string) end( $GLOBALS['argv'] );

$bf_test_cli_config = BF_User_Config_Loader::load( $bf_test_cli_slug );
BF_User_Config_Loader::apply( $bf_test_cli_config, '', false );

$bf_test_cli_state = [
	'slug'  => $bf_test_cli_slug,
	'posts' => BF_User_Config_Loader::posts_summary(),
];
$bf_test_cli_json  = wp_json_encode( $bf_test_cli_state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";

$bf_test_cli_dir = dirname( __DIR__ ) . '/e2e/.state';
if ( ! is_dir( $bf_test_cli_dir ) && ! mkdir( $bf_test_cli_dir, 0777, true ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
	WP_CLI::error( 'Could not create ' . $bf_test_cli_dir );
}
file_put_contents( $bf_test_cli_dir . '/' . $bf_test_cli_slug . '.json', $bf_test_cli_json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

echo $bf_test_cli_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON for the runner, not HTML.
