<?php
/**
 * Plugin Name: SwiftForms
 * Plugin URI: https://example.com/swiftforms
 * Description: High-performance Gutenberg-native forms for WordPress.
 * Version: 0.1.0
 * Author: Smartlogix
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Text Domain: swiftforms
 * Domain Path: /languages
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('SWIFTFORMS_VERSION', '0.1.0');
define('SWIFTFORMS_FILE', __FILE__);
define('SWIFTFORMS_PATH', plugin_dir_path(__FILE__));
define('SWIFTFORMS_URL', plugin_dir_url(__FILE__));

require_once SWIFTFORMS_PATH . 'includes/autoload.php';

/**
 * Boots the plugin singleton.
 *
 * Tests to create:
 * - test_swiftforms_returns_singleton_instance: Call swiftforms() twice and expect the same SwiftForms_Core instance.
 * - test_swiftforms_can_boot_after_plugin_load: Load the plugin file and expect a SwiftForms_Core object.
 *
 * Expected output:
 * - Both calls return the identical object reference.
 * - The returned object is an initialized SwiftForms_Core bootstrap instance.
 */
function swiftforms(): SwiftForms_Core {
    static $plugin = null;

    if (!$plugin instanceof SwiftForms_Core) {
        $plugin = new SwiftForms_Core();
    }

    return $plugin;
}

/**
 * Registers activation-time resources.
 *
 * Tests to create:
 * - test_swiftforms_activate_registers_cpts: Call swiftforms_activate() and expect both custom post types to exist.
 *
 * Expected output:
 * - swiftforms_form and swiftforms_submission are registered for the activation request.
 */
function swiftforms_activate(): void {
    $cpts = new SwiftForms_CPTs();
    $cpts->register();
    flush_rewrite_rules();
}

register_activation_hook(SWIFTFORMS_FILE, 'swiftforms_activate');

add_action(
    'plugins_loaded',
    static function (): void {
        add_action('init', array(swiftforms(), 'init'));
    }
);