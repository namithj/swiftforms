<?php // phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Short fixture helpers BF_BLOCK() and friends used inside config files.
/**
 * Block-markup builders for the configs that generate their content (many forms, or forms in a loop).
 *
 * They write what the editor writes: attributes serialised by core's serialize_block_attributes() (the
 * function the editor's saved markup matches: unescaped slashes, the angle brackets, the ampersand, the
 * quote and double dashes as unicode escapes), self-closing field blocks, fields joined by one blank line,
 * the confirmation as the last inner block. Hand-written fixtures (fixtures/blocks.php) stay the reference
 * for the shape; these helpers only repeat it.
 *
 * @package BlockForms
 */

if ( ! function_exists( 'BF_BLOCK' ) ) {
	/**
	 * One block: self-closing without inner content, otherwise wrapped.
	 *
	 * @param string               $name  Block name, for example "blockforms/field-text" or "core/paragraph".
	 * @param array<string, mixed> $attrs Attributes in the order the editor writes them.
	 * @param string|null          $inner Inner markup, or null for a self-closing block.
	 * @return string
	 */
	function BF_BLOCK( $name, array $attrs = [], $inner = null ) {
		$json = $attrs ? ' ' . serialize_block_attributes( $attrs ) : '';
		if ( null === $inner ) {
			return '<!-- wp:' . $name . $json . ' /-->';
		}
		return '<!-- wp:' . $name . $json . ' -->' . "\n" . $inner . "\n" . '<!-- /wp:' . $name . ' -->';
	}

	/**
	 * A paragraph block.
	 *
	 * @param string $text Paragraph HTML.
	 * @return string
	 */
	function BF_PARAGRAPH( $text ) {
		return BF_BLOCK( 'paragraph', [], '<p>' . $text . '</p>' );
	}

	/**
	 * A heading block (level 2).
	 *
	 * @param string $text Heading text.
	 * @return string
	 */
	function BF_HEADING( $text ) {
		return BF_BLOCK( 'heading', [], '<h2 class="wp-block-heading">' . $text . '</h2>' );
	}

	/**
	 * A field block of the given type ("text", "email", ...).
	 *
	 * @param string               $type  Field type.
	 * @param array<string, mixed> $attrs Attributes.
	 * @return string
	 */
	function BF_FIELD( $type, array $attrs ) {
		return BF_BLOCK( 'blockforms/field-' . $type, $attrs );
	}

	/**
	 * A whole form block: the fields, a submit button and a confirmation message.
	 *
	 * @param array<string, mixed> $attrs        Form attributes (formId first).
	 * @param string[]             $fields       Field markup, in order.
	 * @param string               $submit_label Submit label, or an empty string for the default.
	 * @param string|null          $confirmation Confirmation paragraph text, or null for no confirmation block.
	 * @return string
	 */
	function BF_FORM( array $attrs, array $fields, $submit_label = '', $confirmation = 'Thank you.' ) {
		$children   = $fields;
		$children[] = BF_BLOCK( 'blockforms/submit', '' === $submit_label ? [] : [ 'label' => $submit_label ] );
		if ( null !== $confirmation ) {
			$children[] = BF_BLOCK( 'blockforms/confirmation', [], BF_PARAGRAPH( $confirmation ) );
		}
		return BF_BLOCK( 'blockforms/form', $attrs, implode( "\n\n", $children ) );
	}
}
