<?php
/**
 * The block builder's editor assets must not reach the front end.
 *
 * The builder enqueues its stylesheet on enqueue_block_assets, which also runs
 * on every front-end page once the builder has started. The adapter removes it
 * there and keeps it in the editor.
 *
 * @package BlockForms
 * @group assets
 */

use BlockForms\Plugin;

/**
 * Class FrontEndAssetsTest
 */
class FrontEndAssetsTest extends WP_UnitTestCase {

	/**
	 * Set up the builder with one throwaway block.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		if ( WP_Block_Type_Registry::get_instance()->is_registered( 'blockforms/throwaway' ) ) {
			unregister_block_type( 'blockforms/throwaway' );
		}
		$manager = \BlockForms\Vendor\PedalCMS\CassetteCMFBlocks\Core\Block_Manager::class;
		$manager::reset();
		add_filter(
			'blockforms_block_configs',
			static function () {
				return [
					'blocks' => [
						[
							'id'     => 'blockforms/throwaway',
							'fields' => [
								[
									'name'  => 'title',
									'type'  => 'text',
									'label' => 'Title',
								],
							],
						],
					],
				];
			}
		);
		$this->setExpectedIncorrectUsage( 'BlockForms\Vendor\PedalCMS\CassetteCMFBlocks\Core\Block_Manager::register_blocks' );
		Plugin::instance()->register_blocks();

		$GLOBALS['wp_styles']  = new WP_Styles();
		$GLOBALS['wp_scripts'] = new WP_Scripts();
	}

	/**
	 * Reset the builder and the screen.
	 *
	 * @return void
	 */
	public function tear_down() {
		if ( WP_Block_Type_Registry::get_instance()->is_registered( 'blockforms/throwaway' ) ) {
			unregister_block_type( 'blockforms/throwaway' );
		}
		\BlockForms\Vendor\PedalCMS\CassetteCMFBlocks\Core\Block_Manager::reset();
		unset( $GLOBALS['current_screen'] );
		parent::tear_down();
	}

	/**
	 * Handles of the queued styles and scripts that belong to BlockForms or the builder.
	 *
	 * @return string[]
	 */
	private function own_handles() {
		$handles = array_merge( wp_styles()->queue, wp_scripts()->queue );
		return array_values(
			array_filter(
				$handles,
				static function ( $handle ) {
					return 0 === strpos( $handle, 'blockforms' ) || 0 === strpos( $handle, 'cassette' );
				}
			)
		);
	}

	/**
	 * On the front end nothing of ours is queued by enqueue_block_assets.
	 *
	 * @return void
	 */
	public function test_front_end_has_no_builder_assets() {
		set_current_screen( 'front' );
		do_action( 'enqueue_block_assets' );

		$this->assertSame( [], $this->own_handles() );
		$this->assertFalse( wp_style_is( 'blockforms-blocks-editor-style', 'registered' ) );
	}

	/**
	 * The editor keeps the stylesheet.
	 *
	 * @return void
	 */
	public function test_editor_keeps_the_builder_style() {
		set_current_screen( 'edit-post' );
		do_action( 'enqueue_block_assets' );

		$this->assertContains( 'blockforms-blocks-editor-style', $this->own_handles() );
	}
}
