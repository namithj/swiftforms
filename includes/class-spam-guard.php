<?php
/**
 * Spam guard.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Spam_Guard
 *
 * The invisible checks, in this order, when the form's spamProtection is "default":
 *
 * 1. honeypot (spam.honeypot): the hidden `_sf_hp` input must be empty.
 * 2. time trap (spam.min_seconds): `_sf_t` must show at least that many seconds (Time_Trap).
 * 3. render token: a token that does not verify (forged or malformed) is spam. This rule runs even when
 *    spamProtection is "off": a forged token is never a clean submission (C-3.4).
 * 4. JS token (spam.js_token): a REST submission must carry `_sf_t` in its `js.{elapsed ms}` form, which
 *    only the view module writes; a form post without JavaScript carries the signed render time instead
 *    (C-3.1).
 * 5. blocked words (spam.blocked_words): a literal, case-insensitive substring match over every visitor
 *    value. Never a regular expression.
 * 6. the swiftforms_spam_check filter.
 *
 * "off" skips 1, 2, 4, 5 and 6. The result is a verdict with a machine reason that only goes to the
 * debug log; the visitor gets the same success answer either way.
 */
class Spam_Guard {

	/**
	 * Most blocked words considered.
	 */
	const MAX_WORDS = 500;

	/**
	 * Check a submission.
	 *
	 * @param Submission_Context $ctx      Context with values, transport and both schemas set.
	 * @param array<string, mixed> $controls Posted control fields: "hp" (`_sf_hp`) and "t" (`_sf_t`).
	 * @param array<string, string> $token   Result of Render_Token::verify().
	 * @return array{spam:bool, reason:string}
	 */
	public static function check( Submission_Context $ctx, array $controls, array $token ) {
		$protected = 'off' !== ( $ctx->form->attributes['spamProtection'] ?? 'default' );
		$t         = Time_Trap::parse( $controls['t'] ?? null, $ctx->form->form_id, $ctx->source );
		$min       = (int) Settings::get_value( 'spam.min_seconds', 3 );

		if ( $protected && true === Settings::get_value( 'spam.honeypot', true ) ) {
			$hp = $controls['hp'] ?? '';
			if ( is_array( $hp ) || ( is_string( $hp ) && '' !== $hp ) || ( ! is_string( $hp ) && ! empty( $hp ) ) ) {
				return self::spam( 'honeypot' );
			}
		}

		if ( $protected && $min > 0 ) {
			$reason = self::time_reason( $t, $ctx->transport, $min );
			if ( null !== $reason ) {
				return self::spam( $reason );
			}
		}

		if ( ! in_array( $token['state'] ?? 'malformed', [ 'valid', 'expired' ], true ) ) {
			return self::spam( 'token_forged' );
		}

		if ( $protected && true === Settings::get_value( 'spam.js_token', true ) && 'rest' === $ctx->transport && 'js' !== $t['format'] ) {
			return self::spam( 'js_token' );
		}

		if ( ! $protected ) {
			return [
				'spam'   => false,
				'reason' => '',
			];
		}

		if ( self::has_blocked_word( $ctx ) ) {
			return self::spam( 'blocked_word' );
		}

		/**
		 * Filters the spam verdict of a submission.
		 *
		 * Runs last, only for forms with spam protection on, and only for submissions SwiftForms has
		 * not already judged to be spam. Return true to mark the submission as spam, or a non-empty
		 * string to mark it as spam with that reason (the reason goes to the debug log, never to the
		 * visitor). Return false to leave it clean. A spam submission is stored with status "spam" or
		 * discarded (spam.spam_action) and the visitor still sees the normal confirmation.
		 *
		 * @since 1.0.0
		 *
		 * @param bool|string        $verdict False for clean, true or a reason string for spam.
		 * @param Submission_Context $context The submission. Values are plain text; $context->is_spam is not set yet.
		 */
		$verdict = apply_filters( 'swiftforms_spam_check', false, $ctx );
		if ( true === $verdict ) {
			return self::spam( 'filter' );
		}
		if ( is_string( $verdict ) && '' !== $verdict ) {
			return self::spam( 'filter:' . substr( preg_replace( '/[^A-Za-z0-9_.:-]/', '', $verdict ), 0, 40 ) );
		}

		return [
			'spam'   => false,
			'reason' => '',
		];
	}

	/**
	 * Why the time control fails, or null when it passes.
	 *
	 * @param array{format:string, seconds:float} $t         Parsed `_sf_t`.
	 * @param string                              $transport rest or nojs.
	 * @param int                                 $min       Minimum seconds.
	 * @return string|null
	 */
	private static function time_reason( array $t, $transport, $min ) {
		if ( 'forged' === $t['format'] ) {
			return 'time_forged';
		}
		if ( 'missing' === $t['format'] ) {
			return 'time_missing';
		}
		// A form post without JavaScript can only carry the signed render time; "js." from it is a forgery attempt.
		if ( 'nojs' === $transport && 'signed' !== $t['format'] ) {
			return 'time_forged';
		}
		return $t['seconds'] < $min ? 'too_fast' : null;
	}

	/**
	 * Whether any visitor value contains a blocked word.
	 *
	 * @param Submission_Context $ctx Context.
	 * @return bool
	 */
	private static function has_blocked_word( Submission_Context $ctx ) {
		$list = (string) Settings::get_value( 'spam.blocked_words', '' );
		if ( '' === trim( $list ) ) {
			return false;
		}

		$words = [];
		$parts = preg_split( '/[\r\n,]+/', $list );
		foreach ( false === $parts ? [] : $parts as $word ) {
			$word = trim( $word );
			if ( '' !== $word ) {
				$words[] = $word;
			}
		}
		$words = array_slice( array_unique( $words ), 0, self::MAX_WORDS );
		if ( [] === $words ) {
			return false;
		}

		$hidden = [];
		foreach ( $ctx->validation_schema->fields as $field ) {
			if ( 'hidden' === $field['type'] ) {
				$hidden[ $field['name'] ] = true;
			}
		}

		foreach ( $ctx->values as $name => $value ) {
			if ( isset( $hidden[ $name ] ) ) {
				continue;
			}
			foreach ( (array) $value as $text ) {
				foreach ( $words as $word ) {
					if ( self::contains( (string) $text, $word ) ) {
						return true;
					}
				}
			}
		}
		return false;
	}

	/**
	 * Literal, case-insensitive substring test.
	 *
	 * @param string $haystack Text.
	 * @param string $needle   Word.
	 * @return bool
	 */
	private static function contains( $haystack, $needle ) {
		if ( function_exists( 'mb_stripos' ) ) {
			return false !== mb_stripos( $haystack, $needle, 0, 'UTF-8' );
		}
		return false !== stripos( $haystack, $needle );
	}

	/**
	 * A spam verdict.
	 *
	 * @param string $reason Machine reason.
	 * @return array{spam:bool, reason:string}
	 */
	private static function spam( $reason ) {
		return [
			'spam'   => true,
			'reason' => $reason,
		];
	}
}
