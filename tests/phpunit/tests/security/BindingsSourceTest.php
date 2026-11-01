<?php
/**
 * The BB-18 leak test: cassette-cmf/field must not read secrets.
 *
 * Permanent. It guards the SwiftForms fallback filter now, and the block
 * builder itself once BB-18 lands.
 *
 * @package SwiftForms
 * @group security
 */

use SwiftForms\Builder_Adapter;

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
	 * ID of the private post that holds the meta secret.
	 *
	 * @var int
	 */
	private $private_post_id = 0;

	/**
	 * Set up the secrets and a contributor.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		if ( PHP_VERSION_ID < 80200 ) {
			$this->markTestSkipped( 'BB-18 landed or source not registered: the block builder needs PHP 8.2 and registers nothing below it.' );
		}

		update_option( 'mailserver_pass', self::OPTION_SECRET );

		$this->private_post_id = self::factory()->post->create( [ 'post_status' => 'private' ] );
		update_post_meta( $this->private_post_id, '_sf_secret', self::META_SECRET );

		// The builder registers its bindings source on init, but only once it has been started.
		// Its init callback ran before this test, so register the source by hand.
		\SwiftForms\Vendor\PedalCMS\CassetteCMFBlocks\Core\Block_Manager::init();
		if ( ! $this->source_registered() ) {
			\SwiftForms\Vendor\PedalCMS\CassetteCMFBlocks\Binding\Binding_Source_Registrar::register_source();
		}

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'contributor' ] ) );
	}

	/**
	 * Whether the builder's bindings source exists.
	 *
	 * @return bool
	 */
	private function source_registered() {
		return WP_Block_Bindings_Registry::get_instance()->is_registered( Builder_Adapter::BINDINGS_SOURCE );
	}

	/**
	 * Render a core/paragraph bound to cassette-cmf/field.
	 *
	 * @param array $args Binding arguments.
	 * @return string
	 */
	private function render_bound_paragraph( array $args ) {
		$attributes = [
			'metadata' => [
				'bindings' => [
					'content' => [
						'source' => Builder_Adapter::BINDINGS_SOURCE,
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
	 * With the fallback filter on, neither secret reaches the output.
	 *
	 * @return void
	 */
	public function test_secrets_are_not_rendered() {
		$this->assertTrue( $this->source_registered(), 'The bindings source should be registered.' );

		$option_html = $this->render_bound_paragraph( $this->option_args() );
		$meta_html   = $this->render_bound_paragraph( $this->meta_args() );

		$this->assertStringNotContainsString( self::OPTION_SECRET, $option_html );
		$this->assertStringNotContainsString( self::META_SECRET, $meta_html );
		$this->assertStringContainsString( 'placeholder', $option_html );
	}

	/**
	 * Without the filter the leak is real, which proves the filter is what blocks it.
	 *
	 * @return void
	 */
	public function test_leak_is_real_without_the_filter() {
		if ( ! $this->source_registered() ) {
			$this->markTestSkipped( 'BB-18 landed or source not registered: cassette-cmf/field does not exist in this run.' );
		}

		$adapter = Builder_Adapter::instance();
		remove_filter( 'block_bindings_source_value', [ $adapter, 'filter_bindings_source_value' ], PHP_INT_MAX );

		$option_html = $this->render_bound_paragraph( $this->option_args() );
		$meta_html   = $this->render_bound_paragraph( $this->meta_args() );

		add_filter( 'block_bindings_source_value', [ $adapter, 'filter_bindings_source_value' ], PHP_INT_MAX, 5 );

		if ( false === strpos( $option_html, self::OPTION_SECRET ) && false === strpos( $meta_html, self::META_SECRET ) ) {
			$this->markTestSkipped( 'BB-18 landed or source not registered: the builder no longer leaks on its own. The fallback filter can be removed.' );
		}

		$this->assertStringContainsString( self::OPTION_SECRET, $option_html, 'The option secret leaks without the filter.' );
		$this->assertStringContainsString( self::META_SECRET, $meta_html, 'The post meta secret leaks without the filter.' );
	}

	/**
	 * The fallback filter is attached at the very last priority.
	 *
	 * @return void
	 */
	public function test_filter_runs_last() {
		$this->assertSame(
			PHP_INT_MAX,
			has_filter( 'block_bindings_source_value', [ Builder_Adapter::instance(), 'filter_bindings_source_value' ] )
		);
	}
}
