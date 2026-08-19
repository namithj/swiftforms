<?php
/**
 * Submission security-refresh route tests.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Tests\Submissions;

use SwiftForms\Submissions\SubmitController;
use SwiftForms\Tests\TestCase;
use WP_REST_Request;

final class SubmitControllerTest extends TestCase {

	public function test_challenge_refresh_requires_a_published_form(): void {
		$controller = $this->controller();
		$published  = $this->create_form( '', array( 'enableCaptcha' => true ) );
		$draft      = $this->create_form();
		wp_update_post(
			array(
				'ID'          => $draft,
				'post_status' => 'draft',
			)
		);

		$request = new WP_REST_Request( 'GET' );
		$request->set_param( 'form_id', $published );
		$response = $controller->challenge( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'captcha', $response->get_data() );
		$this->assertNotEmpty( $response->get_data()['nonce'] );

		$request->set_param( 'form_id', $draft );
		$this->assertSame( 404, $controller->challenge( $request )->get_status() );
	}

	private function controller(): SubmitController {
		return smartlogix_swiftforms()->container()->get( 'submit_controller' );
	}
}
