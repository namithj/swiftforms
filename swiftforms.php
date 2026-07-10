<?php
/**
 * Plugin Name: SwiftForms
 * Plugin URI: https://github.com/smartlogix/swiftforms
 * Description: High-performance Gutenberg-native forms with conditional logic, multi-step layouts, and layered spam protection.
 * Version: 0.1.0
 * Author: Smartlogix
 * Author URI: https://smartlogix.co.in
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Text Domain: swiftforms
 * Domain Path: /languages
 *
 * @package SwiftForms
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SWIFTFORMS_VERSION', '0.1.0' );
define( 'SWIFTFORMS_FILE', __FILE__ );
define( 'SWIFTFORMS_PATH', plugin_dir_path( __FILE__ ) );
define( 'SWIFTFORMS_URL', plugin_dir_url( __FILE__ ) );

require_once SWIFTFORMS_PATH . 'includes/autoload.php';

/**
 * Boots the plugin singleton.
 */
function swiftforms(): SwiftForms_Core {
	static $plugin = null;

	if ( ! $plugin instanceof SwiftForms_Core ) {
		$plugin = new SwiftForms_Core();
	}

	return $plugin;
}

/**
 * Registers activation-time resources.
 */
function swiftforms_activate(): void {
	$cpts = new SwiftForms_CPTs();
	$cpts->register();
	flush_rewrite_rules();

	// Stamped so a future release can detect an upgrade and run migrations;
	// there's nothing to migrate yet at 0.1.0.
	update_option( 'swiftforms_db_version', SWIFTFORMS_VERSION );
}

register_activation_hook( SWIFTFORMS_FILE, 'swiftforms_activate' );

/**
 * Cleans up scheduled events on deactivation.
 */
function swiftforms_deactivate(): void {
	wp_clear_scheduled_hook( SwiftForms_Privacy::CLEANUP_HOOK );
	flush_rewrite_rules();
}

register_deactivation_hook( SWIFTFORMS_FILE, 'swiftforms_deactivate' );

add_action(
	'plugins_loaded',
	static function (): void {
		add_action( 'init', array( swiftforms(), 'init' ) );
	}
);
