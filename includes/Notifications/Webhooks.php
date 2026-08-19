<?php
/**
 * Reliable per-form webhook delivery.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Notifications;

use SwiftForms\PostTypes;
use SwiftForms\Registrable;
use SwiftForms\Settings\FormSettings;

/** Queues signed webhook requests and retries transient failures. */
final class Webhooks implements Registrable {

	public const CRON_HOOK    = 'smartlogix_swiftforms_deliver_webhook';
	public const MAX_ATTEMPTS = 4;

	private const META_PREFIX = '_smartlogix_swiftforms_delivery_webhook';

	public function register(): void {
		add_action( self::CRON_HOOK, array( $this, 'deliver' ) );
		add_action( 'admin_post_smartlogix_swiftforms_retry_webhook', array( $this, 'retry' ) );
	}

	/**
	 * @param int                                                            $entry_id      New entry post id.
	 * @param int                                                            $form_id       Source form post id.
	 * @param array<int, array{slug: string, type: string, value: mixed, attributes: array<string, mixed>}> $fields Schema-enforced fields.
	 * @param array<string, mixed>                                          $form_settings Resolved form settings.
	 */
	public function queue( int $entry_id, int $form_id, array $fields, array $form_settings ): void {
		$url = trim( (string) ( $form_settings['webhookUrl'] ?? '' ) );
		if ( '' === $url ) {
			if ( $entry_id > 0 ) {
				update_post_meta( $entry_id, self::META_PREFIX, 'not_configured' );
			}
			return;
		}

		if ( ! $this->valid_url( $url ) ) {
			if ( $entry_id > 0 ) {
				$this->finish( $entry_id, 'failed', 'invalid_url' );
			}
			return;
		}

		$payload = array(
			'entry_id'     => $entry_id,
			'form_id'      => $form_id,
			'submitted_at' => current_time( 'mysql', true ),
			'fields'       => array_reduce(
				$fields,
				static function ( array $carry, array $field ): array {
					$carry[ $field['slug'] ] = is_array( $field['value'] ) ? ( $field['value']['name'] ?? '' ) : $field['value'];
					return $carry;
				},
				array()
			),
		);

		/**
		 * Filters the immutable JSON payload queued for a form's webhook URL.
		 *
		 * @param array<string, mixed> $payload  Payload about to be queued.
		 * @param int                  $entry_id Entry post id.
		 */
		$payload = (array) apply_filters( 'smartlogix_swiftforms_webhook_payload', $payload, $entry_id );

		if ( $entry_id <= 0 ) {
			$this->request( $url, (string) ( $form_settings['webhookSecret'] ?? '' ), $payload, 'unsaved-' . wp_generate_uuid4(), 1, false );
			return;
		}

		update_post_meta( $entry_id, self::META_PREFIX . '_payload', $payload );
		update_post_meta( $entry_id, self::META_PREFIX . '_url', $url );
		update_post_meta( $entry_id, self::META_PREFIX . '_form_id', $form_id );
		update_post_meta( $entry_id, self::META_PREFIX . '_attempts', 0 );
		update_post_meta( $entry_id, self::META_PREFIX . '_log', array() );
		delete_post_meta( $entry_id, self::META_PREFIX . '_error' );
		update_post_meta( $entry_id, self::META_PREFIX, 'queued' );
		$this->schedule( $entry_id, time() + 1 );
	}

	/** Deliver one queued attempt. */
	public function deliver( int $entry_id ): void {
		if ( PostTypes::ENTRY_POST_TYPE !== get_post_type( $entry_id ) ) {
			return;
		}

		$payload = get_post_meta( $entry_id, self::META_PREFIX . '_payload', true );
		$url     = (string) get_post_meta( $entry_id, self::META_PREFIX . '_url', true );
		$form_id = (int) get_post_meta( $entry_id, self::META_PREFIX . '_form_id', true );
		$attempt = (int) get_post_meta( $entry_id, self::META_PREFIX . '_attempts', true ) + 1;
		update_post_meta( $entry_id, self::META_PREFIX . '_attempts', $attempt );

		if ( ! is_array( $payload ) || ! $this->valid_url( $url ) ) {
			$this->finish( $entry_id, 'failed', 'invalid_delivery' );
			return;
		}

		$secret = (string) ( FormSettings::get( $form_id )['webhookSecret'] ?? '' );
		if ( '' === $secret ) {
			$this->finish( $entry_id, 'failed', 'missing_secret' );
			return;
		}

		$result = $this->request( $url, $secret, $payload, 'entry-' . $entry_id, $attempt, true );
		if ( $result['success'] ) {
			$this->finish( $entry_id, 'sent', '' );
			return;
		}

		$this->log( $entry_id, $attempt, $result['code'] );
		update_post_meta( $entry_id, self::META_PREFIX . '_error', $result['code'] );
		if ( $result['retryable'] && $attempt < self::MAX_ATTEMPTS ) {
			update_post_meta( $entry_id, self::META_PREFIX, 'retrying' );
			$this->schedule( $entry_id, time() + $this->retry_delay( $attempt ) );
			return;
		}

		update_post_meta( $entry_id, self::META_PREFIX, 'failed' );
	}

