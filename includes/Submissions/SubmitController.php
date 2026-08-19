<?php
/**
 * REST endpoint for form submissions.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Submissions;

use SwiftForms\PostTypes;
use SwiftForms\Registrable;
use SwiftForms\Settings\FormSettings;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `POST /smartlogix-swiftforms/v1/submit` — intentionally public (`__return_true`); the
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
			'smartlogix-swiftforms/v1',
			'/submit',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'smartlogix-swiftforms/v1',
			'/challenge/(?P<form_id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'challenge' ),
				'permission_callback' => '__return_true',
				'args'                => array( 'form_id' => array( 'sanitize_callback' => 'absint' ) ),
			)
		);
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$result = $this->pipeline->handle( $request->get_params(), $request->get_file_params() );

		return new WP_REST_Response( $result['body'], $result['status_code'] );
	}

	public function challenge( WP_REST_Request $request ): WP_REST_Response {
		$form_id = (int) $request['form_id'];
		if ( PostTypes::FORM_POST_TYPE !== get_post_type( $form_id ) || 'publish' !== get_post_status( $form_id ) ) {
			return new WP_REST_Response( array( 'code' => 'smartlogix_swiftforms_form_unavailable' ), 404 );
		}

		$body = array(
			'nonce'     => ( new NonceGuard() )->create(),
			'render_ts' => TimeTrap::build(),
		);
		if ( FormSettings::get( $form_id )['enableCaptcha'] ) {
			$body['captcha'] = Captcha::build();
			/* translators: 1: first number, 2: second number. */
			$body['captcha']['question'] = sprintf( __( 'What is %1$d + %2$d?', 'swiftforms' ), $body['captcha']['a'], $body['captcha']['b'] );
		}

		return new WP_REST_Response( $body );
	}
}
