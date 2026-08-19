<?php
/**
 * Math CAPTCHA challenge/response.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Submissions;

/**
 * The answer never reaches the browser: only `a`, `b`, and an HMAC token of
 * (answer|issued_at) are rendered. Submitting re-derives the expected token
 * from the posted answer and compares. Used by both Blocks\FormRenderer
 * (to render a challenge) and SpamGuard (to verify one), so the two can
 * never disagree about the token format.
 */
final class Captcha {

	private const TTL_SECONDS = 300;

	/**
	 * Builds a new challenge.
	 *
	 * @return array{a: int, b: int, token: string}
	 */
	public static function build(): array {
		$a = wp_rand( 2, 9 );
		$b = wp_rand( 2, 9 );

		return array(
			'a'     => $a,
			'b'     => $b,
			'token' => self::token_for( $a + $b, time() ),
		);
	}

	/**
	 * Verifies a submitted token against the submitted answer.
	 *
	 * @param string $token  The `captcha_token` field value.
	 * @param mixed  $answer The `captcha_answer` field value.
	 */
	public static function verify( string $token, $answer ): bool {
		if ( '' === $token || ! is_numeric( $answer ) ) {
			return false;
		}

		$issued_at_raw = explode( '.', $token, 2 )[0];
		$issued_at     = (int) $issued_at_raw;

		if ( get_transient( 'smartlogix_swiftforms_captcha_' . md5( $token ) ) ) {
			return false;
		}
		if ( $issued_at <= 0 || ( time() - $issued_at ) > self::TTL_SECONDS ) {
			return false;
		}

		$valid = hash_equals( self::token_for( (int) $answer, $issued_at ), $token );
		if ( $valid ) {
			set_transient( 'smartlogix_swiftforms_captcha_' . md5( $token ), 1, self::TTL_SECONDS );
		}

		return $valid;
	}

	/**
	 * Builds the `{issued_at}.{hmac}` token for a given answer.
	 */
	private static function token_for( int $answer, int $issued_at ): string {
		$signature = hash_hmac( 'sha256', "{$answer}|{$issued_at}", wp_salt( 'auth' ) );

		return "{$issued_at}.{$signature}";
	}
}
