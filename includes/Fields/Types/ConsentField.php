<?php
/**
 * GDPR-style consent field type.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Fields\Types;

use SwiftForms\Fields\FieldType;

/**
 * A required checkbox paired with a fixed statement. Entries\EntryRepository
 * stores the exact statement text and an acceptance timestamp alongside the
 * value, so what the visitor agreed to is preserved even if the form is
 * edited later.
 */
final class ConsentField {

	public static function define(): FieldType {
		return new FieldType(
			type: 'consent',
			label: __( 'Consent', 'swiftforms' ),
			attributes: array(
				'slug'          => array(
					'type'    => 'string',
					'default' => 'privacy_consent',
				),
				'statementText' => array(
					'type'    => 'string',
					'default' => __( 'I have read and agree to the Privacy Policy.', 'swiftforms' ),
				),
			),
			validate: static function ( $value, array $attributes ): ?string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- required to match FieldType's validate() callable shape.
				if ( '' === trim( (string) $value ) ) {
					return __( 'You must give consent to continue.', 'swiftforms' );
				}

				return null;
			}
		);
	}
}
