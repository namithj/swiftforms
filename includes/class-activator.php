<?php
/**
 * Activation, deactivation and per-site installs.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Activator
 *
 * Installing is idempotent and lazy (ADR-003). Activation on a single site
 * installs now. Network activation does nothing per site, because a loop over
 * every site times out on a large network: each site installs itself on its first
 * request (init), and sites created later install on wp_initialize_site.
 *
 * Nothing here writes a settings option (C-5.2), and deactivation keeps all data.
 */
class Activator {

	/**
	 * Hook the lazy install, the new-site install and the table cleanup.
	 *
	 * The activation and deactivation hooks are registered in swiftforms.php
	 * (activation runs before plugins_loaded, so Plugin::boot() is too late).
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', [ self::class, 'maybe_install' ], 1 );
		// After core (priority 10) has created the new site's own tables and options.
		add_action( 'wp_initialize_site', [ self::class, 'initialize_site' ], 900 );
		add_filter( 'wpmu_drop_tables', [ self::class, 'drop_tables' ], 10, 2 );
	}

	/**
	 * Plugin activation.
	 *
	 * @param bool $network_wide Whether the plugin is being network-activated.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			return; // Each site installs itself lazily; see the class comment.
		}
		self::install();
		// Plugin::boot() has not run yet (plugins_loaded comes later), so no hook listens to the migration: rebuild here.
		Form_Index::rebuild();
	}

	/**
	 * Plugin deactivation: stop the scheduled event. Data stays.
	 *
	 * On a network deactivation only the current site's event is removed; the
	 * other sites' events have no callback while the plugin is off and are
	 * picked up again, or replaced, on reactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {
		Cron::unschedule();
	}

	/**
	 * Install this site when the plugin has not run here yet, and run any due migration.
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( wp_installing() ) {
			return;
		}
		if ( null === get_option( Migrations::OPTION_DB_VERSION, null ) ) {
			Install_Marker::maybe_write();
		}
		Migrations::maybe_run();
	}

	/**
	 * Install a site created while the plugin is network-active.
	 *
	 * @param \WP_Site $site The new site.
	 * @return void
	 */
	public static function initialize_site( $site ) {
		if ( ! $site instanceof \WP_Site || ! self::is_network_active() ) {
			return;
		}

		switch_to_blog( (int) $site->blog_id );
		try {
			self::install();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Add this plugin's tables to the list core drops when a site is deleted.
	 *
	 * @param string[] $tables  Tables core will drop.
	 * @param int      $blog_id Site being deleted.
	 * @return string[]
	 */
	public static function drop_tables( $tables, $blog_id = 0 ) {
		return array_values( array_unique( array_merge( (array) $tables, Database::tables( (int) $blog_id ) ) ) );
	}

	/**
	 * Write the marker (new sites only) and run the migrations, for the current site.
	 *
	 * @return void
	 */
	private static function install() {
		Install_Marker::maybe_write();
		Migrations::maybe_run();
	}

	/**
	 * Whether the plugin is network-active.
	 *
	 * @return bool
	 */
	private static function is_network_active() {
		if ( ! is_multisite() ) {
			return false;
		}
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active_for_network( plugin_basename( SWIFTFORMS_FILE ) );
	}
}
