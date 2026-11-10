<?php
/**
 * Form posts without JavaScript.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Nojs_Handler
 *
 * The form is a plain <form method="post"> to the same URL (ADR-002), so it works without JavaScript and
 * behind a page cache. On `template_redirect`:
 *
 * POST with `_sf_form` (nothing else makes this run, and never for REST, admin, AJAX, cron or CLI):
 *   Gate (throttle, size, live location; a refusal is a plain wp_die() page, nothing is stored)
 *   -> `_wpnonce` for a logged-in user (an invalid one means a guest) -> Submission
 *   -> Post/Redirect/Get: a 303 to the same URL with `?sf_result={key}`. The key is 128 random bits and
 *      points at a 10-minute transient that holds this visitor's result only: { form_id, source, instance,
 *      state: { values, errors, success, confirmation_html, notice, notice_type } }.
 *   A redirect confirmation skips that and sends a 303 straight to its target.
 *
 * GET with `sf_result`: read the transient and DELETE it (single read), fill Form_State for the form and
 * instance so the form renders with its errors, values or confirmation, and make the page uncacheable
 * (nocache_headers(), DONOTCACHEPAGE, Referrer-Policy: no-referrer). A stale, unknown or malformed key
 * renders the plain form (still uncacheable).
 *
 * An expired token re-renders the form with the visitor's values and "Please submit again."; the render
 * brings a fresh token.
 */
class Nojs_Handler {

	/**
	 * Query argument that carries the result key.
	 */
	const RESULT_PARAM = 'sf_result';

	/**
	 * Transient name prefix.
	 */
	const TRANSIENT_PREFIX = 'swiftforms_result_';

	/**
	 * Seconds a result is kept.
	 */
	const TTL = 600;

	/**
	 * Whether the current request was made uncacheable by this class (tests read it).
	 *
	 * @var bool
	 */
	public static $uncacheable = false;

	/**
	 * Headers sent for an uncacheable result page (tests read them).
	 *
	 * @var string[]
	 */
	public static $headers = [];

