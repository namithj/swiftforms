<?php
/**
 * Number field type.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Fields\Types;

use SwiftForms\Fields\FieldType;

final class NumberField {

	public static function define(): FieldType {
		return new FieldType(
			type: 'number',
			label: __( 'Number', 'swiftforms' ),
			attributes: array(
				'label'       => array(
					'type'    => 'string',
					'default' => __( 'Number', 'swiftforms' ),
				),
				'slug'        => array(
					'type'    => 'string',
					'default' => 'number_field',
				),
				'placeholder' => array(
					'type'    => 'string',
					'default' => '',
				),
				'min'         => array(
					'type'    => 'string',
					'default' => '',
				),
				'max'         => array(
					'type'    => 'string',
					'default' => '',
				),
				'step'        => array(
					'type'    => 'string',
					'default' => '1',
				),
			),
			validate: static function ( $value, array $attributes ): ?string {
				$value = trim( (string) $value );

				if ( '' === $value ) {
					return ! empty( $attributes['required'] ) ? __( 'This field is required.', 'swiftforms' ) : null;
				}

				if ( ! is_numeric( $value ) ) {
					return __( 'Please enter a valid number.', 'swiftforms' );
				}

				$number = (float) $value;

				if ( '' !== (string) ( $attributes['min'] ?? '' ) && $number < (float) $attributes['min'] ) {
					/* translators: %s: minimum allowed value. */
					return sprintf( __( 'Please enter a value greater than or equal to %s.', 'swiftforms' ), $attributes['min'] );
				}

				if ( '' !== (string) ( $attributes['max'] ?? '' ) && $number > (float) $attributes['max'] ) {
					/* translators: %s: maximum allowed value. */
					return sprintf( __( 'Please enter a value less than or equal to %s.', 'swiftforms' ), $attributes['max'] );
				}

				$step = '' !== (string) ( $attributes['step'] ?? '' ) ? (float) $attributes['step'] : 0.0;

				if ( $step > 0 ) {
					$base      = '' !== (string) ( $attributes['min'] ?? '' ) ? (float) $attributes['min'] : 0.0;
					$remainder = fmod( $number - $base, $step );

					if ( abs( $remainder ) > 1e-5 && abs( $remainder - $step ) > 1e-5 ) {
						return __( 'Please enter a valid value for this step.', 'swiftforms' );
					}
				}

				return null;
			}
		);
	}
}
