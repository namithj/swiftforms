<?php
/**
 * Textarea field type.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Fields\Types;

use SwiftForms\Fields\FieldType;

final class TextareaField {

	public static function define(): FieldType {
		return new FieldType(
			type: 'textarea',
			label: __( 'Textarea', 'swiftforms' ),
			attributes: array(
				'label'       => array(
					'type'    => 'string',
					'default' => __( 'Message', 'swiftforms' ),
				),
				'slug'        => array(
					'type'    => 'string',
					'default' => 'message',
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
