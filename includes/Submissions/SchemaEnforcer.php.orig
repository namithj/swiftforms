<?php
/**
 * Rebuilds the authoritative submission payload from the form's stored blocks.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Submissions;

use SwiftForms\Conditions;
use SwiftForms\Fields\FieldRegistry;
use SwiftForms\Fields\FormSchema;
use SwiftForms\PostTypes;
use WP_Error;

/**
 * Never trusts client-supplied field type/required/options/min/max: those
 * always come from `Fields\FormSchema::for_form()`. Unknown slugs are
 * dropped, hidden (conditionally invisible) fields are stripped before
 * validation ever sees them, and omitted required-visible fields are
 * injected as empty so Validator reports them consistently.
 */
final class SchemaEnforcer {

	public function __construct( private FieldRegistry $field_registry ) {
	}

	/**
	 * @param array<string, mixed> $request Normalized request (see Pipeline::normalize_request()).
	 * @return array{form_id: int, fields: array<int, array{slug: string, type: string, value: mixed, attributes: array<string, mixed>}>}|WP_Error
	 */
	public function enforce( array $request ) {
		$form_id = (int) ( $request['form_id'] ?? 0 );

		if ( $form_id <= 0 || PostTypes::FORM_POST_TYPE !== get_post_type( $form_id ) ) {
			return new WP_Error( 'invalid_form', __( 'This form no longer exists.', 'swiftforms' ), array( 'status' => 400 ) );
		}

		$schema = FormSchema::for_form( $form_id );

		$submitted = array();
		foreach ( (array) ( $request['fields'] ?? array() ) as $row ) {
			$slug = sanitize_key( (string) ( $row['slug'] ?? '' ) );

			if ( '' !== $slug ) {
				$submitted[ $slug ] = $row['value'] ?? '';
			}
		}

		$condition_values = array();
		foreach ( $schema as $slug => $field ) {
			$value                     = $submitted[ $slug ] ?? '';
			$condition_values[ $slug ] = is_array( $value ) ? '' : (string) $value;
		}

		$condition_fields = array_map(
			static fn( array $field ) => array( 'conditions' => $field['attributes']['conditions'] ?? array() ),
			$schema
		);

		$visibility = Conditions::resolve_visibility( $condition_fields, $condition_values );

		$fields = array();

		foreach ( $schema as $slug => $field ) {
			if ( empty( $visibility[ $slug ] ) ) {
				continue; // Conditionally hidden: never validated, stored, or emailed.
			}

			if ( ! $this->field_registry->has( $field['type'] ) ) {
				continue; // Field type no longer registered (e.g. an addon was deactivated).
			}

			$fields[] = array(
				'slug'       => $slug,
				'type'       => $field['type'],
				'value'      => $submitted[ $slug ] ?? '',
				'attributes' => $field['attributes'],
			);
		}

		return array(
			'form_id' => $form_id,
			'fields'  => $fields,
		);
	}
}
