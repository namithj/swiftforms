<?php
/**
 * Control fields of a rendered form.
 *
 * @package BlockForms
 */

namespace BlockForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Form_Controls
 *
 * The inputs every form carries besides its fields (C-3.1): the effective formId
 * `_bf_form`, the source `_bf_source`, the markup version `_bf_v`, the render token
 * `_bf_token`, the signed render time `_bf_t`, the instance `_bf_i` and the honeypot
 * `_bf_hp`. Only for a logged-in user, `_wpnonce` (a `wp_rest` nonce) comes too.
 * hidden_controls() is the single list of hidden control inputs.
 *
 * A guest's HTML holds no nonce and nothing about a user: two guests' renders at the
 * same time are byte-identical, and a logged-in user's differs by the nonce only.
 *
 * `_bf_i` is the 1-based position of this copy of the form on the page. The no-JS
 * handler reads it so the result is shown on the copy that was submitted; a page
 * cached without it counts as the first copy.
 */
class Form_Controls {

	/**
	 * Name of the honeypot input.
	 */
	const HONEYPOT = '_bf_hp';

	/**
	 * The hidden control inputs of a form, name => value.
	 *
	 * @param Form_Context $context The form being rendered.
	 * @return array<string, string>
	 */
	public static function hidden_controls( Form_Context $context ) {
		$controls = [
			'_bf_form'   => $context->form_id,
			'_bf_source' => $context->source,
			'_bf_v'      => BLOCKFORMS_VERSION,
			'_bf_token'  => Render_Token::issue( $context->form_id, $context->source, $context->schema->hash ),
			'_bf_t'      => Time_Trap::issue( $context->form_id, $context->source ),
			'_bf_i'      => (string) $context->instance,
		];

		if ( is_user_logged_in() ) {
			$controls['_wpnonce'] = wp_create_nonce( 'wp_rest' );
		}

		return $controls;
	}

	/**
	 * Markup of the control inputs.
	 *
	 * @param Form_Context $context The form being rendered.
	 * @return string
	 */
	public static function render( Form_Context $context ) {
		$html = '';
		foreach ( self::hidden_controls( $context ) as $name => $value ) {
			$html .= '<input type="hidden"' . Html::attrs(
				[
					'name'  => $name,
					'value' => $value,
				]
			) . '>';
		}

		// The honeypot: out of sight and out of the tab order for people and assistive technology, but a plain text input for bots.
		$html .= '<div class="blockforms-hp" aria-hidden="true"><label>' . esc_html__( 'Leave this field empty', 'blockforms' )
			. '<input type="text" name="' . esc_attr( self::HONEYPOT ) . '" value="" tabindex="-1" autocomplete="off"></label></div>';

		return $html;
	}
}
