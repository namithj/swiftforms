<?php
/**
 * Confirmation block renderer.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Confirmation_Renderer
 *
 * The confirmation block renders nothing where it sits. When the form's Form_State says
 * "success", the form renderer prints the confirmation instead of the fields: the HTML
 * the submission handler prepared (merge tags applied), or the confirmation blocks of the
 * form, or the site's default success message.
 */
class Confirmation_Renderer {

	/**
	 * Render callback of swiftforms/confirmation: nothing on a normal render.
	 *
	 * @param array<string, mixed> $attributes Attributes (none).
	 * @param string               $content    Rendered inner blocks (discarded here).
	 * @param \WP_Block            $block      The block instance.
	 * @return string
	 */
	public static function render_block( $attributes, $content, $block ) {
		unset( $attributes, $content, $block );
		return '';
	}

	/**
	 * The confirmation of a form, wrapped for assistive technology.
	 *
	 * @param Form_Context         $context Form.
	 * @param array<string, mixed> $state   Form_State of the form.
	 * @return string
	 */
	public static function render( Form_Context $context, array $state ) {
		$html = '';

		if ( '' !== $state['confirmation_html'] ) {
			$html = $state['confirmation_html'];
		} else {
			foreach ( $context->schema->confirmation_blocks as $block ) {
				if ( is_array( $block ) ) {
					$html .= render_block( $block );
				}
			}
			if ( '' === trim( wp_strip_all_tags( $html, true ) ) && false === stripos( $html, '<img' ) ) {
				$html = '<p>' . wp_kses_post( (string) Settings::get_value( 'general.default_success_message', '' ) ) . '</p>';
			}
		}

		return '<div class="swiftforms-confirmation" role="status">' . $html . '</div>';
	}
}
