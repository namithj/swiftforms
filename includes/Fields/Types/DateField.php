<?php
/**
 * Date field type.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Fields\Types;

use SwiftForms\Fields\FieldType;

final class DateField {

	public static function define(): FieldType {
		return new FieldType(
			type: 'date',
			label: __( 'Date', 'swiftforms' ),
			attributes: array(
				'label' => array(
					'type'    => 'string',
					'default' => __( 'Date', 'swiftforms' ),
				),
				'slug'  => array(
					'type'    => 'string',
					'default' => 'date_field',
				),
				'min'   => array(
					'type'    => 'string',
					'default' => '',
				),
				'max'   => array(
					'type'    => 'string',
					'default' => '',
				),
			),
			validate: static function ( $value, array $attributes ): ?string {
				$value = trim( (string) $value );

				if ( '' === $value ) {
					return ! empty( $attributes['required'] ) ? __( 'This field is required.', 'swiftforms' ) : null;
				}

				if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts ) ) {
					return __( 'Please enter a valid date (YYYY-MM-DD).', 'swiftforms' );
				}

				if ( ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) {
					return __( 'Please enter a valid date.', 'swiftforms' );
				}

				if ( ! empty( $attributes['min'] ) && $value < $attributes['min'] ) {
					/* translators: %s: earliest allowed date. */
					return sprintf( __( 'Please choose a date on or after %s.', 'swiftforms' ), $attributes['min'] );
				}

				if ( ! empty( $attributes['max'] ) && $value > $attributes['max'] ) {
					/* translators: %s: latest allowed date. */
					return sprintf( __( 'Please choose a date on or before %s.', 'swiftforms' ), $attributes['max'] );
				}

				return null;
			}
		);
	}
}
