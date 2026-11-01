<?php
/**
 * Plugin Name: SwiftForms – Contact Form Builder for the Block Editor
 * Plugin URI: https://github.com/namithj/swiftforms
 * Description: Build contact forms with blocks, right in the editor. Fast, accessible and free, with no upsells.
 * Author: namithjawahar
 * Author URI: https://smartlogix.co.in/
 * Version: 0.1.0
 * Requires at least: 6.8
 * Requires PHP: 8.2
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: swiftforms
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin version.
 *
 * Kept in step with the plugin header and the readme Stable tag by
 * bin/set-version.sh, which the release pipeline runs from the pushed tag.
 */
define( 'SWIFTFORMS_VERSION', '0.1.0' );

/**
 * Main plugin file, for plugin_dir_path() / plugin_dir_url() and activation hooks.
 */
define( 'SWIFTFORMS_FILE', __FILE__ );

/**
 * Floors checked by the bootstrap guard below.
 *
 * SWIFTFORMS_MIN_PHP is the floor of SwiftForms' own code (phpcs testVersion
 * 7.4-), so the PHP 7.4 CI cell exercises it. The "Requires PHP" header is
 * stricter (8.2) because the bundled frameworks need it; WordPress enforces the
 * header, this guard is the last line of defence when the header was bypassed.
 */
define( 'SWIFTFORMS_MIN_PHP', '7.4' );
define( 'SWIFTFORMS_MIN_WP', '6.8' );

/**
 * Print an admin notice and leave the plugin switched off.
 *
 * Written for PHP 5.6+ on purpose (no short closures, no typed properties, no
 * namespaces) so that this file parses on any PHP and never fatals.
 *
 * @param string $message Already-translated, plain-text message.
 * @return void
 */
function swiftforms_bootstrap_notice( $message ) {
	add_action(
		'admin_notices',
		function () use ( $message ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( $message )
			);
		}
	);
}

if ( version_compare( PHP_VERSION, SWIFTFORMS_MIN_PHP, '<' ) ) {
	swiftforms_bootstrap_notice(
		sprintf(
			/* translators: 1: required PHP version, 2: current PHP version. */
			__( 'SwiftForms needs PHP %1$s or newer. You are running PHP %2$s, so the plugin is switched off.', 'swiftforms' ),
			SWIFTFORMS_MIN_PHP,
			PHP_VERSION
		)
	);
	return;
}

if ( version_compare( get_bloginfo( 'version' ), SWIFTFORMS_MIN_WP, '<' ) ) {
	swiftforms_bootstrap_notice(
		sprintf(
			/* translators: 1: required WordPress version, 2: current WordPress version. */
			__( 'SwiftForms needs WordPress %1$s or newer. You are running WordPress %2$s, so the plugin is switched off.', 'swiftforms' ),
			SWIFTFORMS_MIN_WP,
			get_bloginfo( 'version' )
		)
	);
	return;
}

// Strauss output (the namespaced frameworks) and Composer's own autoloader (our classes).
if ( ! is_readable( __DIR__ . '/vendor-prefixed/autoload.php' ) || ! is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	swiftforms_bootstrap_notice(
		__( 'SwiftForms is missing its dependencies. Install the plugin from a release ZIP, or run "composer install" in the plugin folder.', 'swiftforms' )
	);
	return;
}

require_once __DIR__ . '/vendor-prefixed/autoload.php';
require_once __DIR__ . '/vendor/autoload.php';

add_action(
	'plugins_loaded',
	function () {
		\SwiftForms\Plugin::instance()->boot();
	}
);
