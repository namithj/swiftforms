<?php
/**
 * Star rating field type.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Fields\Types;

use SwiftForms\Fields\FieldType;

final class RatingField {

	public static function define(): FieldType {
		return new FieldType(
			type: 'rating',
			label: __( 'Rating', 'swiftforms' ),
			attributes: array(
				'label'     => array(
					'type'    => 'string',
					'default' => __( 'Rating', 'swiftforms' ),
				),
				'slug'      => array(
					'type'    => 'string',
					'default' => 'rating',
				),
				'maxRating' => array(
					'type'    => 'number',
					'default' => 5,
				),
			),
			validate: static function ( $value, array $attributes ): ?string {
				$value = trim( (string) $value );

				if ( '' === $value ) {
					return ! empty( $attributes['required'] ) ? __( 'This field is required.', 'swiftforms' ) : null;
				}

				$max = (int) ( $attributes['maxRating'] ?? 5 );

				if ( ! ctype_digit( $value ) || (int) $value < 1 || (int) $value > $max ) {
					return __( 'Please choose a valid rating.', 'swiftforms' );
				}

				return null;
			}
		);
	}
}
