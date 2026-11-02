<?php
/**
 * Time trap.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Time_Trap
 *
 * The `_sf_t` control: how long the visitor took. A bot posts instantly.
 *
 * Two formats:
 * - Signed render time, `{unix}.{mac}`: written into the HTML on the server. mac = the first 16 hex
 *   characters of HMAC-SHA256( key, "t|{formId}|{source}|{unix}" ). The no-JS path posts it back
 *   unchanged; the time that passed since the render is the time the visitor took (and a forged value
 *   fails the signature).
 * - `js.{elapsed ms}`: the view module (P6) replaces the signed value with the time measured in the
 *   browser since hydration. A page cached for days still shows a plausible time this way. It is also
 *   the JS token (spam.js_token): a REST submission must carry this form.
 *
 * Nothing here is proof of a human: both formats can be faked by a script that reads this code. They
 * only stop the laziest bots.
 */
class Time_Trap {

	/**
	 * Prefix of the browser-measured format.
	 */
	const JS_PREFIX = 'js.';

	/**
	 * A signed render time for a form, as of now.
	 *
	 * @param string $form_id Effective formId.
	 * @param string $source  Source reference.
	 * @return string
	 */
	public static function issue( $form_id, $source ) {
		$now = Clock::now();
		return $now . '.' . self::mac( $form_id, $source, $now );
	}

	/**
	 * Parse a posted `_sf_t`.
	 *
	 * @param mixed  $value   Posted value.
	 * @param string $form_id Effective formId.
	 * @param string $source  Source reference.
	 * @return array{format:string, seconds:float} format: "js" (browser-measured), "signed" (valid signature), "forged" (signature mismatch) or "missing" (anything else, including an empty value). seconds is the elapsed time for "js" and "signed", otherwise 0.
	 */
	public static function parse( $value, $form_id, $source ) {
		$none = [
			'format'  => 'missing',
			'seconds' => 0.0,
		];
		if ( ! is_string( $value ) || strlen( $value ) > 40 ) {
			return $none;
		}

		if ( 1 === preg_match( '/^js\.([0-9]{1,12})$/D', $value, $m ) ) {
			return [
				'format'  => 'js',
				'seconds' => (float) $m[1] / 1000,
			];
		}

		if ( 1 === preg_match( '/^([0-9]{1,12})\.([0-9a-f]{16})$/D', $value, $m ) ) {
			if ( ! hash_equals( self::mac( $form_id, $source, $m[1] ), $m[2] ) ) {
				return [
					'format'  => 'forged',
					'seconds' => 0.0,
				];
			}
			return [
				'format'  => 'signed',
				'seconds' => (float) max( 0, Clock::now() - (int) $m[1] ),
			];
		}

		return $none;
	}

	/**
	 * MAC of a signed render time.
	 *
	 * @param string     $form_id Effective formId.
	 * @param string     $source  Source reference.
	 * @param int|string $unix    Unix time.
	 * @return string 16 hex characters.
	 */
	private static function mac( $form_id, $source, $unix ) {
		return substr( hash_hmac( 'sha256', 't|' . $form_id . '|' . $source . '|' . $unix, Render_Token::key() ), 0, 16 );
	}
}
