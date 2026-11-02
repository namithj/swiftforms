<?php
/**
 * Email settings field.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

use SwiftForms\Vendor\PedalCMS\CassetteCMF\Field\Fields\Text_Field;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Settings_Email_Field
 *
 * A text field that holds one email address (config "single") or a comma-separated list. The stock email field
 * blanks an invalid address while sanitising, so the settings route would store "" and answer 200; this one keeps
 * the text and refuses it with a message, the way Settings::coerce() judges what is valid when reading.
 *
 * The React app draws it as a text field (get_type() says "text").
 */
class Settings_Email_Field extends Text_Field {

	/**
	 * Field type the page registers this class under.
	 */
	const TYPE = 'swiftforms_email';

	/**
	 * The type the settings app sees.
	 *
	 * @return string
	 */
	public function get_type(): string {
		return 'text';
	}

	/**
	 * Validate the address or the list.
	 *
	 * @param mixed $input Sanitised value.
	 * @return array{valid:bool,errors:array<int,string>}
	 */
	public function validate( $input ): array {
		$result = parent::validate( $input );
		if ( ! is_string( $input ) || '' === $input ) {
			return $result;
		}

		$single    = ! empty( $this->get_config( 'single' ) );
		$addresses = $single ? [ $input ] : explode( ',', $input );
		foreach ( $addresses as $address ) {
			$address = trim( $address );
			if ( '' === $address || sanitize_email( $address ) !== $address ) {
				$result['valid']    = false;
				$result['errors'][] = $single
					? __( 'Enter a valid email address, or leave it empty.', 'swiftforms' )
					: __( 'Enter valid email addresses separated by commas.', 'swiftforms' );
				break;
			}
		}
		return $result;
	}
}
