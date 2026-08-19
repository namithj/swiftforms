<?php
/**
 * Plugin bootstrap and service container wiring.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms;

/**
 * Singleton bootstrap. Builds the container, then asks every registered
 * service to hook itself into WordPress. Each service decides its own hook
 * (init, rest_api_init, admin_menu, …) inside its register() method, so
 * services stay independently unit-testable without booting the whole plugin.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	private Container $container;

	private bool $booted = false;

	/**
	 * Returns the shared plugin instance, creating it on first access.
	 */
	public static function instance(): self {
		if ( ! self::$instance instanceof self ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->container = new Container();
	}

	/**
	 * The dependency container. Services fetch collaborators from here
	 * (e.g. `swf()->container()->get( 'field_registry' )`) instead of
	 * deep constructor chains.
	 */
	public function container(): Container {
		return $this->container;
	}

	/**
	 * Boots the plugin. Safe to call more than once; only the first call
	 * has any effect.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		add_action( 'init', array( $this, 'load_textdomain' ), 1 );

		$this->define_services();

		foreach ( $this->service_ids_in_boot_order() as $id ) {
			$service = $this->container->get( $id );

			if ( $service instanceof Registrable ) {
				$service->register();
			}
		}
	}

	/**
	 * Loads translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'swiftforms', false, dirname( plugin_basename( SWF_PLUGIN_FILE ) ) . '/languages' );
	}

	/**
	 * Registers every service factory. Nothing here has side effects until
	 * a factory is actually resolved via Container::get().
	 */
	private function define_services(): void {
		$c = $this->container;

		$c->set( 'field_registry', static fn() => new Fields\FieldRegistry() );

		$c->set( 'post_types', static fn() => new PostTypes() );

		$c->set( 'blocks_registrar', static fn( Container $c ) => new Blocks\Registrar( $c->get( 'field_registry' ) ) );

		$c->set( 'skins', static fn() => new Design\Skins() );
		$c->set( 'css_variables', static fn() => new Design\CssVariables() );
		$c->set( 'design_system', static fn( Container $c ) => new Design\DesignSystem( $c->get( 'css_variables' ) ) );

		$c->set( 'privacy', static fn() => new Privacy() );
		$c->set( 'patterns', static fn() => new Patterns() );

		$c->set( 'entry_repository', static fn() => new Entries\EntryRepository() );

		$c->set( 'mailer', static fn() => new Notifications\Mailer() );
		$c->set( 'template_renderer', static fn() => new Notifications\TemplateRenderer() );
		$c->set(
			'notifier',
			static fn( Container $c ) => new Notifications\Notifier( $c->get( 'template_renderer' ), $c->get( 'mailer' ) )
		);
		$c->set( 'webhooks', static fn() => new Notifications\Webhooks() );

		$c->set( 'global_settings_page', static fn( Container $c ) => new Settings\GlobalSettingsPage( $c->get( 'mailer' ) ) );
		$c->set( 'form_settings_metabox', static fn() => new Settings\FormSettingsMetabox() );

		$c->set( 'rate_limiter', static fn() => new Submissions\RateLimiter() );
		$c->set( 'nonce_guard', static fn() => new Submissions\NonceGuard() );
		$c->set( 'spam_guard', static fn() => new Submissions\SpamGuard() );
		$c->set( 'schema_enforcer', static fn( Container $c ) => new Submissions\SchemaEnforcer( $c->get( 'field_registry' ) ) );
		$c->set( 'validator', static fn( Container $c ) => new Submissions\Validator( $c->get( 'field_registry' ) ) );
		$c->set( 'upload_handler', static fn() => new Submissions\UploadHandler() );

		$c->set(
			'pipeline',
			static fn( Container $c ) => new Submissions\Pipeline(
				$c->get( 'rate_limiter' ),
				$c->get( 'nonce_guard' ),
				$c->get( 'spam_guard' ),
				$c->get( 'schema_enforcer' ),
				$c->get( 'validator' ),
				$c->get( 'upload_handler' ),
				$c->get( 'entry_repository' ),
				$c->get( 'notifier' ),
				$c->get( 'webhooks' )
			)
		);

		$c->set( 'submit_controller', static fn( Container $c ) => new Submissions\SubmitController( $c->get( 'pipeline' ) ) );

		$c->set( 'admin_menu', static fn() => new Admin\Menu() );
		$c->set( 'admin_editor_integration', static fn() => new Admin\EditorIntegration() );
	}

	/**
	 * The order services are asked to register() in. Order matters only
	 * where one service's hook must be attached before another fires on the
	 * same WordPress hook/priority; each service is otherwise independent.
	 *
	 * @return string[]
	 */
	private function service_ids_in_boot_order(): array {
		return array(
			'field_registry',
			'post_types',
			'blocks_registrar',
			'skins',
			'css_variables',
			'design_system',
			'privacy',
			'patterns',
			'entry_repository',
			'submit_controller',
			'mailer',
			'global_settings_page',
			'form_settings_metabox',
			'admin_menu',
			'admin_editor_integration',
		);
	}
}
