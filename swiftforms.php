<?php
/**
 * Plugin Name: SwiftForms
 * Plugin URI: https://github.com/smartlogix/swiftforms
 * Description: A streamlined, block-based form builder for WordPress with per-form Settings/Entries screens, layered spam protection, conditional logic, and a themeable design system.
 * Version: 1.0.0
 * Author: Smartlogix
 * Author URI: https://smartlogix.co.in
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.6
 * Requires PHP: 8.2
 * Text Domain: swiftforms
 * Domain Path: /languages
 *
 * @package SwiftForms
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function smartlogix_swiftforms_meets_requirements( ?string $wordpress_version = null, ?string $php_version = null ): bool {
	$wordpress_version = null === $wordpress_version ? get_bloginfo( 'version' ) : $wordpress_version;
	$php_version       = null === $php_version ? PHP_VERSION : $php_version;

	return version_compare( $wordpress_version, '6.6', '>=' ) && version_compare( $php_version, '8.2', '>=' );
}

function smartlogix_swiftforms_requirements_notice(): void {
	echo '<div class="notice notice-error"><p>' . esc_html__( 'SwiftForms requires WordPress 6.6 or newer and PHP 8.2 or newer. The plugin has not started.', 'swiftforms' ) . '</p></div>';
}

function smartlogix_swiftforms_reject_unsupported_activation(): void {
	deactivate_plugins( plugin_basename( __FILE__ ) );
	wp_die( esc_html__( 'SwiftForms requires WordPress 6.6 or newer and PHP 8.2 or newer.', 'swiftforms' ), esc_html__( 'Plugin requirements not met', 'swiftforms' ), array( 'back_link' => true ) );
}

if ( ! smartlogix_swiftforms_meets_requirements() ) {
	add_action( 'admin_notices', 'smartlogix_swiftforms_requirements_notice' );
	register_activation_hook( __FILE__, 'smartlogix_swiftforms_reject_unsupported_activation' );
	return;
}

define( 'SMARTLOGIX_SWIFTFORMS_VERSION', '1.0.0' );
define( 'SMARTLOGIX_SWIFTFORMS_PLUGIN_FILE', __FILE__ );
define( 'SMARTLOGIX_SWIFTFORMS_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'SMARTLOGIX_SWIFTFORMS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

$smartlogix_swiftforms_autoload = SMARTLOGIX_SWIFTFORMS_PLUGIN_PATH . 'vendor/autoload.php';
if ( file_exists( $smartlogix_swiftforms_autoload ) ) {
	require_once $smartlogix_swiftforms_autoload;
}
unset( $smartlogix_swiftforms_autoload );

/**
 * Returns the plugin singleton, booting it on first access.
 */
function smartlogix_swiftforms(): SwiftForms\Plugin {
	return SwiftForms\Plugin::instance();
}

register_activation_hook( SMARTLOGIX_SWIFTFORMS_PLUGIN_FILE, array( SwiftForms\Activation::class, 'activate' ) );
register_deactivation_hook( SMARTLOGIX_SWIFTFORMS_PLUGIN_FILE, array( SwiftForms\Activation::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( smartlogix_swiftforms(), 'boot' ) );
