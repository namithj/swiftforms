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

define( 'SWF_VERSION', '1.0.0' );
define( 'SWF_PLUGIN_FILE', __FILE__ );
define( 'SWF_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'SWF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

$swf_autoload = SWF_PLUGIN_PATH . 'vendor/autoload.php';
if ( file_exists( $swf_autoload ) ) {
	require_once $swf_autoload;
}
unset( $swf_autoload );

/**
 * Returns the plugin singleton, booting it on first access.
 */
function swf(): SwiftForms\Plugin {
	return SwiftForms\Plugin::instance();
}

register_activation_hook( SWF_PLUGIN_FILE, array( SwiftForms\Activation::class, 'activate' ) );
register_deactivation_hook( SWF_PLUGIN_FILE, array( SwiftForms\Activation::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( swf(), 'boot' ) );
