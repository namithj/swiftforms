<?php
/**
 * Derives the authoritative field list for a form from its stored blocks.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Fields;

/**
 * The form post's `post_content` — not anything the client sends — is the
 * single source of truth for what fields exist, their types, and their
 * validation rules. Used by Submissions\SchemaEnforcer to rebuild the
 * authoritative payload before validation, and by the admin UI (e.g. the
 * notification placeholder helper) to list a form's slugs.
 */
final class FormSchema {

	private const FIELD_PREFIX = 'swf/field-';

	/**
	 * Slug => { type, attributes } for every field block in a form, in
	 * document order, walking nested blocks (steps, groups, columns).
	 *
	 * @return array<string, array{type: string, attributes: array<string, mixed>}>
	 */
	public static function for_form( int $form_id ): array {
		$post = get_post( $form_id );

		if ( ! $post ) {
			return array();
		}

		$fields = array();

		self::collect( parse_blocks( $post->post_content ), $fields );

		return $fields;
	}

	/**
	 * Recursively walks parsed blocks, collecting field blocks into $fields
	 * (by reference) keyed by slug — a later duplicate slug overwrites an
	 * earlier one, matching how post meta storage would collide anyway.
	 *
	 * @param array<int, array<string, mixed>>                          $blocks Parsed blocks.
	 * @param array<string, array{type: string, attributes: array<string, mixed>}> $fields Accumulator.
	 */
	private static function collect( array $blocks, array &$fields ): void {
		foreach ( $blocks as $block ) {
			$name = (string) ( $block['blockName'] ?? '' );

			if ( str_starts_with( $name, self::FIELD_PREFIX ) ) {
				$type = substr( $name, strlen( self::FIELD_PREFIX ) );
				$slug = sanitize_key( (string) ( $block['attrs']['slug'] ?? '' ) );

				if ( '' !== $slug ) {
					$fields[ $slug ] = array(
						'type'       => $type,
						'attributes' => (array) $block['attrs'],
					);
				}
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				self::collect( $block['innerBlocks'], $fields );
			}
		}
	}
}
