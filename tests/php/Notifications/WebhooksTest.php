<?php
/**
 * Tests for safe webhook delivery.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Tests\Notifications;

use SwiftForms\Notifications\Webhooks;
use SwiftForms\Tests\TestCase;

final class WebhooksTest extends TestCase {

	public function test_sends_https_webhooks_with_safe_request_arguments(): void {
		$captured_request = array();
		$capture          = static function ( $preempt, array $args, string $url ) use ( &$captured_request ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			return array(
				'body'     => '',
				'headers'  => array(),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		};
		add_filter( 'pre_http_request', $capture, 10, 3 );

		( new Webhooks() )->send(
			44,
			9,
			array(
				array(
					'slug'       => 'email',
					'type'       => 'email',
					'value'      => 'person@example.com',
					'attributes' => array(),
				),
			),
			array( 'webhookUrl' => 'https://example.org/webhooks/swiftforms' )
		);

		remove_filter( 'pre_http_request', $capture, 10 );

		$this->assertSame( 'https://example.org/webhooks/swiftforms', $captured_request['url'] );
		$this->assertTrue( $captured_request['args']['reject_unsafe_urls'] );
		$this->assertSame( 0, $captured_request['args']['redirection'] );
		$this->assertFalse( $captured_request['args']['blocking'] );
	}

	public function test_does_not_send_to_non_https_webhook_urls(): void {
		$requests = 0;
		$capture  = static function ( $preempt ) use ( &$requests ) {
			++$requests;

			return $preempt;
		};
		add_filter( 'pre_http_request', $capture, 10, 3 );

		( new Webhooks() )->send(
			44,
			9,
			array(),
			array( 'webhookUrl' => 'http://example.org/webhooks/swiftforms' )
		);

		remove_filter( 'pre_http_request', $capture, 10 );

		$this->assertSame( 0, $requests );
	}
}
