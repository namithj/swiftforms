<?php
/**
 * Radio group field type.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Fields\Types;

use SwiftForms\Fields\FieldType;
use SwiftForms\Fields\OptionParser;

final class RadioField {

	public static function define(): FieldType {
		return new FieldType(
			type: 'radio',
			label: __( 'Radio', 'swiftforms' ),
			attributes: array(
				'label'   => array(
					'type'    => 'string',
					'default' => __( 'Radio', 'swiftforms' ),
				),
				'slug'    => array(
					'type'    => 'string',
					'default' => 'radio_field',
				),
				'options' => array(
					'type'    => 'string',
					'default' => "Option one|option_one\nOption two|option_two",
				),
			),
			validate: static function ( $value, array $attributes ): ?string {
				$value = trim( (string) $value );

				if ( '' === $value ) {
					return ! empty( $attributes['required'] ) ? __( 'This field is required.', 'swiftforms' ) : null;
				}

				$allowed = OptionParser::values( (string) ( $attributes['options'] ?? '' ) );

				return in_array( $value, $allowed, true ) ? null : __( 'Please choose a valid option.', 'swiftforms' );
			}
		);
	}
}
