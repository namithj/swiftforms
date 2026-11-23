<?php
/**
 * Submit block renderer.
 *
 * @package BlockForms
 */

namespace BlockForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Submit_Renderer
 */
class Submit_Renderer {

	/**
	 * Render callback of blockforms/submit.
	 *
	 * The default label is stored as the English word (the stored markup must not depend on the
	 * locale), so that exact value is translated here; a label the author typed is printed as typed.
	 * A submit block outside a form renders nothing.
	 *
	 * @param array<string, mixed> $attributes Attributes prepared by the block builder (the stored ones are read).
	 * @param string               $content    Inner content (none).
	 * @param \WP_Block            $block      The block instance.
	 * @return string
	 */
	public static function render_block( $attributes, $content, $block ) {
		unset( $attributes, $content );

		if ( null === Form_Renderer::current_context() ) {
			return '';
		}

		$stored = isset( $block->parsed_block['attrs'] ) && is_array( $block->parsed_block['attrs'] ) ? $block->parsed_block['attrs'] : [];
		$label  = Field_Types::resolve( Field_Types::submit_attributes(), $stored )['label'];
		if ( 'Submit' === $label ) {
			$label = __( 'Submit', 'blockforms' );
		}

		return '<div ' . get_block_wrapper_attributes( [ 'class' => 'blockforms-submit' ] ) . '>'
			. '<button type="submit" class="blockforms-submit__button wp-element-button">' . esc_html( $label ) . '</button></div>';
	}
}
