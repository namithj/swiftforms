<?php
/**
 * Small HTML helpers for the renderers.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Html
 */
class Html {

	/**
	 * Attribute string for a map of attributes, every value escaped.
	 *
	 * A value of null or false leaves the attribute out; true prints it bare
	 * ("required"); anything else is printed as a string, including the empty
	 * string (value="").
	 *
	 * @param array<string, mixed> $attributes Attribute name => value.
	 * @return string With a leading space per attribute, or an empty string.
	 */
	public static function attrs( array $attributes ) {
		$html = '';
		foreach ( $attributes as $name => $value ) {
			if ( null === $value || false === $value ) {
				continue;
			}
			if ( true === $value ) {
				$html .= ' ' . $name;
				continue;
			}
			$html .= ' ' . $name . '="' . esc_attr( (string) $value ) . '"';
		}
		return $html;
	}

	/**
	 * Strip the leading space of an attribute string (get_block_wrapper_attributes() has none, attrs() does).
	 *
	 * @param string $attributes Attribute string.
	 * @return string
	 */
	public static function trim_attrs( $attributes ) {
		return ltrim( (string) $attributes );
	}
}
