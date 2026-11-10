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
		$this->assertSame( '7.4', $data['PHP'] );
	}

	/**
	 * Booting wires the init hook (the adapter attaches nothing until there are blocks).
	 *
	 * @return void
	 */
	public function test_boot_wires_hooks() {
		$this->assertSame( 5, has_action( 'init', [ Plugin::instance(), 'register_blocks' ] ) );
	}

	/**
	 * With no block configs, the adapter registers nothing and does not start the builder.
	 *
	 * SwiftForms itself always has blocks (they are registered on init in the bootstrap), so this is a
	 * property of the adapter: an empty configuration never creates the builder's block manager.
	 *
	 * @return void
	 */
	public function test_empty_config_does_not_start_the_builder() {
		\SwiftForms\Vendor\PedalCMS\CassetteCMFBlocks\Core\Block_Manager::reset();

		Builder_Adapter::instance()->register_blocks( [] );
		Builder_Adapter::instance()->register_blocks( [ 'blocks' => [] ] );

		$this->assertNull( \SwiftForms\Vendor\PedalCMS\CassetteCMFBlocks\Core\Block_Manager::get_instance(), 'The builder must not start without blocks.' );
	}

	/**
	 * The plugin's own blocks are registered through the builder on init.
	 *
	 * @return void
	 */
	public function test_blocks_are_registered_on_init() {
		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( 'swiftforms/form' ) );
		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( 'swiftforms/field-text' ) );
		$this->assertTrue( has_action( 'enqueue_block_assets', [ Builder_Adapter::instance(), 'strip_front_end_style' ] ) !== false );
	}
}
