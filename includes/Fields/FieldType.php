<?php
/**
 * Value object describing one field type.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Fields;

/**
 * Immutable description of a field type: its block attribute schema and the
 * validator the submission pipeline runs against a submitted value.
 * FieldRegistry is the only place these get constructed and stored, so it
 * stays the single source of truth for "what field types exist."
 */
final class FieldType {

	/**
	 * @param string               $type       Type key (matches the `smartlogix-swiftforms/field-{type}` block suffix).
	 * @param string               $label      Human-readable label shown in the inserter.
	 * @param array<string, mixed> $attributes Extra block attributes beyond the shared set (name => {type, default}).
	 * @param callable             $validate   ( mixed $value, array $attributes ): string|null — returns an error message, or null when valid.
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $label,
		public readonly array $attributes,
		public readonly mixed $validate
	) {
	}

	/**
	 * Runs this type's validator.
	 *
	 * @param mixed                $value      Submitted value.
	 * @param array<string, mixed> $attributes Field attributes from the form's stored blocks.
	 * @return string|null Error message, or null when the value is valid.
	 */
	public function validate( mixed $value, array $attributes ): ?string {
		return ( $this->validate )( $value, $attributes );
	}

	/**
	 * The full attribute schema for this type's block: the attributes every
	 * field shares, plus this type's own extras.
	 *
	 * @return array<string, mixed>
	 */
	public function full_attribute_schema(): array {
		return array_merge( self::shared_attributes(), $this->attributes );
	}

	/**
	 * Attributes every field block has, regardless of type.
	 *
	 * @return array<string, mixed>
	 */
	public static function shared_attributes(): array {
		return array(
			'label'      => array(
				'type'    => 'string',
				'default' => '',
			),
			'slug'       => array(
				'type'    => 'string',
				'default' => '',
			),
			'helpText'   => array(
				'type'    => 'string',
				'default' => '',
			),
			'required'   => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'conditions' => array(
				'type'    => 'object',
				'default' => array(
					'enabled' => false,
					'action'  => 'show',
					'groups'  => array(),
				),
			),
		);
	}
}
