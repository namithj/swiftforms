<?php
/**
 * Email field type.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Fields\Types;

use SwiftForms\Fields\FieldType;

final class EmailField {

	public static function define(): FieldType {
		return new FieldType(
			type: 'email',
			label: __( 'Email', 'swiftforms' ),
			attributes: array(
				'label'       => array(
					'type'    => 'string',
					'default' => __( 'Email field', 'swiftforms' ),
				),
				'slug'        => array(
					'type'    => 'string',
					'default' => 'email',
				),
				'required'    => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'placeholder' => array(
					'type'    => 'string',
					'default' => '',
				),
			),
			validate: static function ( $value, array $attributes ): ?string {
				$value = trim( (string) $value );

				if ( '' === $value ) {
					return ! empty( $attributes['required'] ) ? __( 'This field is required.', 'swiftforms' ) : null;
				}

				return is_email( $value ) ? null : __( 'Please enter a valid email address.', 'swiftforms' );
			}
		);
	}
}
