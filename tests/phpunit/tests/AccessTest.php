<?php
/**
 * Tests for SwiftForms\Access (C-8.4).
 *
 * @package SwiftForms
 */

use SwiftForms\Access;

/**
 * Class AccessTest
 *
 * @group access
 */
class AccessTest extends WP_UnitTestCase {

	/**
	 * Raw bytes of the roles option.
	 *
	 * @return string
	 */
	private function roles_bytes() {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $wpdb->get_blog_prefix() . 'user_roles' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * The six capabilities exist and map to manage_options by default.
	 *
	 * @return void
	 */
	public function test_default_map() {
		$this->assertCount( 6, Access::CAPS );
		$this->assertSame( array_fill_keys( Access::CAPS, 'manage_options' ), Access::map() );
		$this->assertContains( 'swiftforms_manage_integrations', Access::CAPS );
	}

	/**
	 * Administrators pass, other roles and guests do not, unknown capabilities never.
	 *
	 * @return void
	 */
	public function test_can_by_default() {
		$admin  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );

		foreach ( Access::CAPS as $cap ) {
			$this->assertTrue( Access::can( $cap, $admin ), $cap );
			$this->assertFalse( Access::can( $cap, $editor ), $cap );
			$this->assertFalse( Access::can( $cap, 0 ), $cap );
		}

		wp_set_current_user( $admin );
		$this->assertTrue( Access::can( 'swiftforms_view_entries' ), 'null means the current user.' );
		$this->assertTrue( user_can( $admin, 'swiftforms_view_entries' ), 'The meta capability works through core as well.' ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- A meta capability, mapped by Access::map_meta_cap().

		$this->assertFalse( Access::can( 'manage_options', $admin ), 'Only SwiftForms capabilities go through can().' );
		$this->assertFalse( Access::can( 'swiftforms_bogus', $admin ) );
		wp_set_current_user( 0 );
		$this->assertFalse( Access::can( 'swiftforms_view_entries' ) );
	}

	/**
	 * The filter can grant a capability to another role, and no role is written.
	 *
	 * @return void
	 */
	public function test_filter_grants_to_another_role_without_writing_roles() {
		$admin  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		$author = self::factory()->user->create( [ 'role' => 'author' ] );
		$before = $this->roles_bytes();

		add_filter(
			'swiftforms_capability_map',
			static function ( $map ) {
				$map['swiftforms_view_entries'] = 'edit_others_posts';
				return $map;
			}
		);

		$this->assertTrue( Access::can( 'swiftforms_view_entries', $editor ) );
		$this->assertTrue( Access::can( 'swiftforms_view_entries', $admin ), 'Administrators hold edit_others_posts too.' );
		$this->assertFalse( Access::can( 'swiftforms_view_entries', $author ) );
		$this->assertFalse( Access::can( 'swiftforms_manage_settings', $editor ), 'Only the mapped capability changed.' );

		$this->assertSame( $before, $this->roles_bytes(), 'wp_user_roles is byte-identical.' );
		$this->assertFalse( get_role( 'editor' )->has_cap( 'swiftforms_view_entries' ), 'No role gained the meta capability.' );
	}

	/**
	 * Invalid filter values fall back to manage_options.
	 *
	 * @return void
	 */
	public function test_invalid_filter_values_fall_back() {
		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );

		add_filter(
			'swiftforms_capability_map',
			static function ( $map ) {
				$map['swiftforms_manage_settings']     = 123;
				$map['swiftforms_view_entries']        = '';
				$map['swiftforms_edit_entries']        = [ 'read' ];
				$map['swiftforms_delete_entries']      = 'not a cap!';
				$map['swiftforms_export_entries']      = 'swiftforms_view_entries'; // Would loop through map_meta_cap.
				$map['swiftforms_manage_integrations'] = true;
				$map['swiftforms_bogus']               = 'read';
				return $map;
			}
		);

		$this->assertSame( array_fill_keys( Access::CAPS, 'manage_options' ), Access::map() );
		foreach ( Access::CAPS as $cap ) {
			$this->assertFalse( Access::can( $cap, $editor ), $cap );
		}
		$this->assertFalse( Access::can( 'swiftforms_bogus', $editor ), 'A capability SwiftForms does not define stays unknown.' );
	}

	/**
	 * A filter that returns something other than an array is ignored.
	 *
	 * @return void
	 */
	public function test_non_array_filter_result_is_ignored() {
		add_filter( 'swiftforms_capability_map', '__return_false' );
		$this->assertSame( array_fill_keys( Access::CAPS, 'manage_options' ), Access::map() );
	}

	/**
	 * The meta capability hook is registered once.
	 *
	 * @return void
	 */
	public function test_hook_is_registered() {
		$this->assertSame( 10, has_filter( 'map_meta_cap', [ Access::class, 'map_meta_cap' ] ) );
	}
}
