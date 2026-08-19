<?php
/**
 * Single checkbox field type (consent-style yes/no, not a multi-checkbox group).
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Fields\Types;

use SwiftForms\Fields\FieldType;

final class CheckboxField {

	public static function define(): FieldType {
		return new FieldType(
			type: 'checkbox',
			label: __( 'Checkbox', 'swiftforms' ),
			attributes: array(
				'label'         => array(
					'type'    => 'string',
					'default' => __( 'Consent', 'swiftforms' ),
				),
				'slug'          => array(
					'type'    => 'string',
					'default' => 'consent',
				),
				'checkboxLabel' => array(
					'type'    => 'string',
					'default' => __( 'I agree to the terms.', 'swiftforms' ),
				),
				'value'         => array(
					'type'    => 'string',
					'default' => 'yes',
				),
			),
			validate: static function ( $value, array $attributes ): ?string {
				if ( ! empty( $attributes['required'] ) && '' === trim( (string) $value ) ) {
					return __( 'You must check this box to continue.', 'swiftforms' );
				}

				return null;
			}
		);
	}
}
