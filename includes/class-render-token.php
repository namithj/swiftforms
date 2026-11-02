<?php
/**
 * Render token.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Render_Token
 *
 * The signed token a rendered form carries (`_sf_token`, C-3.7):
 *
 *   v1.{issued}.{mac}
 *
 * - issued: the issue time in whole hours since the Unix epoch, base 36.
 * - mac:    the first 32 hex characters of HMAC-SHA256( wp_salt( 'auth' ) . 'swiftforms',
 *           "v1|{formId}|{source}|{schema_hash}|{issued}" ).
 *
 * It is an integrity token, not an anti-replay control and not proof of a human: it says "this
 * server rendered this form, in this source, with this field list, at about this time". It is valid
 * for 7 days (168 hours, counted in whole hours), which is longer than common page-cache lifetimes.
 * Replay inside that time is bounded by the limiters and the duplicate guard.
 *
 * A future format gets a new prefix and a new entry in FORMATS; the old entry stays so pages cached
 * with the old format keep working (C-3.7).
 */
class Render_Token {

	/**
	 * Hours a token stays valid.
	 */
	const VALID_HOURS = 168;

	/**
	 * Format prefix => name of the method that computes its MAC. Entries are never removed (C-3.7).
	 */
	const FORMATS = [
		'v1' => 'mac_v1',
	];

	/**
	 * The format new tokens are issued in.
	 */
	const CURRENT = 'v1';

	/**
	 * How many older snapshots of a form are tried (Submission passes the hashes).
	 */
	const MAX_CANDIDATES = 26;

	/**
	 * Issue a token for a form as it is rendered now.
	 *
	 * @param string $form_id     Effective formId.
	 * @param string $source      Source reference.
	 * @param string $schema_hash Hash of the form's field list.
	 * @return string
	 */
	public static function issue( $form_id, $source, $schema_hash ) {
		$issued = base_convert( (string) self::hours( Clock::now() ), 10, 36 );
		$method = self::FORMATS[ self::CURRENT ];
		return self::CURRENT . '.' . $issued . '.' . self::$method( $form_id, $source, $schema_hash, $issued );
	}

	/**
	 * Check a token.
	 *
	 * @param mixed    $token             The posted token.
	 * @param string   $form_id           Effective formId.
	 * @param string   $source            Source reference.
	 * @param string[] $candidate_hashes  Schema hashes the token may name: the current one first, then the form's older snapshots, newest first.
	 * @return array{state:string, schema_hash:string} state: valid, expired (a genuine token past its time), forged (the MAC matches nothing) or malformed (not a token at all). schema_hash is the matched hash for valid and expired, otherwise an empty string.
	 */
	public static function verify( $token, $form_id, $source, array $candidate_hashes ) {
		$result = [
			'state'       => 'malformed',
			'schema_hash' => '',
		];

		if ( ! is_string( $token ) || strlen( $token ) > 64 || 1 !== preg_match( '/^([a-z][a-z0-9]{0,7})\.([0-9a-z]{1,8})\.([0-9a-f]{32})$/D', $token, $m ) ) {
			return $result;
		}
		if ( ! isset( self::FORMATS[ $m[1] ] ) ) {
			return $result;
		}

		$method          = self::FORMATS[ $m[1] ];
		$issued          = $m[2];
		$result['state'] = 'forged';

		$matched = '';
		foreach ( array_slice( array_values( array_unique( $candidate_hashes ) ), 0, self::MAX_CANDIDATES ) as $hash ) {
			if ( is_string( $hash ) && hash_equals( self::$method( $form_id, $source, $hash, $issued ), $m[3] ) ) {
				$matched = $hash;
				break;
			}
		}
		if ( '' === $matched ) {
			return $result;
		}

		$issued_hours = (int) base_convert( $issued, 36, 10 );
		$now_hours    = self::hours( Clock::now() );

		// A MAC we computed but a time in the future (more than the hour of clock slack): not something we issued.
		if ( $issued_hours > $now_hours + 1 ) {
			return $result;
		}

		$result['schema_hash'] = $matched;
		$result['state']       = ( $now_hours - $issued_hours ) > self::VALID_HOURS ? 'expired' : 'valid';
		return $result;
	}

	/**
	 * Whole hours since the epoch.
	 *
	 * @param int $time Unix time.
	 * @return int
	 */
	private static function hours( $time ) {
		return intdiv( (int) $time, HOUR_IN_SECONDS );
	}

	/**
	 * MAC of the v1 format.
	 *
	 * @param string $form_id     Effective formId.
	 * @param string $source      Source reference.
	 * @param string $schema_hash Schema hash.
	 * @param string $issued      Issue hours, base 36.
	 * @return string 32 hex characters.
	 */
	private static function mac_v1( $form_id, $source, $schema_hash, $issued ) {
		return substr( hash_hmac( 'sha256', 'v1|' . $form_id . '|' . $source . '|' . $schema_hash . '|' . $issued, self::key() ), 0, 32 );
	}

	/**
	 * The signing key, shared with Time_Trap.
	 *
	 * @return string
	 */
	public static function key() {
		return wp_salt( 'auth' ) . 'swiftforms';
	}
}
