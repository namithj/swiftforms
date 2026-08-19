<?php
/**
 * Render-timestamp time trap: rejects submissions completed too fast to be human.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Submissions;

/**
 * A hidden field carries `{issued_at}.{hmac}`, stamped when the form is
 * rendered. `verify()` only enforces a *minimum* elapsed time — there is no
 * expiry, since a slow human filling out a long form is not spam.
 */
final class TimeTrap {

	/**
	 * Builds the render-timestamp field value.
	 */
	public static function build(): string {
		$issued_at = time();
		$signature = self::sign( $issued_at );

		return "{$issued_at}.{$signature}";
	}

	/**
	 * Verifies a submitted render-timestamp token against the minimum
	 * allowed submit time. A missing/malformed token fails open (passes) —
	 * it only ever tightens spam defenses, never blocks a legitimate
	 * submission on its own.
	 *
	 * @param string $token           The `render_ts` field value.
	 * @param int    $min_seconds     Minimum seconds that must have elapsed.
	 */
	public static function verify( string $token, int $min_seconds ): bool {
		if ( '' === $token || ! str_contains( $token, '.' ) ) {
			return true;
		}

		[ $issued_at_raw, $signature ] = explode( '.', $token, 2 );
		$issued_at                     = (int) $issued_at_raw;

		if ( $issued_at <= 0 || ! hash_equals( self::sign( $issued_at ), $signature ) ) {
			return true;
		}

		return ( time() - $issued_at ) >= $min_seconds;
	}

	private static function sign( int $issued_at ): string {
		return hash_hmac( 'sha256', "rendered_at|{$issued_at}", wp_salt( 'auth' ) );
	}
}