	/**
	 * Hook the handler.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'template_redirect', [ self::class, 'maybe_handle' ], 1 );
	}

	/**
	 * Entry point: do something only for a front-end page view that carries a form post or a result key.
	 *
	 * @return void
	 */
	public static function maybe_handle() {
		if ( ! self::is_page_view() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Public form post: no nonce exists for a guest; logged-in users are checked in handle_post().
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['_sf_form'] ) ) {
			self::handle_post();
			return;
		}
		if ( isset( $_GET[ self::RESULT_PARAM ] ) ) {
			self::show_result( wp_unslash( $_GET[ self::RESULT_PARAM ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated as 32 hex characters in show_result().
		}
		// phpcs:enable
	}

	/**
	 * Whether this is a front-end page view (not REST, admin, AJAX, cron, CLI, XML-RPC).
	 *
	 * @return bool
	 */
	private static function is_page_view() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || defined( 'REST_REQUEST' ) || defined( 'XMLRPC_REQUEST' ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		return ! is_feed();
	}

	/**
	 * Process a form post.
	 *
	 * @return void
	 */
	private static function handle_post() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- See maybe_handle(); the nonce of a logged-in user is verified below.
		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every value is type-checked and cleaned by the pipeline.
		// phpcs:enable

		$sf      = isset( $post['sf'] ) && is_array( $post['sf'] ) ? $post['sf'] : [];
		$form_id = isset( $post['_sf_form'] ) && is_string( $post['_sf_form'] ) ? $post['_sf_form'] : '';
		$source  = isset( $post['_sf_source'] ) && is_string( $post['_sf_source'] ) ? $post['_sf_source'] : '';
		$length  = isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
		$count   = Gate::count_fields( $sf ) + max( 0, count( $post ) - 1 );

		$refused = Gate::check( $form_id, $source, $count, $length );
		if ( null !== $refused ) {
			self::refuse( $refused );
			return;
		}

		$user = null;
		if ( is_user_logged_in() && isset( $post['_wpnonce'] ) && is_string( $post['_wpnonce'] ) && false !== wp_verify_nonce( $post['_wpnonce'], 'wp_rest' ) ) {
			$user = wp_get_current_user();
		}

		$instance = isset( $post['_sf_i'] ) && is_string( $post['_sf_i'] ) && 1 === preg_match( '/^[1-9][0-9]{0,2}$/D', $post['_sf_i'] ) ? (int) $post['_sf_i'] : 1;

		$result = Submission::handle(
			[
				'form_id'   => $form_id,
				'source'    => $source,
				'token'     => $post['_sf_token'] ?? null,
				'hp'        => $post['_sf_hp'] ?? '',
				't'         => $post['_sf_t'] ?? null,
				'sf'        => $sf,
				'page_hint' => self::request_url( false ),
			],
			'nojs',
			$user
		);

		self::finish( $result, $form_id, $source, $instance );
	}

	/**
	 * Answer a Gate refusal with a bare error page. Nothing is stored and nothing the visitor sent is shown.
	 *
	 * @param Submission_Result $refused The refusal.
	 * @return void
	 */
	private static function refuse( Submission_Result $refused ) {
		nocache_headers();
		if ( Submission_Result::RATE_LIMITED === $refused->status ) {
			$message = __( 'Too many requests. Please wait a minute and try again.', 'swiftforms' );
		} elseif ( Submission_Result::CLOSED === $refused->status ) {
			$message = __( 'This form is not accepting submissions.', 'swiftforms' );
		} else {
			$message = __( 'Your request could not be processed.', 'swiftforms' );
		}
		wp_die( esc_html( $message ), '', [ 'response' => (int) $refused->http ] );
	}

	/**
	 * Turn a result into a redirect.
	 *
	 * @param Submission_Result $result   Result.
	 * @param string            $form_id  Effective formId.
	 * @param string            $source   Source reference.
	 * @param int               $instance Instance of the form on the page.
	 * @return void
	 */
	private static function finish( Submission_Result $result, $form_id, $source, $instance ) {
		$state = [
			'values' => $result->values,
		];

		switch ( $result->status ) {
			case Submission_Result::SUCCESS:
				if ( 'redirect' === ( $result->confirmation['type'] ?? '' ) && ! empty( $result->confirmation['url'] ) ) {
					self::redirect( $result->confirmation['url'], false );
					return;
				}
				$state = [
					'success'           => true,
					'confirmation_html' => (string) ( $result->confirmation['html'] ?? '' ),
				];
				break;

			case Submission_Result::INVALID:
				$state['errors'] = $result->errors;
				break;

			case Submission_Result::EXPIRED:
				$state['notice']      = __( 'Please submit again.', 'swiftforms' );
				$state['notice_type'] = 'info';
				break;

			case Submission_Result::RATE_LIMITED:
				$state['notice']      = __( 'Too many submissions. Please wait a minute and try again.', 'swiftforms' );
				$state['notice_type'] = 'error';
				break;

			default:
				$state['notice']      = __( 'This form is not accepting submissions.', 'swiftforms' );
				$state['notice_type'] = 'error';
		}

		$key = Random::token( 16 );
		set_transient(
			self::TRANSIENT_PREFIX . $key,
			[
				'form_id'  => $form_id,
				'source'   => $source,
				'instance' => $instance,
				'state'    => $state,
			],
			self::TTL
		);

		self::redirect( add_query_arg( self::RESULT_PARAM, $key, self::request_url( true ) ), true );
	}

	/**
	 * Read a stored result (once) and prepare the page.
	 *
	 * @param mixed $key The sf_result query value.
	 * @return void
	 */
	private static function show_result( $key ) {
		self::make_uncacheable();

		if ( ! is_string( $key ) || 1 !== preg_match( '/^[0-9a-f]{32}$/D', $key ) ) {
			return;
		}

		$name = self::TRANSIENT_PREFIX . $key;
		$data = get_transient( $name );
		delete_transient( $name );

		if ( ! is_array( $data ) || ! isset( $data['form_id'], $data['instance'], $data['state'] ) || ! Uuid::is_valid( $data['form_id'] ) || ! is_array( $data['state'] ) ) {
			return;
		}
		Form_State::set( $data['form_id'], $data['state'], (int) $data['instance'] );
	}

	/**
	 * Keep the page out of every cache and out of Referer headers.
	 *
	 * @return void
	 */
	private static function make_uncacheable() {
		self::$uncacheable = true;
		self::$headers     = [];

		nocache_headers();
		foreach ( wp_get_nocache_headers() as $name => $value ) {
			if ( is_string( $value ) && '' !== $value ) {
				self::$headers[] = $name . ': ' . $value;
			}
		}
		self::send_header( 'Referrer-Policy: no-referrer' );

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- The constant page caches look for.
		}
	}

	/**
	 * Send one header (and record it).
	 *
	 * @param string $header Header line.
	 * @return void
	 */
	private static function send_header( $header ) {
		self::$headers[] = $header;
		if ( ! headers_sent() ) {
			header( $header );
		}
	}

	/**
	 * Redirect with a 303 and stop.
	 *
	 * @param string $url       Target.
	 * @param bool   $same_site Whether the target is on this site (wp_safe_redirect) or admin-configured (wp_redirect).
	 * @return void
	 */
	private static function redirect( $url, $same_site ) {
		nocache_headers();
		if ( $same_site ) {
			wp_safe_redirect( $url, 303, 'SwiftForms' );
		} else {
			wp_redirect( $url, 303, 'SwiftForms' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- The target is the form author's redirectUrl or page, validated as http(s) by Merge_Tags::redirect_url(); visitor values sit in the query only.
		}
		exit;
	}

	/**
	 * Absolute URL of the current request on this site.
	 *
	 * @param bool $without_result Whether to remove an existing sf_result argument.
	 * @return string
	 */
	private static function request_url( $without_result ) {
		$uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Appended to this site's own origin and sanitised by the redirect functions.
		$uri = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $uri );
		if ( '' === $uri || '/' !== $uri[0] || 0 === strpos( $uri, '//' ) ) {
			$uri = '/';
		}
		if ( $without_result ) {
			$uri = remove_query_arg( self::RESULT_PARAM, $uri );
		}

		$home   = wp_parse_url( home_url() );
		$origin = ( $home['scheme'] ?? 'http' ) . '://' . ( $home['host'] ?? '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
		return $origin . $uri;
	}
}
