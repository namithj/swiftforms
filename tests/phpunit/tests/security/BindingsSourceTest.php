<?php
/**
 * The BB-18 leak test: the builder must not expose a bindings source that reads secrets.
 *
 * Permanent. Since the block builder 0.2.0 its bindings source is off unless
 * the host asks for it, and SwiftForms does not. No SwiftForms filter stands in
 * the way any more, so this proves the builder's own default and fails if a
 * future change turns a source on or lets a binding read an option, post meta
 * or term meta by name.
 *
 * @package SwiftForms
 * @group security
 */

use SwiftForms\Plugin;

/**
 * Class BindingsSourceTest
 */
class BindingsSourceTest extends WP_UnitTestCase {

	/**
	 * Secret stored in a core option.
	 */
	const OPTION_SECRET = 'sf-secret-pass';

	/**
	 * Secret stored in protected post meta.
	 */
	const META_SECRET = 'sf-secret-meta';

	/**
	 * Binding source names that must not exist.
	 */
	const SOURCES = [ 'cassette-cmf/field', 'swiftforms/field' ];

	/**
	 * ID of the private post that holds the meta secret.
	 *
	 * @var int
	 */
	private $private_post_id = 0;

	/**
	 * Set up the secrets, a non-empty block config and a contributor.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		update_option( 'mailserver_pass', self::OPTION_SECRET );

		$this->private_post_id = self::factory()->post->create( [ 'post_status' => 'private' ] );
		update_post_meta( $this->private_post_id, '_sf_secret', self::META_SECRET );

		if ( WP_Block_Type_Registry::get_instance()->is_registered( 'swiftforms/throwaway' ) ) {
			unregister_block_type( 'swiftforms/throwaway' );
		}

		// Start the builder the way SwiftForms does, with one throwaway block.
		$manager = \SwiftForms\Vendor\PedalCMS\CassetteCMFBlocks\Core\Block_Manager::class;
		$manager::reset();
		add_filter( 'swiftforms_block_configs', [ $this, 'throwaway_config' ] );
		$this->setExpectedIncorrectUsage( 'SwiftForms\Vendor\PedalCMS\CassetteCMFBlocks\Core\Block_Manager::register_blocks' );
		Plugin::instance()->register_blocks();
		// init has already run in the test process, so register the block types now.
		$manager::init()->register_blocks();
		// What the builder does on init, when the host asked for a source (SwiftForms does not).
		\SwiftForms\Vendor\PedalCMS\CassetteCMFBlocks\Binding\Binding_Source_Registrar::register_source();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'contributor' ] ) );
	}

	/**
	 * Reset the builder so that later tests start clean.
	 *
	 * @return void
	 */
	public function tear_down() {
		if ( WP_Block_Type_Registry::get_instance()->is_registered( 'swiftforms/throwaway' ) ) {
			unregister_block_type( 'swiftforms/throwaway' );
		}
		\SwiftForms\Vendor\PedalCMS\CassetteCMFBlocks\Core\Block_Manager::reset();
		foreach ( self::SOURCES as $source ) {
			if ( WP_Block_Bindings_Registry::get_instance()->is_registered( $source ) ) {
				unregister_block_bindings_source( $source );
			}
		}
		parent::tear_down();
	}

	/**
	 * Block config with one throwaway block.
	 *
	 * @return array
	 */
	public function throwaway_config() {
		return [
			'blocks' => [
				[
					'id'     => 'swiftforms/throwaway',
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

	/**
	 * Render a core/paragraph bound to a source.
	 *
	 * @param string $source Bindings source name.
	 * @param array  $args   Binding arguments.
	 * @return string
	 */
	private function render_bound_paragraph( $source, array $args ) {
		$attributes = [
			'metadata' => [
				'bindings' => [
					'content' => [
						'source' => $source,
						'args'   => $args,
					],
				],
			],
		];

		return do_blocks( '<!-- wp:paragraph ' . wp_json_encode( $attributes ) . ' --><p>placeholder</p><!-- /wp:paragraph -->' );
	}

	/**
	 * Binding arguments that point at the option secret.
	 *
	 * @return array
	 */
	private function option_args() {
		return [
			'field'        => 'pass',
			'context'      => 'mailserver',
			'context_type' => 'settings',
		];
	}

	/**
	 * Binding arguments that point at the post meta secret.
	 *
	 * @return array
	 */
	private function meta_args() {
		return [
			'field'        => '_sf_secret',
			'context'      => $this->private_post_id,
			'context_type' => 'post',
		];
	}

	/**
	 * The builder started, and registered the throwaway block.
	 *
	 * @return void
	 */
	public function test_builder_is_running() {
		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( 'swiftforms/throwaway' ) );
	}

	/**
	 * Neither cassette-cmf/field nor swiftforms/field is a registered bindings source.
	 *
	 * @return void
	 */
	public function test_no_bindings_source_is_registered() {
		foreach ( self::SOURCES as $source ) {
			$this->assertFalse( WP_Block_Bindings_Registry::get_instance()->is_registered( $source ), "{$source} must not be registered." );
		}
	}

	/**
	 * A contributor's preview renders neither secret, whichever source name is used.
	 *
	 * @return void
	 */
	public function test_secrets_are_not_rendered() {
		foreach ( self::SOURCES as $source ) {
			$option_html = $this->render_bound_paragraph( $source, $this->option_args() );
			$meta_html   = $this->render_bound_paragraph( $source, $this->meta_args() );

			$this->assertStringNotContainsString( self::OPTION_SECRET, $option_html, "{$source} leaks the option." );
			$this->assertStringNotContainsString( self::META_SECRET, $meta_html, "{$source} leaks the post meta." );
			$this->assertStringContainsString( 'placeholder', $option_html );
		}
	}

	/**
	 * SwiftForms keeps no callback on block_bindings_source_value (BB-18 needs no fallback).
	 *
	 * @return void
	 */
	public function test_no_swiftforms_bindings_callback() {
		global $wp_filter;

		if ( ! isset( $wp_filter['block_bindings_source_value'] ) ) {
			$this->assertTrue( true );
			return;
		}

		foreach ( $wp_filter['block_bindings_source_value']->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];
				$owner    = '';
				if ( is_array( $function ) ) {
					$owner = is_object( $function[0] ) ? get_class( $function[0] ) : (string) $function[0];
				} elseif ( is_object( $function ) && ! $function instanceof Closure ) {
					$owner = get_class( $function );
				}
				$this->assertStringNotContainsString( 'SwiftForms', $owner, 'SwiftForms must not filter block bindings.' );
			}
		}
	}
}
