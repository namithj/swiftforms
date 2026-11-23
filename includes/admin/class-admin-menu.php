<?php
/**
 * The Forms menu and the Entries screen.
 *
 * @package BlockForms
 */

namespace BlockForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin_Menu
 *
 * Top-level "Forms" with Entries, a PHP screen (Entries_Screen: a WP_List_Table and a single-entry screen).
 * The Settings submenu is registered by Settings_Page (cassette-cmf) after this menu. Nothing is shown outside these screens: no redirect on activation, no wizard, no notice. The screen IDs
 * contain "blockforms", which is how other code recognises a BlockForms screen.
 */
class Admin_Menu {

	/**
	 * Menu slug of the Entries screen (also the top-level slug).
	 */
	const SLUG_ENTRIES = 'blockforms';

	/**
	 * Hook suffixes of the screens, set while the menu is added.
	 *
	 * @var array<string, string>
	 */
	private static $screens = [];

	/**
	 * Hook the menu and the assets.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', [ self::class, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
		add_filter( 'screen_options_show_screen', [ self::class, 'hide_settings_screen_options' ], 10, 2 );
		Entries_Screen::init();
	}

	/**
	 * Hide "Screen Options" on the Settings screen: the cassette-cmf metabox registration adds it, and it has nothing to set there.
	 *
	 * @param bool       $show   Whether to show the Screen Options tab.
	 * @param \WP_Screen $screen Current screen.
	 * @return bool
	 */
	public static function hide_settings_screen_options( $show, $screen ) {
		if ( $screen instanceof \WP_Screen && get_plugin_page_hookname( Settings_Page::MENU_SLUG, Settings_Page::PARENT_SLUG ) === $screen->id ) {
			return false;
		}
		return $show;
	}

	/**
	 * Add the menu.
	 *
	 * @return void
	 */
	public static function add_menu() {
		self::$screens = [];

		$entries = add_menu_page(
			__( 'Entries', 'blockforms' ),
			__( 'Forms', 'blockforms' ),
			'blockforms_view_entries',
			self::SLUG_ENTRIES,
			[ Entries_Screen::class, 'render' ],
			'dashicons-feedback',
			30
		);
		if ( $entries ) {
			self::$screens['entries'] = $entries;
			add_action( 'load-' . $entries, [ Entries_Screen::class, 'load' ] );
		}

		// The first submenu item replaces the duplicate top-level entry.
		add_submenu_page( self::SLUG_ENTRIES, __( 'Entries', 'blockforms' ), __( 'Entries', 'blockforms' ), 'blockforms_view_entries', self::SLUG_ENTRIES, [ Entries_Screen::class, 'render' ] );
	}

	/**
	 * Load the stylesheet of the Entries screen.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public static function enqueue( $hook_suffix ) {
		if ( isset( self::$screens['entries'] ) && self::$screens['entries'] === $hook_suffix ) {
			Entries_Screen::enqueue();
		}
		if ( get_plugin_page_hookname( Settings_Page::MENU_SLUG, Settings_Page::PARENT_SLUG ) === $hook_suffix ) {
			$path = plugin_dir_path( BLOCKFORMS_FILE ) . 'includes/admin/settings.css';
			wp_enqueue_style( 'blockforms-admin-settings', plugins_url( 'includes/admin/settings.css', BLOCKFORMS_FILE ), [], is_readable( $path ) ? (string) filemtime( $path ) : BLOCKFORMS_VERSION );
		}
	}
}
