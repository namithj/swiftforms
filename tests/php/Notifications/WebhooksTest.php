<?php
/**
 * Tests for reliable signed webhook delivery.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Tests\Notifications;

use SwiftForms\Entries\EntryRepository;
use SwiftForms\Notifications\Webhooks;
use SwiftForms\Tests\TestCase;

final class WebhooksTest extends TestCase {

	private Webhooks $webhooks;

	public function set_up(): void {
		parent::set_up();
		$this->webhooks = new Webhooks();
	}

	public function tear_down(): void {
		wp_clear_scheduled_hook( Webhooks::CRON_HOOK );
		parent::tear_down();
	}

	public function test_queues_and_sends_a_signed_idempotent_payload(): void {
		$secret   = 'never-log-this-secret';
		$form_id  = $this->create_form(
			'',
			array(
				'webhookUrl'    => 'https://example.org/hook',
				'webhookSecret' => $secret,
			)
		);
		$entry_id = $this->entry( $form_id );
		$request  = array();
		$capture  = static function ( $preempt, array $args, string $url ) use ( &$request ) {
			$request = array(
				'args' => $args,
				'url'  => $url,
			);
			return self::response( 204 );
		};
		add_filter( 'pre_http_request', $capture, 10, 3 );

		$this->webhooks->queue(
			$entry_id,
			$form_id,
			$this->fields(),
			array(
				'webhookUrl'    => 'https://example.org/hook',
				'webhookSecret' => $secret,
			)
		);
		$this->assertSame( 'queued', get_post_meta( $entry_id, '_smartlogix_swiftforms_delivery_webhook', true ) );
		$this->assertNotFalse( wp_next_scheduled( Webhooks::CRON_HOOK, array( $entry_id ) ) );
		wp_clear_scheduled_hook( Webhooks::CRON_HOOK, array( $entry_id ) );
		$this->webhooks->deliver( $entry_id );
		remove_filter( 'pre_http_request', $capture, 10 );

		$headers = $request['args']['headers'];
		$this->assertSame( 'https://example.org/hook', $request['url'] );
		$this->assertTrue( $request['args']['blocking'] );
		$this->assertSame( 'entry-' . $entry_id, $headers['X-SwiftForms-Idempotency-Key'] );
		$this->assertSame( 'v1=' . hash_hmac( 'sha256', $headers['X-SwiftForms-Timestamp'] . '.' . $request['args']['body'], $secret ), $headers['X-SwiftForms-Signature'] );
		$this->assertStringNotContainsString( $secret, $request['args']['body'] );
		$this->assertSame( 'sent', get_post_meta( $entry_id, '_smartlogix_swiftforms_delivery_webhook', true ) );
		$this->assertSame( '1', get_post_meta( $entry_id, '_smartlogix_swiftforms_delivery_webhook_attempts', true ) );
	}

	public function test_retries_transient_failures_with_a_bounded_backoff(): void {
		$form_id  = $this->create_form(
			'',
			array(
				'webhookUrl'    => 'https://example.org/hook',
				'webhookSecret' => 'secret',
			)
		);
		$entry_id = $this->entry( $form_id );
		$keys     = array();
		$capture  = static function ( $preempt, array $args ) use ( &$keys ) {
			$keys[] = $args['headers']['X-SwiftForms-Idempotency-Key'];
			return self::response( 503 );
		};
		add_filter( 'pre_http_request', $capture, 10, 3 );
		$this->webhooks->queue(
			$entry_id,
			$form_id,
			$this->fields(),
			array(
				'webhookUrl'    => 'https://example.org/hook',
				'webhookSecret' => 'secret',
			)
		);

		for ( $attempt = 1; $attempt <= Webhooks::MAX_ATTEMPTS; $attempt++ ) {
			wp_clear_scheduled_hook( Webhooks::CRON_HOOK, array( $entry_id ) );
			$this->webhooks->deliver( $entry_id );
			if ( $attempt < Webhooks::MAX_ATTEMPTS ) {
				$this->assertSame( 'retrying', get_post_meta( $entry_id, '_smartlogix_swiftforms_delivery_webhook', true ) );
				$this->assertGreaterThanOrEqual( time() + 50, wp_next_scheduled( Webhooks::CRON_HOOK, array( $entry_id ) ) );
			}
		}
		remove_filter( 'pre_http_request', $capture, 10 );

		$this->assertSame( 'failed', get_post_meta( $entry_id, '_smartlogix_swiftforms_delivery_webhook', true ) );
		$this->assertSame( '4', get_post_meta( $entry_id, '_smartlogix_swiftforms_delivery_webhook_attempts', true ) );
		$this->assertFalse( wp_next_scheduled( Webhooks::CRON_HOOK, array( $entry_id ) ) );
		$this->assertCount( 1, array_unique( $keys ) );
	}

	public function test_does_not_retry_a_terminal_http_error(): void {
		$form_id  = $this->create_form(
			'',
			array(
				'webhookUrl'    => 'https://example.org/hook',
				'webhookSecret' => 'secret',
			)
		);
		$entry_id = $this->entry( $form_id );
		$capture  = static fn() => self::response( 400 );
		add_filter( 'pre_http_request', $capture, 10, 3 );
		$this->webhooks->queue(
			$entry_id,
			$form_id,
			array(),
			array(
				'webhookUrl'    => 'https://example.org/hook',
				'webhookSecret' => 'secret',
			)
		);
		wp_clear_scheduled_hook( Webhooks::CRON_HOOK, array( $entry_id ) );
		$this->webhooks->deliver( $entry_id );
		remove_filter( 'pre_http_request', $capture, 10 );

		$this->assertSame( 'failed', get_post_meta( $entry_id, '_smartlogix_swiftforms_delivery_webhook', true ) );
		$this->assertSame( 'http_400', get_post_meta( $entry_id, '_smartlogix_swiftforms_delivery_webhook_error', true ) );
		$this->assertFalse( wp_next_scheduled( Webhooks::CRON_HOOK, array( $entry_id ) ) );
	}

	public function test_transport_log_records_only_a_redacted_error_code(): void {
		$form_id  = $this->create_form(
			'',
			array(
				'webhookUrl'    => 'https://example.org/hook',
				'webhookSecret' => 'secret',
			)
		);
		$entry_id = $this->entry( $form_id );
		$capture  = static fn() => new \WP_Error( 'http_request_failed', 'secret response body and credentials' );
		add_filter( 'pre_http_request', $capture, 10, 3 );
		$this->webhooks->queue(
			$entry_id,
			$form_id,
			array(),
			array(
				'webhookUrl'    => 'https://example.org/hook',
				'webhookSecret' => 'secret',
			)
		);
		wp_clear_scheduled_hook( Webhooks::CRON_HOOK, array( $entry_id ) );
		$this->webhooks->deliver( $entry_id );
		remove_filter( 'pre_http_request', $capture, 10 );

		$log = wp_json_encode( get_post_meta( $entry_id, '_smartlogix_swiftforms_delivery_webhook_log', true ) );
		$this->assertStringContainsString( 'http_request_failed', $log );
		$this->assertStringNotContainsString( 'credentials', $log );
		$this->assertStringNotContainsString( 'secret response', $log );
	}

	public function test_rejects_non_https_webhook_urls_without_a_request(): void {
		$requests = 0;
		$capture  = static function ( $preempt ) use ( &$requests ) {
			++$requests;
			return $preempt;
		};
		add_filter( 'pre_http_request', $capture, 10, 3 );
		$entry_id = $this->entry( $this->create_form() );
		$this->webhooks->queue(
			$entry_id,
			9,
			array(),
			array(
				'webhookUrl'    => 'http://example.org/hook',
				'webhookSecret' => 'secret',
			)
		);
		remove_filter( 'pre_http_request', $capture, 10 );

		$this->assertSame( 0, $requests );
		$this->assertSame( 'invalid_url', get_post_meta( $entry_id, '_smartlogix_swiftforms_delivery_webhook_error', true ) );
	}

	/** @return array<int, array{slug:string,type:string,value:mixed,attributes:array<string,mixed>}> */
	private function fields(): array {
		return array(
			array(
				'slug'       => 'email',
				'type'       => 'email',
				'value'      => 'person@example.com',
				'attributes' => array( 'label' => 'Email' ),
			),
		);
	}

	private function entry( int $form_id ): int {
		return ( new EntryRepository() )->create( $form_id, $this->fields() );
	}

	/** @return array<string, mixed> */
	private static function response( int $status ): array {
		return array(
			'body'     => '',
			'headers'  => array(),
			'response' => array(
				'code'    => $status,
				'message' => '',
			),
			'cookies'  => array(),
		);
	}
}
