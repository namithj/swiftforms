<?php
/**
 * Plugin service wiring.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Plugin
 *
 * Wires the services together. It holds no feature logic of its own.
 */
class Plugin {

	/**
	 * Single instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Get the single instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hook everything up. Safe to call more than once.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		Builder_Adapter::instance()->register_hooks();

		// Priority 5: the block builder registers its blocks on init and must see our configs first.
		add_action( 'init', [ $this, 'register_blocks' ], 5 );
	}

	/**
	 * Hand the block configs to the block builder.
	 *
	 * @return void
	 */
	public function register_blocks() {
		Builder_Adapter::instance()->register_blocks( $this->block_configs() );
	}

	/**
	 * Block configurations for the block builder.
	 *
	 * Empty in R00: the blocks themselves are R01.
	 *
	 * @return array<string, mixed>
	 */
	public function block_configs() {
		/**
		 * Filters SwiftForms' internal block configuration (not a public extension point).
		 *
		 * @param array<string, mixed> $config Block builder configuration array.
		 */
		return (array) apply_filters( 'swiftforms_block_configs', [] );
	}
}
