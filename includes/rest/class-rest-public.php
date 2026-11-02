<?php
/**
 * Public REST routes.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Rest_Public
 *
 * The only place SwiftForms registers a route without a capability check. A public route must be named in
 * ROUTES; register() refuses anything else, so adding a public endpoint takes a visible change to this list
 * (and a line in the global route snapshot and the route-inventory test).
 *
 * Every public route runs the Gate before anything else. WordPress parses a request body before it calls the
 * route's callback, so the throttle and the declared body size are checked earlier, on rest_pre_dispatch.
 */
class Rest_Public {

	/**
	 * REST namespace.
	 */
	const REST_NAMESPACE = 'swiftforms/v1';

	/**
	 * The allow-list of routes anybody may call.
	 */
	const ROUTES = [ '/submit', '/token' ];

	/**
	 * Hook the early gate.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'rest_pre_dispatch', [ self::class, 'pre_dispatch' ], 10, 3 );
	}

	/**
	 * Register a public route.
	 *
	 * @param string               $route Route, one of ROUTES.
	 * @param array<string, mixed> $args  Route arguments (methods, callback, args).
	 * @return bool Whether the route was registered.
	 */
	public static function register( $route, array $args ) {
		if ( ! in_array( $route, self::ROUTES, true ) ) {
			_doing_it_wrong( __METHOD__, 'A public route must be listed in Rest_Public::ROUTES.', '1.0.0' );
			return false;
		}
		// The one deliberate public permission callback of the plugin: these routes are the visitor-facing endpoints. The Gate guards them.
		$args['permission_callback'] = '__return_true';
		return register_rest_route( self::REST_NAMESPACE, $route, $args );
	}

	/**
	 * Run the throttle and the size cap before WordPress parses the body of a public route.
	 *
	 * @param mixed            $result  Pre-dispatch result (null to continue).
	 * @param \WP_REST_Server  $server  Server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function pre_dispatch( $result, $server, $request ) {
		unset( $server );
		if ( null !== $result || ! $request instanceof \WP_REST_Request ) {
			return $result;
		}
		if ( ! in_array( $request->get_route(), self::full_routes(), true ) ) {
			return $result;
		}

		$length  = max( (int) $request->get_header( 'content_length' ), strlen( (string) $request->get_body() ) );
		$refused = Gate::early( $length, $request );
		return null === $refused ? $result : self::response( $refused );
	}

	/**
	 * A REST response for a result.
	 *
	 * @param Submission_Result $result           Result.
	 * @param bool              $include_entry_id Whether "entryId" may be added (caller is authenticated and may view entries).
	 * @return \WP_REST_Response
	 */
	public static function response( Submission_Result $result, $include_entry_id = false ) {
		$response = new \WP_REST_Response( $result->body( $include_entry_id ), $result->http );
		self::no_store( $response );
		if ( Submission_Result::RATE_LIMITED === $result->status ) {
			$response->header( 'Retry-After', (string) Throttle::WINDOW );
		}
		return $response;
	}

	/**
	 * Mark a response as uncacheable.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @return void
	 */
	public static function no_store( \WP_REST_Response $response ) {
		foreach ( wp_get_nocache_headers() as $name => $value ) {
			if ( is_string( $value ) && '' !== $value ) {
				$response->header( $name, $value );
			}
		}
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
	}

	/**
	 * The routes with their namespace.
	 *
	 * @return string[]
	 */
	private static function full_routes() {
		return array_map(
			static function ( $route ) {
				return '/' . self::REST_NAMESPACE . $route;
			},
			self::ROUTES
		);
	}
}
