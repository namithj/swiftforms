<?php
/**
 * Tests that the plugin loads and boots cleanly.
 *
 * @package SwiftForms
 */

use SwiftForms\Builder_Adapter;
use SwiftForms\Plugin;

/**
 * Class BootstrapTest
 */
class BootstrapTest extends WP_UnitTestCase {

	/**
	 * The plugin file is loaded and its constants are set.
	 *
	 * @return void
	 */
	public function test_plugin_is_loaded() {
		$this->assertTrue( defined( 'SWIFTFORMS_VERSION' ) );
		$this->assertTrue( class_exists( Plugin::class ) );
		$this->assertSame( 'SwiftForms\\Plugin', get_class( Plugin::instance() ) );
	}

	/**
	 * The version constant matches the plugin header.
	 *
	 * @return void
	 */
	public function test_version_matches_header() {
		$data = get_file_data( SWIFTFORMS_FILE, [ 'Version' => 'Version' ] );
		$this->assertSame( $data['Version'], SWIFTFORMS_VERSION );
	}

	/**
	 * The header declares the floors the plan promises.
	 *
	 * @return void
	 */
	public function test_header_floors() {
		$data = get_file_data(
			SWIFTFORMS_FILE,
			[
				'WP'  => 'Requires at least',
				'PHP' => 'Requires PHP',
			]
		);
		$this->assertSame( SWIFTFORMS_MIN_WP, $data['WP'] );
		$this->assertSame( '8.2', $data['PHP'] );
	}

	/**
	 * Booting wires the adapter and the init hook.
	 *
	 * @return void
	 */
	public function test_boot_wires_hooks() {
		$this->assertNotFalse( has_filter( 'block_bindings_source_value', [ Builder_Adapter::instance(), 'filter_bindings_source_value' ] ) );
		$this->assertSame( 5, has_action( 'init', [ Plugin::instance(), 'register_blocks' ] ) );
	}

	/**
	 * With no block configs, the adapter registers nothing and does not start the builder.
	 *
	 * @return void
	 */
	public function test_no_blocks_registered_in_r00() {
		$this->assertSame( [], Plugin::instance()->block_configs() );
		$registered = array_filter(
			array_keys( WP_Block_Type_Registry::get_instance()->get_all_registered() ),
			static function ( $name ) {
				return 0 === strpos( $name, 'swiftforms/' );
			}
		);
		$this->assertSame( [], array_values( $registered ) );
	}
}
