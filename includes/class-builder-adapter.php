<?php
/**
 * The only door to the block builder.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Builder_Adapter
 *
 * The only class that references the Strauss-prefixed block builder. A late
 * framework change is absorbed here without touching stored data.
 *
 * State delivered with the frameworks 0.2.x (see .ai/handover.md):
 * - No fallbacks are in force. The builder's bindings source is off by default
 *   (BB-18), so SwiftForms boots it without one; BindingsSourceTest guards that.
 * - PHP namespaces are prefixed by Strauss (SwiftForms\Vendor\); every name that
 *   WordPress or the browser sees (script and style handles, REST namespace,
 *   JS global) is derived from the builder's boot prefix PREFIX.
 * - The builder boots lazily, on the first non-empty block configuration, so a
 *   site with no SwiftForms blocks gets no REST route, no editor assets and no
 *   hooks from it.
 * - cassette-cmf is not booted: the builder uses it only as a library (field
 *   sanitising and validation); SwiftForms registers none of its settings pages,
 *   assets or REST routes.
 * - The builder's stylesheet is for the editor. It is removed again on the front
 *   end (strip_front_end_style()).
 */
class Builder_Adapter {

	/**
	 * Boot prefix of the block builder (handles, REST namespace, JS global).
	 */
	const PREFIX = 'swiftforms';

	/**
	 * Single instance.
	 *
	 * @var Builder_Adapter|null
	 */
	private static $instance = null;

	/**
	 * Get the single instance.
	 *
	 * @return Builder_Adapter
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Boot the block builder, without a bindings source.
	 *
	 * Safe to repeat: the builder ignores an identical second boot, and
	 * WordPress ignores the same callback added twice.
	 *
	 * @return bool False when the builder is not available.
	 */
	private function boot() {
		$class = '\\SwiftForms\\Vendor\\PedalCMS\\CassetteCMFBlocks\\CassetteCMFBlocks';
		if ( ! class_exists( $class ) ) {
			return false;
		}

		// No 'bindings_source': it stays off (BB-18), and no 'prefix'-named source exists.
		$class::boot( [ 'prefix' => self::PREFIX ] );

		// Priority 100: after the builder's own enqueue at priority 10.
		add_action( 'enqueue_block_assets', [ $this, 'strip_front_end_style' ], 100 );

		return true;
	}

	/**
	 * Remove the builder's editor stylesheet from the front end.
	 *
	 * The builder enqueues it on enqueue_block_assets, which also runs on the
	 * front end of every page once it has started. The editor (admin, incl. the
	 * iframed canvas) keeps it; SwiftForms ships its own front-end stylesheet.
	 *
	 * @return void
	 */
	public function strip_front_end_style() {
		if ( is_admin() ) {
			return;
		}
		wp_dequeue_style( self::PREFIX . '-blocks-editor-style' );
		wp_deregister_style( self::PREFIX . '-blocks-editor-style' );
	}

	/**
	 * Register blocks through the block builder.
	 *
	 * Boots the builder first, but only when there is something to register.
	 *
	 * @param array<string, mixed> $config Builder configuration ('blocks' => [...]).
	 * @return void
	 */
	public function register_blocks( array $config ) {
		if ( empty( $config['blocks'] ) ) {
			return;
		}

		if ( ! $this->boot() ) {
			return;
		}

		$class = '\\SwiftForms\\Vendor\\PedalCMS\\CassetteCMFBlocks\\CassetteCMFBlocks';
		$class::register_from_array( $config );
	}
}
