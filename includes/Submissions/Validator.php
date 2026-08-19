<?php
/**
 * Per-field validation, delegated to each type's FieldType::validate().
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Submissions;

use SwiftForms\Fields\FieldRegistry;

final class Validator {

	public function __construct( private FieldRegistry $field_registry ) {
	}

	/**
	 * @param array<int, array{slug: string, type: string, value: mixed, attributes: array<string, mixed>}> $fields Schema-enforced fields.
	 * @return array<string, string> Slug => error message, for any invalid field.
	 */
	public function validate( array $fields ): array {
		$errors = array();

		foreach ( $fields as $field ) {
			$field_type = $this->field_registry->get( $field['type'] );

			if ( ! $field_type ) {
				continue;
			}

			$error = $field_type->validate( $field['value'], $field['attributes'] );

			if ( null !== $error ) {
				$errors[ $field['slug'] ] = $error;
			}
		}

		return $errors;
	}
}
