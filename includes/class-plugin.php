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

		Settings::init();
		Access::init();
		Cron::init();
		Activator::init();
		Source_Stack::init();
		Form_Index::init();
		Form_Id_Writer::init();
		Assets::init();
		Form_Renderer::init();
		Rest_Public::init();
		Rest_Submit::init();
		Rest_Token::init();
		Rest_Nonce::init();
		Nojs_Handler::init();
		Admin_Menu::init();
		Rest_Entries::init();
		Settings_Page::init();
		Privacy::init();
		Mail_Notice::init();

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
	 * Block configurations for the block builder: the form, field, submit and confirmation blocks, the
	 * "Forms" categories and the six patterns (Block_Configs).
	 *
	 * @return array<string, mixed>
	 */
	public function block_configs() {
		/**
		 * Filters SwiftForms' internal block configuration (not a public extension point).
		 *
		 * @param array<string, mixed> $config Block builder configuration array.
		 */
		return (array) apply_filters( 'swiftforms_block_configs', Block_Configs::all() );
	}
}
