<?php
/**
 * Tests for the Forms menu.
 *
 * @package BlockForms
 */

use BlockForms\Admin_Menu;

/**
 * Class AdminMenuTest
 *
 * @group admin
 */
class AdminMenuTest extends WP_UnitTestCase {

	/**
	 * Clean menu state.
	 *
	 * @return void
	 */
	public function set_up() {
		global $menu, $submenu, $admin_page_hooks, $_registered_pages, $_parent_pages;
		parent::set_up();
		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh admin menu state for the test.
		$menu              = [];
		$submenu           = [];
		$admin_page_hooks  = [];
		$_registered_pages = [];
		$_parent_pages     = [];
		// phpcs:enable
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		Admin_Menu::add_menu();
	}

	/**
	 * Forms with Entries and Settings, behind the BlockForms capabilities, screen IDs with "blockforms".
	 *
	 * @return void
	 */
	public function test_menu_structure() {
		global $menu, $submenu;

		$top = array_values(
			array_filter(
				$menu,
				static function ( $item ) {
					return 'blockforms' === $item[2];
				}
			)
		);
		$this->assertCount( 1, $top );
		$this->assertSame( 'Forms', $top[0][0] );
		$this->assertSame( 'blockforms_view_entries', $top[0][1] );
		$this->assertSame( 'dashicons-feedback', $top[0][6] );

		$items = $submenu['blockforms'];
		$this->assertSame( [ 'Entries', 'blockforms_view_entries', 'blockforms' ], [ $items[0][0], $items[0][1], $items[0][2] ] );
		$this->assertCount( 1, $items, 'Admin_Menu adds only Entries; Settings_Page adds Settings.' );
		$this->assertStringContainsString( 'blockforms', get_plugin_page_hookname( 'blockforms', '' ) );
	}

	/**
	 * Entries is a PHP screen: its controller runs on load-{hook}, only its own stylesheet loads, and no app or inline data.
	 *
	 * @return void
	 */
	public function test_entries_screen_is_php() {
		$hook = get_plugin_page_hookname( 'blockforms', '' );
		$this->assertNotFalse( has_action( 'load-' . $hook, [ \BlockForms\Entries_Screen::class, 'load' ] ) );

		Admin_Menu::enqueue( 'edit.php' );
		$this->assertFalse( wp_style_is( 'blockforms-admin-entries', 'enqueued' ) );

		Admin_Menu::enqueue( $hook );
		$this->assertTrue( wp_style_is( 'blockforms-admin-entries', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'blockforms-admin-entries', 'enqueued' ), 'The DataViews app is gone.' );
		$this->assertFalse( wp_script_is( 'blockforms-admin-settings', 'enqueued' ) );
		$this->assertFalse( method_exists( Admin_Menu::class, 'config' ), 'window.blockformsAdmin is gone.' );
	}

	/**
	 * The Settings screen (cassette-cmf) has no Screen Options; other screens keep theirs.
	 *
	 * @return void
	 */
	public function test_settings_screen_hides_screen_options() {
		$settings = WP_Screen::get( get_plugin_page_hookname( 'blockforms-settings', 'blockforms' ) );
		$this->assertFalse( Admin_Menu::hide_settings_screen_options( true, $settings ) );
		$this->assertTrue( Admin_Menu::hide_settings_screen_options( true, WP_Screen::get( get_plugin_page_hookname( 'blockforms', '' ) ) ) );
		$this->assertTrue( Admin_Menu::hide_settings_screen_options( true, WP_Screen::get( 'edit-post' ) ) );
		$this->assertNotFalse( has_filter( 'screen_options_show_screen', [ Admin_Menu::class, 'hide_settings_screen_options' ] ) );
	}

	/**
	 * With the real hooks the Forms submenu reads Entries, Settings.
	 *
	 * @return void
	 */
	public function test_full_submenu_order() {
		global $submenu;
		do_action( 'admin_menu' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		$titles = array_map(
			static function ( $item ) {
				return $item[0];
			},
			$submenu['blockforms']
		);
		$this->assertSame( [ 'Entries', 'Settings' ], array_values( array_unique( $titles ) ) );
	}
}
