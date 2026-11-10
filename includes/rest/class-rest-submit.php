<?php
/**
 * REST route: submit.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Rest_Submit
 *
 * POST swiftforms/v1/submit (C-3.4). Public; guarded by the Gate (Rest_Public) and the pipeline (Submission).
 * Accepts JSON or a form-encoded body:
 *
 *   { _sf_form, _sf_source, _sf_token, _sf_hp, _sf_t, _sf_v, sf: { name: value | [values] } }
 *
 * Answers exactly the C-3.4 shapes. "entryId" is added only for an authenticated caller who may view entries;
 * a guest never gets it, and a spam submission is answered like a clean one. Logged-in visitors authenticate
 * with core's cookie and the X-WP-Nonce header; core treats a request without a nonce as a guest (user 0),
 * and rejects a stale one before this callback runs (the view module refreshes it and retries). Responses
 * never echo what was sent.
 */
class Rest_Submit {

	/**
	 * Hook the route.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', [ self::class, 'register' ] );
	}

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public static function register() {
		Rest_Public::register(
			'/submit',
			[
				'methods'  => 'POST',
				'callback' => [ self::class, 'handle' ],
			]
		);
	}

	/**
	 * Handle a submission.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function handle( \WP_REST_Request $request ) {
		$params = $request->is_json_content_type() ? $request->get_json_params() : $request->get_body_params();
		$params = is_array( $params ) ? $params : [];
		$sf     = isset( $params['sf'] ) && is_array( $params['sf'] ) ? $params['sf'] : [];

		$form_id = self::text( $params, '_sf_form' );
		$source  = self::text( $params, '_sf_source' );

		$length  = max( (int) $request->get_header( 'content_length' ), strlen( (string) $request->get_body() ) );
		$refused = Gate::check( $form_id, $source, Gate::count_fields( $sf ), $length, $request );
		if ( null !== $refused ) {
			return Rest_Public::response( $refused );
		}

		$user   = get_current_user_id() > 0 ? wp_get_current_user() : null;
		$result = Submission::handle(
			[
				'form_id'   => $form_id,
				'source'    => $source,
				'token'     => $params['_sf_token'] ?? null,
				'hp'        => $params['_sf_hp'] ?? '',
				't'         => $params['_sf_t'] ?? null,
				'sf'        => $sf,
				'page_hint' => (string) $request->get_header( 'referer' ),
			],
			'rest',
			$user
		);

		return Rest_Public::response( $result, null !== $user && Access::can( 'swiftforms_view_entries' ) );
	}

	/**
	 * A posted string, or an empty string.
	 *
	 * @param array<string, mixed> $params Parameters.
	 * @param string               $key    Key.
	 * @return string
	 */
	private static function text( array $params, $key ) {
		return isset( $params[ $key ] ) && is_string( $params[ $key ] ) ? $params[ $key ] : '';
	}
}
