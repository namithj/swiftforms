<?php
/**
 * Per-request state of the forms on a page.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Form_State
 *
 * What a form shows beyond its stored markup: the values a visitor submitted, the
 * errors that came back, a notice, and the success state. The no-JS submission
 * path (P4) fills it before the page renders, keyed by the effective formId and
 * the instance of the form on the page (1 for the first copy of that form, 2 for
 * the second, in render order). The renderers only read it.
 *
 * State shape (every key optional when setting, all present when reading):
 * - values: map field name => string, or list of strings for multi-value fields.
 * - errors: map field name => message (plain text; the renderer escapes it).
 * - success: bool. When true the form is replaced by its confirmation.
 * - confirmation_html: string. Ready-to-print HTML of the confirmation (P4 sets it after
 *   merge tags). Empty means "render the confirmation blocks, or the default message".
 * - notice: string. Plain text shown above the fields (for example "Please submit again").
 * - notice_type: "error" or "info".
 *
 * The state lives for one request. Nothing here is stored.
 */
class Form_State {

	/**
	 * States by "formId|instance".
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static $states = [];

	/**
	 * The state of every key with its empty value.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return [
			'values'            => [],
			'errors'            => [],
			'success'           => false,
			'confirmation_html' => '',
			'notice'            => '',
			'notice_type'       => 'error',
		];
	}

	/**
	 * Set the state of one form instance, replacing any earlier state.
	 *
	 * @param string               $form_id  Effective formId.
	 * @param array<string, mixed> $state    See the class description; unknown keys are ignored.
	 * @param int                  $instance Instance on the page, 1-based.
	 * @return void
	 */
	public static function set( $form_id, array $state, $instance = 1 ) {
		$clean = self::defaults();
		foreach ( $clean as $key => $default ) {
			if ( array_key_exists( $key, $state ) && gettype( $state[ $key ] ) === gettype( $default ) ) {
				$clean[ $key ] = $state[ $key ];
			}
		}
		self::$states[ self::key( $form_id, $instance ) ] = $clean;
	}

	/**
	 * The state of one form instance.
	 *
	 * @param string $form_id  Effective formId.
	 * @param int    $instance Instance on the page, 1-based.
	 * @return array<string, mixed> Always every key.
	 */
	public static function get( $form_id, $instance = 1 ) {
		$key = self::key( $form_id, $instance );
		return isset( self::$states[ $key ] ) ? self::$states[ $key ] : self::defaults();
	}

	/**
	 * Forget every state (tests, and code that renders a page twice).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$states = [];
	}

	/**
	 * Storage key.
	 *
	 * @param string $form_id  Effective formId.
	 * @param int    $instance Instance.
	 * @return string
	 */
	private static function key( $form_id, $instance ) {
		return (string) $form_id . '|' . max( 1, (int) $instance );
	}
}
