<?php
/**
 * Control fields of a rendered form.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Form_Controls
 *
 * The inputs every form carries besides its fields (C-3.1): the effective formId
 * `_sf_form`, the source `_sf_source`, the markup version `_sf_v`, the render token
 * `_sf_token`, the signed render time `_sf_t`, the instance `_sf_i` and the honeypot
 * `_sf_hp`. Only for a logged-in user, `_wpnonce` (a `wp_rest` nonce) comes too.
 * hidden_controls() is the single list of hidden control inputs.
 *
 * A guest's HTML holds no nonce and nothing about a user: two guests' renders at the
 * same time are byte-identical, and a logged-in user's differs by the nonce only.
 *
 * `_sf_i` is the 1-based position of this copy of the form on the page. The no-JS
 * handler reads it so the result is shown on the copy that was submitted; a page
 * cached without it counts as the first copy.
 */
class Form_Controls {

	/**
	 * Name of the honeypot input.
	 */
	const HONEYPOT = '_sf_hp';

	/**
	 * The hidden control inputs of a form, name => value.
	 *
	 * @param Form_Context $context The form being rendered.
	 * @return array<string, string>
	 */
	public static function hidden_controls( Form_Context $context ) {
		$controls = [
			'_sf_form'   => $context->form_id,
			'_sf_source' => $context->source,
			'_sf_v'      => SWIFTFORMS_VERSION,
			'_sf_token'  => Render_Token::issue( $context->form_id, $context->source, $context->schema->hash ),
			'_sf_t'      => Time_Trap::issue( $context->form_id, $context->source ),
			'_sf_i'      => (string) $context->instance,
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
		$html .= '<div class="swiftforms-hp" aria-hidden="true"><label>' . esc_html__( 'Leave this field empty', 'swiftforms' )
			. '<input type="text" name="' . esc_attr( self::HONEYPOT ) . '" value="" tabindex="-1" autocomplete="off"></label></div>';

		return $html;
	}
}
