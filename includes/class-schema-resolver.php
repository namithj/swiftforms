<?php
/**
 * Schema resolver.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Schema_Resolver
 *
 * Builds a Form_Schema from stored content only (C-3.2, ADR-001): the request
 * contributes an effective formId and a source reference, nothing else.
 *
 * Rules:
 * - Fields are the field blocks inside the form, in document order, at any depth
 *   and inside any wrapper, including blocks this version does not know (C-2.8).
 *   A nested form block and the confirmation block are not walked for fields.
 * - Attributes are merged over the Field_Types defaults. Unknown attributes are
 *   ignored; a wrong-typed value falls back to its default.
 * - Names: a valid name stays. An invalid one (empty, uppercase, spaces, dots,
 *   longer than 64 characters, not starting with a letter) is DERIVED, never
 *   skipped, so every field block yields exactly one schema field and the n-th
 *   field block is fields[ n ]. Derivation: lowercase, every run of characters
 *   outside [a-z0-9_-] becomes "-", surrounding "-" and "_" are trimmed, a result
 *   that is empty or does not start with a letter gets the field type or "f-" in
 *   front, then it is cut to 64 characters. Then C-3.9: a name already taken
 *   earlier in the form becomes "{name}-2", "{name}-3" (the base is shortened so the
 *   result stays within 64 characters). Both repairs are listed in Form_Schema::$notices.
 * - Hidden fields keep their "value" attribute; P4 computes the real value at submit
 *   time from the source (C-3.10).
 */
class Schema_Resolver {

	/**
	 * Resolve the schema of the form with an effective ID in a source.
	 *
	 * @param string $effective_id Effective formId (C-2.7).
	 * @param string $ref          Source reference the form belongs to.
	 * @return Form_Schema|null Null when the ID or reference is not valid, the source cannot be loaded, or it has no form with that effective ID.
	 */
	public static function resolve( $effective_id, $ref ) {
		if ( ! Uuid::is_valid( $effective_id ) ) {
			return null;
		}
		$loaded = Source_Loader::load( $ref );
		if ( null === $loaded ) {
			return null;
		}

		foreach ( Form_Index::plan( $ref, Block_Walker::forms( $loaded['blocks'] ) ) as $entry ) {
			if ( $entry['effective'] === $effective_id ) {
				return self::build( $entry['block'], $entry['effective'], $entry['stored'], $ref );
			}
		}
		return null;
	}

	/**
	 * Build the schema of one form block.
	 *
	 * Also used by the renderer: it has the same block and gets the same final field names.
	 *
	 * @param array<string, mixed> $form_block Parsed swiftforms/form block.
	 * @param string $form_id    Effective formId.
	 * @param string $stored_id  formId as stored (empty when missing).
	 * @param string $ref        Source reference.
	 * @return Form_Schema
	 */
	public static function build( array $form_block, $form_id, $stored_id, $ref ) {
		$attributes = Field_Types::resolve_form( $form_block['attrs'] ?? [] );

		$blocks       = [];
		$confirmation = [];
		self::collect( isset( $form_block['innerBlocks'] ) && is_array( $form_block['innerBlocks'] ) ? $form_block['innerBlocks'] : [], $blocks, $confirmation );

		$fields  = [];
		$notices = [];
		$taken   = [];
		foreach ( $blocks as $index => $block ) {
			$type  = $block['type'];
			$field = array_merge( [ 'type' => $type ], Field_Types::resolve_field( $type, $block['attrs'] ) );

			if ( isset( $field['options'] ) ) {
				$field['options'] = self::normalise_options( $field['options'] );
			}

			$raw  = $field['name'];
			$name = Field_Types::is_valid_name( $raw ) ? $raw : self::derive_name( $raw, $type );
			if ( $name !== $raw ) {
				$notices[] = [
					'index' => $index,
					'code'  => 'name_derived',
					'from'  => $raw,
					'to'    => $name,
				];
			}

			$unique = self::unique_name( $name, $taken );
			if ( $unique !== $name ) {
				$notices[] = [
					'index' => $index,
					'code'  => 'name_duplicate',
					'from'  => $name,
					'to'    => $unique,
				];
			}
			$taken[ $unique ] = true;

			$field['name'] = $unique;
			$fields[]      = $field;
		}

		return new Form_Schema(
			[
				'form_id'             => $form_id,
				'stored_id'           => $stored_id,
				'source'              => $ref,
				'attributes'          => $attributes,
				'fields'              => $fields,
				'confirmation_blocks' => $confirmation,
				'notices'             => $notices,
			]
		);
	}

