<?php
/**
 * Per-form webhook delivery.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Notifications;

/**
 * Fires a JSON POST to a form's configured webhook URL on every new entry.
 * Non-blocking, short timeout — a slow or dead endpoint never delays the
 * visitor's response.
 */
final class Webhooks {

	/**
	 * @param int                                                            $entry_id      New entry post id.
	 * @param int                                                            $form_id       Source form post id.
	 * @param array<int, array{slug: string, type: string, value: mixed, attributes: array<string, mixed>}> $fields Schema-enforced fields.
	 * @param array<string, mixed>                                          $form_settings Resolved `_smartlogix_swiftforms_settings`.
	 */
	public function send( int $entry_id, int $form_id, array $fields, array $form_settings ): void {
		$url = trim( (string) ( $form_settings['webhookUrl'] ?? '' ) );

		if ( '' === $url || 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) || ! wp_http_validate_url( $url ) ) {
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
		 * Filters the JSON payload sent to a form's webhook URL.
		 *
		 * @param array<string, mixed> $payload  Payload about to be sent.
		 * @param int                  $entry_id Entry post id.
		 */
		$payload = (array) apply_filters( 'smartlogix_swiftforms_webhook_payload', $payload, $entry_id );

		wp_safe_remote_post(
			$url,
			array(
				'timeout'            => 5,
				'blocking'           => false,
				'redirection'        => 0,
				'reject_unsafe_urls' => true,
				'headers'            => array( 'Content-Type' => 'application/json' ),
				'body'               => wp_json_encode( $payload ),
			)
		);
	}
}
