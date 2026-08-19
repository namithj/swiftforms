<?php
/**
 * Telephone field type.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Fields\Types;

use SwiftForms\Fields\FieldType;

final class TelField {

	public static function define(): FieldType {
		return new FieldType(
			type: 'tel',
			label: __( 'Phone', 'swiftforms' ),
			attributes: array(
				'label'       => array(
					'type'    => 'string',
					'default' => __( 'Phone', 'swiftforms' ),
				),
				'slug'        => array(
					'type'    => 'string',
					'default' => 'phone',
				),
				'placeholder' => array(
					'type'    => 'string',
					'default' => '+1 555 555 5555',
				),
			),
			validate: static function ( $value, array $attributes ): ?string {
				$value = trim( (string) $value );

				if ( '' === $value ) {
					return ! empty( $attributes['required'] ) ? __( 'This field is required.', 'swiftforms' ) : null;
				}

				return preg_match( '/^\+?[0-9\s().-]{6,20}$/', $value )
					? null
					: __( 'Please enter a valid phone number.', 'swiftforms' );
			}
		);
	}
}