	/** Protected operator-triggered retry. */
	public function retry(): void {
		$entry_id = isset( $_GET['entry_id'] ) ? absint( $_GET['entry_id'] ) : 0;
		if ( ! $entry_id || PostTypes::ENTRY_POST_TYPE !== get_post_type( $entry_id ) || ! current_user_can( 'edit_post', $entry_id ) ) {
			wp_die( esc_html__( 'You are not allowed to retry this delivery.', 'swiftforms' ), 403 );
		}
		check_admin_referer( 'smartlogix_swiftforms_retry_webhook_' . $entry_id );

		if ( ! is_array( get_post_meta( $entry_id, self::META_PREFIX . '_payload', true ) ) ) {
			wp_die( esc_html__( 'This entry has no stored webhook delivery.', 'swiftforms' ), 400 );
		}
		wp_clear_scheduled_hook( self::CRON_HOOK, array( $entry_id ) );
		update_post_meta( $entry_id, self::META_PREFIX . '_attempts', 0 );
		delete_post_meta( $entry_id, self::META_PREFIX . '_error' );
		update_post_meta( $entry_id, self::META_PREFIX, 'queued' );
		$this->schedule( $entry_id, time() + 1 );
		wp_safe_redirect( get_edit_post_link( $entry_id, 'raw' ) );
		exit;
	}

	public static function retry_url( int $entry_id ): string {
		if ( ! current_user_can( 'edit_post', $entry_id ) ) {
			return '';
		}

		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'smartlogix_swiftforms_retry_webhook',
					'entry_id' => $entry_id,
				),
				admin_url( 'admin-post.php' )
			),
			'smartlogix_swiftforms_retry_webhook_' . $entry_id
		);
	}

	/** @return array{success: bool, retryable: bool, code: string} */
	private function request( string $url, string $secret, array $payload, string $delivery_id, int $attempt, bool $blocking ): array {
		if ( '' === $secret ) {
			return array(
				'success'   => false,
				'retryable' => false,
				'code'      => 'missing_secret',
			);
		}

		$body      = (string) wp_json_encode( $payload );
		$timestamp = (string) time();
		$response  = wp_safe_remote_post(
			$url,
			array(
				'timeout'            => 5,
				'blocking'           => $blocking,
				'redirection'        => 0,
				'reject_unsafe_urls' => true,
				'headers'            => array(
					'Content-Type'                  => 'application/json',
					'X-SwiftForms-Timestamp'        => $timestamp,
					'X-SwiftForms-Signature'        => 'v1=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret ),
					'X-SwiftForms-Idempotency-Key'  => $delivery_id,
					'X-SwiftForms-Delivery-Attempt' => (string) $attempt,
				),
				'body'               => $body,
			)
		);

		if ( ! $blocking ) {
			return array(
				'success'   => ! is_wp_error( $response ),
				'retryable' => false,
				'code'      => is_wp_error( $response ) ? sanitize_key( $response->get_error_code() ) : '',
			);
		}
		if ( is_wp_error( $response ) ) {
			$error_code = sanitize_key( $response->get_error_code() );
			return array(
				'success'   => false,
				'retryable' => true,
				'code'      => '' !== $error_code ? $error_code : 'request_failed',
			);
		}

		$status = wp_remote_retrieve_response_code( $response );
		return array(
			'success'   => $status >= 200 && $status < 300,
			'retryable' => 408 === $status || 429 === $status || $status >= 500,
			'code'      => $status >= 200 && $status < 300 ? '' : 'http_' . $status,
		);
	}

	private function valid_url( string $url ): bool {
		return 'https' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) && (bool) wp_http_validate_url( $url );
	}

	private function schedule( int $entry_id, int $timestamp ): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK, array( $entry_id ) ) ) {
			wp_schedule_single_event( $timestamp, self::CRON_HOOK, array( $entry_id ) );
		}
	}

	private function retry_delay( int $attempt ): int {
		return 60 * ( 5 ** ( $attempt - 1 ) );
	}

	private function finish( int $entry_id, string $status, string $code ): void {
		update_post_meta( $entry_id, self::META_PREFIX, $status );
		if ( '' === $code ) {
			delete_post_meta( $entry_id, self::META_PREFIX . '_error' );
		} else {
			update_post_meta( $entry_id, self::META_PREFIX . '_error', $code );
		}
		$this->log( $entry_id, (int) get_post_meta( $entry_id, self::META_PREFIX . '_attempts', true ), '' !== $code ? $code : 'sent' );
	}

	private function log( int $entry_id, int $attempt, string $code ): void {
		$log   = (array) get_post_meta( $entry_id, self::META_PREFIX . '_log', true );
		$log[] = array(
			'attempt' => $attempt,
			'time'    => current_time( 'mysql', true ),
			'code'    => sanitize_key( $code ),
		);
		update_post_meta( $entry_id, self::META_PREFIX . '_log', array_slice( $log, -10 ) );
	}
}
