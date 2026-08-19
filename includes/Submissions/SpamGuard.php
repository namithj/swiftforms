<?php
/**
 * Layered spam defenses: honeypot, time trap, CAPTCHA, Turnstile, Akismet.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Submissions;

use SwiftForms\Settings\GlobalSettings;

/**
 * Returns one of three verdicts:
 *  - `pass`           — clean, proceed normally.
 *  - `silent_reject`  — looks like a bot (honeypot/time-trap); respond as if
 *                        it succeeded so the bot doesn't learn anything, but
 *                        never store or notify.
 *  - `hard_reject`    — a human-facing check failed (CAPTCHA/Turnstile); show
 *                        a validation error so a real visitor can retry.
 *  - `soft_flag`      — Akismet thinks it's spam; still store (flagged), but
 *                        skip notifications.
 */
final class SpamGuard {

	/**
	 * @param array<string, mixed> $request      Normalized submission payload.
	 * @param array<string, mixed> $form_settings Resolved `_smartlogix_swiftforms_settings` for this form.
	 * @return array{status: string, code?: string, message?: string}
	 */
	public function evaluate( array $request, array $form_settings ): array {
		if ( '' !== trim( (string) ( $request['honeypot'] ?? '' ) ) ) {
			return array( 'status' => 'silent_reject' );
		}

		$min_seconds = (int) apply_filters( 'smartlogix_swiftforms_min_submit_seconds', GlobalSettings::instance()->get( 'minSubmitSeconds', 3 ) );

		if ( ! TimeTrap::verify( (string) ( $request['render_ts'] ?? '' ), $min_seconds ) ) {
			return array( 'status' => 'silent_reject' );
		}

		if ( ! empty( $form_settings['enableCaptcha'] ) ) {
			$token  = (string) ( $request['captcha_token'] ?? '' );
			$answer = $request['captcha_answer'] ?? null;

			if ( ! Captcha::verify( $token, $answer ) ) {
				return array(
					'status'  => 'hard_reject',
					'code'    => 'invalid_captcha',
					'message' => __( 'That answer was not correct. Please try again.', 'swiftforms' ),
				);
			}
		}

		if ( ! empty( $form_settings['enableTurnstile'] ) ) {
			$verdict = $this->verify_turnstile( (string) ( $request['cf_turnstile_response'] ?? '' ) );

			if ( ! $verdict ) {
				return array(
					'status'  => 'hard_reject',
					'code'    => 'invalid_captcha',
					'message' => __( 'Verification failed. Please try again.', 'swiftforms' ),
				);
			}
		}

		if ( GlobalSettings::instance()->get( 'akismetEnabled', false ) && $this->is_akismet_active() && $this->akismet_flags_as_spam( $request ) ) {
			return array( 'status' => 'soft_flag' );
		}

		return array( 'status' => 'pass' );
	}

	/**
	 * Verifies a Cloudflare Turnstile response server-side.
	 */
	private function verify_turnstile( string $response_token ): bool {
		$secret   = (string) GlobalSettings::instance()->get( 'turnstileSecretKey', '' );
		$site_key = (string) GlobalSettings::instance()->get( 'turnstileSiteKey', '' );

		if ( '' === $secret || '' === $site_key ) {
			return false;
		}

		if ( '' === $response_token ) {
			return false;
		}

		$result = wp_remote_post(
			'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			array(
				'timeout' => 5,
				'body'    => array(
					'secret'   => $secret,
					'response' => $response_token,
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			return false;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $result ), true );

		/**
		 * Filters the decoded Turnstile verification response.
		 *
		 * @param array<string, mixed> $body    Decoded JSON body.
		 * @param array<string, mixed> $request Unused here; kept for parity with other spam filters.
		 */
		$body = apply_filters( 'smartlogix_swiftforms_turnstile_verify_response', is_array( $body ) ? $body : array(), array() );

		return ! empty( $body['success'] );
	}

	/**
	 * Whether the Akismet plugin is active and configured.
	 */
	private function is_akismet_active(): bool {
		return (bool) apply_filters( 'smartlogix_swiftforms_akismet_active', class_exists( '\Akismet' ) && '' !== (string) get_option( 'wordpress_api_key' ) );
	}

	/**
	 * Runs the submission through Akismet's comment-check API.
	 *
	 * @param array<string, mixed> $request Normalized submission payload.
	 */
	private function akismet_flags_as_spam( array $request ): bool {
		$fields = (array) ( $request['fields'] ?? array() );

		$author  = '';
		$email   = '';
		$content = array();

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || 'hidden' === ( $field['type'] ?? '' ) ) {
				continue;
			}

			$value = is_array( $field['value'] ?? null ) ? '' : (string) ( $field['value'] ?? '' );

			if ( '' === $author && 'text' === ( $field['type'] ?? '' ) ) {
				$author = $value;
			}

			if ( '' === $email && 'email' === ( $field['type'] ?? '' ) ) {
				$email = $value;
			}

			if ( '' !== $value ) {
				$content[] = $value;
			}
		}

		$payload = array(
			'comment_type'         => 'contact-form',
			'comment_author'       => $author,
			'comment_author_email' => $email,
			'comment_content'      => implode( "\n\n", $content ),
			'user_ip'              => RateLimiter::client_ip(),
			'user_agent'           => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
		);

		$is_spam = false;

		if ( method_exists( '\Akismet', 'http_post' ) ) {
			$response = \Akismet::http_post( \Akismet::build_query( $payload ), 'comment-check' );
			$is_spam  = isset( $response[1] ) && 'true' === trim( (string) $response[1] );
		}

		/**
		 * Filters the final Akismet spam determination.
		 *
		 * @param bool                  $is_spam Whether Akismet flagged this as spam.
		 * @param array<string, mixed>  $request Normalized submission payload.
		 */
		return (bool) apply_filters( 'smartlogix_swiftforms_akismet_result', $is_spam, $request );
	}
}
