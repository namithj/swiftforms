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
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' === $method && isset( $_POST['_sf_form'] ) ) {
			self::handle_post();
			return;
		}
		if ( isset( $_GET[ self::RESULT_PARAM ] ) && is_string( $_GET[ self::RESULT_PARAM ] ) ) {
			self::show_result( sanitize_key( wp_unslash( $_GET[ self::RESULT_PARAM ] ) ) );
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
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- See maybe_handle(); a guest posts no nonce (the Gate, token, time trap and honeypot protect the route); the nonce of a logged-in user is verified below.
		// Each control is read on its own and sanitised as it is read; the field values are read per field of the
		// form's schema in posted_fields(), after the Gate has checked the size of the post.
		$shape   = isset( $_POST['sf'] ) && is_array( $_POST['sf'] ) ? map_deep( wp_unslash( $_POST['sf'] ), 'absint' ) : []; // Structure only, for counting.
		$form_id = self::posted_text( '_sf_form' );
		$source  = self::posted_text( '_sf_source' );
		$length  = isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
		$count   = Gate::count_fields( $shape ) + max( 0, count( $_POST ) - 1 );

		$refused = Gate::check( $form_id, $source, $count, $length );
		if ( null !== $refused ) {
			self::refuse( $refused );
			return;
		}

		$schema = Schema_Resolver::resolve( $form_id, $source );
		$sf     = null === $schema ? [] : self::posted_fields( $schema );

		$nonce = self::posted_text( '_wpnonce' );
		$user  = null;
		if ( is_user_logged_in() && '' !== $nonce && false !== wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			$user = wp_get_current_user();
		}

		$instance_raw = self::posted_text( '_sf_i' );
		$instance     = 1 === preg_match( '/^[1-9][0-9]{0,2}$/D', $instance_raw ) ? absint( $instance_raw ) : 1;

		// A honeypot value that is not a plain string, or that cleans to nothing, still counts as filled in.
		$hp = isset( $_POST['_sf_hp'] ) ? self::posted_text( '_sf_hp' ) : '';
		if ( isset( $_POST['_sf_hp'] ) && '' === $hp && ( ! is_string( $_POST['_sf_hp'] ) || '' !== $_POST['_sf_hp'] ) ) {
			$hp = '1';
		}
		$token = isset( $_POST['_sf_token'] ) ? self::posted_text( '_sf_token' ) : null;
		$t     = isset( $_POST['_sf_t'] ) ? self::posted_text( '_sf_t' ) : null;
		// phpcs:enable

		$result = Submission::handle(
			[
				'form_id'   => $form_id,
				'source'    => $source,
				'token'     => $token,
				'hp'        => $hp,
				't'         => $t,
				'sf'        => $sf,
				'page_hint' => self::request_url( false ),
			],
			'nojs',
			$user
		);

		self::finish( $result, $form_id, $source, $instance );
	}

	/**
	 * The posted values of the schema's fields, each sanitised as it is read: a choice keeps an exact option
	 * value of the form, a textarea goes through sanitize_textarea_field(), every other field through
	 * sanitize_text_field(). Validator::collect() then applies the same rules on every transport.
	 *
	 * @param Form_Schema $schema Schema of the posted form.
	 * @return array<string, string|string[]>
	 */
	private static function posted_fields( Form_Schema $schema ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- See handle_post().
		$sf = [];
		if ( ! isset( $_POST['sf'] ) || ! is_array( $_POST['sf'] ) ) {
			return $sf;
		}
		foreach ( $schema->fields as $field ) {
			$name = (string) $field['name'];
			if ( ! isset( $_POST['sf'][ $name ] ) ) {
				continue;
			}
			$options   = Validator::option_values( $field ) ?? [];
			$multiline = 'textarea' === (string) $field['type'];

			if ( is_array( $_POST['sf'][ $name ] ) ) {
				$list = [];
				$keys = array_keys( map_deep( wp_unslash( $_POST['sf'][ $name ] ), 'absint' ) ); // Structure only.
				foreach ( array_slice( $keys, 0, Validator::MAX_LIST ) as $key ) {
					if ( ! isset( $_POST['sf'][ $name ][ $key ] ) || ! is_string( $_POST['sf'][ $name ][ $key ] ) ) {
						continue;
					}
					$item = sanitize_text_field( wp_unslash( $_POST['sf'][ $name ][ $key ] ) );
					foreach ( $options as $option ) {
						if ( in_array( $option, (array) wp_unslash( $_POST['sf'][ $name ][ $key ] ), true ) ) {
							$item = $option;
							break;
						}
					}
					$list[] = $item;
				}
				$sf[ $name ] = $list;
				continue;
			}

			if ( ! is_string( $_POST['sf'][ $name ] ) ) {
				continue;
			}
			$value = $multiline ? sanitize_textarea_field( wp_unslash( $_POST['sf'][ $name ] ) ) : sanitize_text_field( wp_unslash( $_POST['sf'][ $name ] ) );
			foreach ( $options as $option ) {
				if ( in_array( $option, (array) wp_unslash( $_POST['sf'][ $name ] ), true ) ) {
					$value = $option;
					break;
				}
			}
			$sf[ $name ] = $value;
		}
		// phpcs:enable
		return $sf;
	}

	/**
	 * One posted control as a sanitised string, or an empty string when it is missing or not a string.
	 *
	 * @param string $key Key in $_POST.
	 * @return string
	 */
	private static function posted_text( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- See handle_post().
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
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
	 * @param bool   $same_site Whether the target is on this site, or the form author's redirect URL (its host is allowed for this redirect).
	 * @return void
	 */
	private static function redirect( $url, $same_site ) {
		nocache_headers();
		if ( ! $same_site ) {
			// The form author's redirectUrl (validated as http(s) by Merge_Tags::redirect_url()): allow its host for this redirect only.
			$host = wp_parse_url( $url, PHP_URL_HOST );
			if ( is_string( $host ) && '' !== $host ) {
				add_filter(
					'allowed_redirect_hosts',
					static function ( $hosts ) use ( $host ) {
						$hosts[] = $host;
						return $hosts;
					}
				);
			}
		}
		wp_safe_redirect( $url, 303, 'SwiftForms' );
		exit;
	}

	/**
	 * Absolute URL of the current request on this site.
	 *
	 * @param bool $without_result Whether to remove an existing sf_result argument.
	 * @return string
	 */
	private static function request_url( $without_result ) {
		$uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
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
