<?php
/**
 * URL field type.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Fields\Types;

use SwiftForms\Fields\FieldType;

final class UrlField {

	public static function define(): FieldType {
		return new FieldType(
			type: 'url',
			label: __( 'Website', 'swiftforms' ),
			attributes: array(
				'label'       => array(
					'type'    => 'string',
					'default' => __( 'Website', 'swiftforms' ),
				),
				'slug'        => array(
					'type'    => 'string',
					'default' => 'website',
				),
				'placeholder' => array(
					'type'    => 'string',
					'default' => 'https://example.com',
				),
			),
			validate: static function ( $value, array $attributes ): ?string {
				$value = trim( (string) $value );

				if ( '' === $value ) {
					return ! empty( $attributes['required'] ) ? __( 'This field is required.', 'swiftforms' ) : null;
				}

				// Scheme check as well as shape: FILTER_VALIDATE_URL happily
				// accepts `javascript:`/`data:` URLs, which must never be
				// stored and later handed to a template or webhook consumer.
				$scheme = strtolower( (string) wp_parse_url( $value, PHP_URL_SCHEME ) );

				return false !== filter_var( $value, FILTER_VALIDATE_URL ) && in_array( $scheme, array( 'http', 'https' ), true )
					? null
					: __( 'Please enter a valid URL.', 'swiftforms' );
			}
		);
	}
}
