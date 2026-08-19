<?php
/**
 * REST endpoint for form submissions.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Submissions;

use SwiftForms\Registrable;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `POST /swf/v1/submit` — intentionally public (`__return_true`); the
 * pipeline's own nonce/rate-limit/spam checks are the real gate, not a
 * capability check (anonymous visitors must be able to submit forms).
 */
final class SubmitController implements Registrable {

	public function __construct( private Pipeline $pipeline ) {
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			'swf/v1',
			'/submit',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$result = $this->pipeline->handle( $request->get_params(), $request->get_file_params() );

		return new WP_REST_Response( $result['body'], $result['status_code'] );
	}
}
