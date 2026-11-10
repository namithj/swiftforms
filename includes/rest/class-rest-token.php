<?php
/**
 * REST route: token.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Rest_Token
 *
 * GET swiftforms/v1/token?formId=&source= . A page cached for days refreshes its render token and its signed
 * render time from here (ADR-002). Public, throttled, behind the Gate: a pair that is not a live location
 * (or whose source is not open) answers 403 closed with no token and no schema hash. The answer is never
 * cached.
 *
 *   200 { status: "success", token, t }
 */
class Rest_Token {

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
			'/token',
			[
				'methods'  => 'GET',
				'callback' => [ self::class, 'handle' ],
			]
		);
	}

	/**
	 * Issue a token.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function handle( \WP_REST_Request $request ) {
		$query   = $request->get_query_params();
		$form_id = isset( $query['formId'] ) && is_string( $query['formId'] ) ? $query['formId'] : '';
		$source  = isset( $query['source'] ) && is_string( $query['source'] ) ? $query['source'] : '';

		$refused = Gate::check( $form_id, $source, 0, 0, $request );
		if ( null !== $refused ) {
			return Rest_Public::response( $refused );
		}

		$schema = Schema_Resolver::resolve( $form_id, $source );
		if ( null === $schema || ! Submission::is_open( $schema ) ) {
			return Rest_Public::response( Submission_Result::make( Submission_Result::CLOSED ) );
		}

		$response = new \WP_REST_Response(
			[
				'status' => 'success',
				'token'  => Render_Token::issue( $form_id, $source, $schema->hash ),
				't'      => Time_Trap::issue( $form_id, $source ),
			],
			200
		);
		Rest_Public::no_store( $response );
		return $response;
	}
}
