<?php
/**
 * Colour settings field.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

use SwiftForms\Vendor\PedalCMS\CassetteCMF\Field\Fields\Color_Field;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Settings_Color_Field
 *
 * A hex colour that may be empty (empty means "use the theme's accent"). The stock colour field replaces anything
 * it does not understand with its default while sanitising, so a typo would be saved as the default with a 200;
 * this one keeps the text and refuses it with a message.
 */
class Settings_Color_Field extends Color_Field {

	/**
	 * Field type the page registers this class under.
	 */
	const TYPE = 'swiftforms_color';

	/**
	 * The type the settings app sees.
	 *
	 * @return string
	 */
	public function get_type(): string {
		return 'color';
	}

	/**
	 * Keep a valid colour (a leading "#" is added when missing), keep anything else as typed so validate() can refuse it.
	 *
	 * @param mixed $input Raw value.
	 * @return mixed
	 */
	public function sanitize( $input ) {
		if ( ! is_string( $input ) ) {
			return '';
		}
		$input = trim( $input );
		if ( '' !== $input && null !== sanitize_hex_color( '#' . ltrim( $input, '#' ) ) ) {
			return '#' . ltrim( $input, '#' );
		}
		return sanitize_text_field( $input );
	}

	/**
	 * Validate: empty, or #rgb or #rrggbb.
	 *
	 * @param mixed $input Sanitised value.
	 * @return array{valid:bool,errors:array<int,string>}
	 */
	public function validate( $input ): array {
		$result = [
			'valid'  => true,
			'errors' => [],
		];
		if ( is_string( $input ) && '' !== $input && sanitize_hex_color( $input ) !== $input ) {
			$result['valid']    = false;
			$result['errors'][] = __( 'Enter a colour such as #1d4ed8, or leave it empty.', 'swiftforms' );
		}
		return $result;
	}
}
