<?php
/**
 * Core plugin bootstrap.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

/**
 * Plugin bootstrap: wires services, hooks, and the service container.
 */
class SwiftForms_Core {
	/**
	 * Shared service container.
	 *
	 * @var ArrayObject<string, mixed>
	 */
	private ArrayObject $container;

	/**
	 * Tracks whether init has already executed.
	 *
	 * @var bool
	 */
	private bool $initialized = false;

	/**
	 * Sets up the shared service container.
	 *
	 * @param array<string, mixed> $services Optional services keyed by container id.
	 */
	public function __construct( array $services = array() ) {
		$this->container = new ArrayObject( $services );
	}

	/**
	 * Initializes CPTs, blocks, AJAX handlers, and translations.
	 */
	public function init(): void {
		if ( $this->initialized ) {
			return;
		}

		$this->load_textdomain();
		$this->get_cpts()->register();
		$this->get_blocks()->register_blocks();
		$this->get_privacy()->register();
		$this->get_settings_service()->register();
		$this->get_templates()->register();
		$this->register_ajax_actions();

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		$this->initialized = true;
	}

	/**
	 * Registers the public submission REST endpoint.
	 *
	 * Mirrors the admin-ajax handler (which stays registered for
	 * back-compat); the frontend script prefers this route.
	 */
	public function register_rest_routes(): void {
		register_rest_route(
			'swiftforms/v1',
			'/submit',
			array(
				'callback'            => array( $this, 'handle_rest_submission' ),
				'methods'             => 'POST',
				// The endpoint is intentionally public (logged-out visitors
				// submit forms); abuse is handled by the submission
				// pipeline's nonce, honeypot, captcha, and rate limiting.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handles a REST submission by delegating to the shared pipeline.
	 *
	 * The handler reads the multipart body from the superglobals just
	 * like the AJAX path, so both endpoints behave identically — including
	 * the live-traffic hardening (schema enforcement, rate limiting).
	 */
	public function handle_rest_submission(): WP_REST_Response {
		$response = $this->get_submissions()->handle_submission();

		return new WP_REST_Response( $response, SwiftForms_Submissions::get_status_for_response( $response ) );
	}

	/**
	 * Returns the shared service container.
	 *
	 * @return ArrayObject<string, mixed>
	 */
	public function get_container(): ArrayObject {
		return $this->container;
	}

	/**
	 * Loads translations for the plugin domain.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'swiftforms',
			false,
			dirname( plugin_basename( SWIFTFORMS_FILE ) ) . '/languages'
		);
	}

	/**
	 * Registers AJAX handlers for authenticated and public submissions.
	 */
	public function register_ajax_actions(): void {
		$handler = array( $this->get_submissions(), 'handle_submission' );

		// accepted_args=0: admin-ajax.php triggers this hook with no extra
		// arguments, and WordPress core's do_action() otherwise pads that to a
		// single empty-string argument, which fails handle_submission()'s
		// ?array type hint. Zero accepted args means WP invokes the callback
		// with nothing at all, so its own `$request = null` default applies.
		add_action( 'wp_ajax_swiftforms_submit', $handler, 10, 0 );
		add_action( 'wp_ajax_nopriv_swiftforms_submit', $handler, 10, 0 );
	}

	/**
	 * Returns the CPT registrar service.
	 */
	private function get_cpts(): SwiftForms_CPTs {
		$service = $this->get_service(
			'cpts',
			static fn (): SwiftForms_CPTs => new SwiftForms_CPTs()
		);

		return $service;
	}

	/**
	 * Returns the block registrar service.
	 */
	private function get_blocks(): SwiftForms_Blocks {
		$service = $this->get_service(
			'blocks',
			static fn (): SwiftForms_Blocks => new SwiftForms_Blocks( SWIFTFORMS_PATH )
		);

		return $service;
	}

	/**
	 * Returns the global settings service.
	 */
	private function get_settings_service(): SwiftForms_Settings {
		$service = $this->get_service(
			'settings',
			static fn (): SwiftForms_Settings => new SwiftForms_Settings()
		);

		return $service;
	}

	/**
	 * Returns the privacy/data-lifecycle service.
	 */
	private function get_privacy(): SwiftForms_Privacy {
		$service = $this->get_service(
			'privacy',
			static fn (): SwiftForms_Privacy => new SwiftForms_Privacy()
		);

		return $service;
	}

	/**
	 * Returns the form templates service.
	 */
	private function get_templates(): SwiftForms_Templates {
		$service = $this->get_service(
			'templates',
			static fn (): SwiftForms_Templates => new SwiftForms_Templates()
		);

		return $service;
	}

	/**
	 * Returns the submissions service.
	 */
	private function get_submissions(): SwiftForms_Submissions {
		$service = $this->get_service(
			'submissions',
			static fn (): SwiftForms_Submissions => new SwiftForms_Submissions()
		);

		return $service;
	}

	/**
	 * Lazily resolves and caches services inside the container.
	 *
	 * @template T of object
	 *
	 * @param string   $key     Service container key.
	 * @param callable $factory Service factory.
	 *
	 * @return T
	 */
	private function get_service( string $key, callable $factory ): object {
		if ( ! $this->container->offsetExists( $key ) ) {
			$this->container->offsetSet( $key, $factory() );
		}

		/**
		 * Resolved service instance.
		 *
		 * @var T $service
		 */
		$service = $this->container->offsetGet( $key );

		return $service;
	}
}
