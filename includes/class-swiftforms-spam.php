<?php
/**
 * Akismet integration for submission spam checks.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

/**
 * Checks submissions against Akismet when the plugin is installed and keyed.
 *
 * Spam matches are stored with `_sf_spam` meta and skipped for notifications
 * instead of being rejected, so a false positive is reviewable in the admin
 * list rather than silently lost.
 */
class SwiftForms_Spam {

	/**
	 * Reports whether the Akismet plugin is active and configured with a key.
	 */
	public static function is_akismet_active(): bool {
		return class_exists( 'Akismet' ) && is_callable( array( 'Akismet', 'get_api_key' ) ) && '' !== (string) Akismet::get_api_key();
	}

	/**
	 * Maps a submission request onto Akismet's comment-check payload.
	 *
	 * The first text-type field doubles as the author name and the first
	 * email field as the author email; every scalar value lands in the
	 * comment content so keyword-based detection sees the whole submission.
	 *
	 * @param array<string, mixed> $request Submission payload.
	 *
	 * @return array<string, string>
	 */
	public static function build_comment_check_payload( array $request ): array {
		$fields  = is_array( $request['fields'] ?? null ) ? $request['fields'] : array();
		$author  = '';
		$email   = '';
		$content = array();

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$type  = isset( $field['type'] ) ? (string) $field['type'] : '';
			$value = $field['value'] ?? '';

			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$value = (string) $value;

			if ( '' === $author && 'text' === $type && '' !== $value ) {
				$author = $value;
			}

			if ( '' === $email && 'email' === $type && '' !== $value ) {
				$email = $value;
			}

			if ( '' !== $value ) {
				$content[] = $value;
			}
		}

		return array(
			'blog'                 => (string) home_url(),
			'comment_author'       => $author,
			'comment_author_email' => $email,
			'comment_content'      => implode( "\n", $content ),
			'comment_type'         => 'contact-form',
			'referrer'             => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '',
			'user_agent'           => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
			'user_ip'              => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
		);
	}

	/**
	 * Runs the submission through Akismet's comment-check endpoint.
	 *
	 * The result passes through `swiftforms_akismet_result` so tests (and
	 * sites with custom heuristics) can decide without a network call.
	 *
	 * @param array<string, mixed> $request Submission payload.
	 *
	 * @return bool True when Akismet classifies the submission as spam.
	 */
	public static function check( array $request ): bool {
		$is_spam = false;

		if ( self::is_akismet_active() && is_callable( array( 'Akismet', 'http_post' ) ) ) {
			$response = Akismet::http_post( http_build_query( self::build_comment_check_payload( $request ) ), 'comment-check' );
			$is_spam  = isset( $response[1] ) && 'true' === trim( (string) $response[1] );
		}

		return (bool) apply_filters( 'swiftforms_akismet_result', $is_spam, $request );
	}
}
