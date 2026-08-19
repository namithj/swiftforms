<?php
/**
 * Select field type.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Fields\Types;

use SwiftForms\Fields\FieldType;
use SwiftForms\Fields\OptionParser;

final class SelectField {

	public static function define(): FieldType {
		return new FieldType(
			type: 'select',
			label: __( 'Select', 'swiftforms' ),
			attributes: array(
				'label'   => array(
					'type'    => 'string',
					'default' => __( 'Select', 'swiftforms' ),
				),
				'slug'    => array(
					'type'    => 'string',
					'default' => 'select_field',
				),
				'options' => array(
					'type'    => 'string',
					'default' => "Option 1|option_1\nOption 2|option_2",
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
