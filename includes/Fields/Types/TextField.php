<?php
/**
 * Text field type.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Fields\Types;

use SwiftForms\Fields\FieldType;

/**
 * A single-line free text input.
 */
final class TextField {

	public static function define(): FieldType {
		return new FieldType(
			type: 'text',
			label: __( 'Text', 'swiftforms' ),
			attributes: array(
				'label'       => array(
					'type'    => 'string',
					'default' => __( 'Text field', 'swiftforms' ),
				),
				'slug'        => array(
					'type'    => 'string',
					'default' => 'text_field',
				),
				'placeholder' => array(
					'type'    => 'string',
					'default' => '',
				),
			),
			validate: static function ( $value, array $attributes ): ?string {
				if ( ! empty( $attributes['required'] ) && '' === trim( (string) $value ) ) {
					return __( 'This field is required.', 'swiftforms' );
				}

				return null;
			}
		);
	}
}