	/**
	 * Valid name derived from an invalid one.
	 *
	 * @param string $raw  The stored name (any string).
	 * @param string $type Field type, used when nothing usable is left.
	 * @return string A valid name.
	 */
	public static function derive_name( $raw, $type = 'field' ) {
		$name = strtolower( (string) $raw );
		$name = (string) preg_replace( '/[^a-z0-9_-]+/', '-', $name );
		$name = trim( $name, '-_' );

		if ( '' === $name ) {
			$name = Field_Types::is_type( $type ) ? $type : 'field';
		} elseif ( 1 !== preg_match( '/^[a-z]/', $name ) ) {
			$name = 'f-' . $name;
		}

		return substr( $name, 0, 64 );
	}

	/**
	 * Make a name unique among the names taken so far (C-3.9).
	 *
	 * @param string              $name  A valid name.
	 * @param array<string, bool> $taken Names already used in the form, as keys.
	 * @return string
	 */
	private static function unique_name( $name, array $taken ) {
		if ( ! isset( $taken[ $name ] ) ) {
			return $name;
		}
		for ( $n = 2; ; $n++ ) {
			$suffix    = '-' . $n;
			$candidate = substr( $name, 0, 64 - strlen( $suffix ) ) . $suffix;
			if ( ! isset( $taken[ $candidate ] ) ) {
				return $candidate;
			}
		}
	}

	/**
	 * Collect the field blocks (document order) and the confirmation's inner blocks of a form.
	 *
	 * @param array<int, array<string, mixed>> $blocks       Parsed blocks to walk.
	 * @param array<int, array{type:string, attrs:array<mixed>}> $fields Collected fields: type and stored attributes. Passed by reference.
	 * @param array<int, mixed> $confirmation Collected confirmation inner blocks. Passed by reference.
	 * @return void
	 */
	private static function collect( array $blocks, array &$fields, array &$confirmation ) {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$name = $block['blockName'] ?? null;

			if ( Field_Types::FORM_BLOCK === $name ) {
				continue; // A nested form is its own form.
			}
			if ( Field_Types::CONFIRMATION_BLOCK === $name ) {
				if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
					foreach ( $block['innerBlocks'] as $inner ) {
						$confirmation[] = $inner;
					}
				}
				continue;
			}

			$type = Field_Types::type_from_block_name( $name );
			if ( null !== $type ) {
				$fields[] = [
					'type'  => $type,
					'attrs' => isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [],
				];
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::collect( $block['innerBlocks'], $fields, $confirmation );
			}
		}
	}

	/**
	 * Clean a choice field's options into a list of { value, label } strings.
	 *
	 * An entry that is not an array, or has no usable value or label, is dropped. A missing
	 * value takes the label; a missing label takes the value.
	 *
	 * @param array<int|string, mixed> $options Stored options.
	 * @return array<int, array{value:string, label:string}>
	 */
	public static function normalise_options( array $options ) {
		$clean = [];
		foreach ( $options as $option ) {
			if ( is_string( $option ) || is_int( $option ) || is_float( $option ) ) {
				$option = [ 'value' => (string) $option ];
			}
			if ( ! is_array( $option ) ) {
				continue;
			}

			$value = isset( $option['value'] ) && ( is_string( $option['value'] ) || is_int( $option['value'] ) || is_float( $option['value'] ) ) ? (string) $option['value'] : '';
			$label = isset( $option['label'] ) && ( is_string( $option['label'] ) || is_int( $option['label'] ) || is_float( $option['label'] ) ) ? (string) $option['label'] : '';
			if ( '' === $value ) {
				$value = $label;
			}
			if ( '' === $label ) {
				$label = $value;
			}
			if ( '' === $value ) {
				continue;
			}

			$clean[] = [
				'value' => $value,
				'label' => $label,
			];
		}
		return $clean;
	}
}
